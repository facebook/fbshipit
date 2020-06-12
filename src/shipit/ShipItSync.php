<?hh // strict
/**
 * Copyright (c) Facebook, Inc. and its affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * This file was moved from fbsource to www. View old history in diffusion:
 * https://fburl.com/j2zf8wd1
 */
namespace Facebook\ShipIt;

use namespace HH\Lib\{Str, Regex, C, Vec};

class ShipItException extends \Exception {}

final class ShipItImportDisallowedException extends ShipItException {}

final class ShipItSync {
  public function __construct(
    private ShipItBaseConfig $baseConfig,
    private ShipItSyncConfig $syncConfig,
  ) {
  }

  private function getFirstSourceID(): ?string {
    $config = $this->syncConfig;
    $rev = $config->getFirstCommit();
    if ($rev === null) {
      $src = $this->getRepo(ShipItSourceRepo::class);

      $rev = $src->findNextCommit(
        $this->findLastSyncedCommit(),
        $config->getSourceRoots(),
      );
    }
    return $rev;
  }

  private function getSourceChangesets(): vec<ShipItChangeset> {
    $config = $this->syncConfig;
    $src = $this->getRepo(ShipItSourceRepo::class);

    $changesets = vec[];
    $rev = $this->getFirstSourceID();
    while ($rev !== null) {
      $changeset = $src->getChangesetFromID($rev);

      if (!$changeset) {
        throw new ShipItException("Unable to get patch for $rev");
      }

      $changesets[] = $changeset;
      $rev = $src->findNextCommit($rev, $config->getSourceRoots());
    }
    return $changesets;
  }

  private function getFilteredChangesets(): vec<ShipItChangeset> {
    $base_config = $this->baseConfig;
    $skipped_ids = $this->syncConfig->getSkippedSourceCommits();
    $filter = $this->syncConfig->getFilter();

    $changesets = vec[];
    foreach ($this->getSourceChangesets() as $changeset) {
      $skip_match = null;
      foreach ($skipped_ids as $skip_id) {
        if (Str\search($changeset->getID(), $skip_id) === 0) {
          $skip_match = $skip_id;
          break;
        }
      }
      if ($skip_match !== null) {
        $changesets[] = $changeset
          ->withDiffs(vec[])
          ->withDebugMessage(
            'USER SKIPPED COMMIT: id "%s" matches "%s"',
            $changeset->getID(),
            $skip_match,
          );
        continue;
      }

      $changeset = $filter($base_config, $changeset);
      if (!$this->isValidChangeToSync($changeset)) {
        $changesets[] = $changeset->withDebugMessage(
          'SKIPPED COMMIT: no matching files',
        );
      } else {
        $changesets[] = self::addTrackingData($base_config, $changeset);
      }
    }
    return $changesets;
  }

  public function run(): void {
    $changesets = $this->getFilteredChangesets();
    if (C\is_empty($changesets)) {
      ShipItLogger::out("  No new commits to sync.\n");
      $this->maybeLogStats(vec[], vec[]);
      return;
    }

    $patches_dir = $this->syncConfig->getPatchesDirectory();
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    if ($patches_dir !== null && !\file_exists($patches_dir)) {
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      \mkdir($patches_dir, 0755, /* recursive = */ true);
    }

    $verbose = $this->baseConfig->isVerboseEnabled();
    $dest = $this->getRepo(ShipItDestinationRepo::class);

    $changesets = $this->syncConfig->postFilterChangesets($changesets, $dest);

    $changesets_applied = vec[];
    $changesets_skipped = vec[];
    foreach ($changesets as $changeset) {
      if ($patches_dir !== null) {
        $file = $patches_dir.
          '/'.
          $this->baseConfig->getDestinationBranch().
          '-'.
          $changeset->getID().
          '.patch';
        /* HH_IGNORE_ERROR[2049] __PHPStdLib */
        /* HH_IGNORE_ERROR[4107] __PHPStdLib */
        if (\file_exists($file)) {
          ShipItLogger::out("Overwriting patch file: %s\n", $file);
        }
        /* HH_IGNORE_ERROR[2049] __PHPStdLib */
        /* HH_IGNORE_ERROR[4107] __PHPStdLib */
        \file_put_contents($file, $dest::renderPatch($changeset));
        $changeset = $changeset->withDebugMessage(
          'Saved patch file: %s',
          $file,
        );
      }

      if ($verbose) {
        $changeset->dumpDebugMessages();
      }

      if (!$this->isValidChangeToSync($changeset)) {
        ShipItLogger::out(
          "  SKIP %s %s\n",
          $changeset->getShortID(),
          $changeset->getSubject(),
        );
        $changesets_skipped[] = $changeset;
        continue;
      }

      try {
        $dest->commitPatch($changeset);
        ShipItLogger::out(
          "  OK %s %s\n",
          $changeset->getShortID(),
          $changeset->getSubject(),
        );
        $changesets_applied[] = $changeset;
        continue;
      } catch (ShipItRepoException $e) {
        ShipItLogger::err(
          "Failed to apply patch %s (%s): %s\n",
          $changeset->getID(),
          $changeset->getMessage(),
          $e->getMessage(),
        );
        throw $e;
      }
    }

    $this->maybeLogStats($changesets_applied, $changesets_skipped);
  }

  /**
   * Optionally logs stats about the sync to the user-specified file.
   *
   * @param $changesets_applied the changesets that were applied.
   */
  private function maybeLogStats(
    vec<ShipItChangeset> $changesets_applied,
    vec<ShipItChangeset> $changesets_skipped,
  ): void {
    $filename = $this->syncConfig->getStatsFilename();
    if ($filename === null) {
      return;
    }
    $destination_branch = $this->baseConfig->getDestinationBranch();
    // Support logging stats for a project with multiple branches.
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    if (\is_dir($filename)) {
      // Slashes are allowed in branch names but not filenames.
      $namesafe_branch = Regex\replace(
        $destination_branch,
        re"/[^a-zA-Z0-9_\-.]/",
        '_',
      );
      $filename = $filename.'/'.$namesafe_branch.'.json';
    }
    $source_changeset = $this
      ->getRepo(ShipItSourceRepo::class)
      ->getHeadChangeset();
    $destination_changeset = $this
      ->getRepo(ShipItDestinationRepo::class)
      ->getHeadChangeset();
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    \file_put_contents(
      $filename,
      \json_encode(dict[
        'source' => dict[
          'id' => $source_changeset?->getID(),
          'timestamp' => $source_changeset?->getTimestamp(),
          'branch' => $this->baseConfig->getSourceBranch(),
        ],
        'destination' => dict[
          'id' => $destination_changeset?->getID(),
          'timestamp' => $destination_changeset?->getTimestamp(),
          'branch' => $destination_branch,
        ],
        'changesets' =>
          Vec\map($changesets_applied, $changeset ==> $changeset->getID()),
        'skipped' =>
          Vec\map($changesets_skipped, $changeset ==> $changeset->getID()),
      ]),
    );
  }

  /** Sync the change ONLY if:
  *  ChangeSet is not Empty OR
  *  ChangeSet isTaggedEmpty and the project allows empty commit.
  */
  private function isValidChangeToSync(ShipItChangeset $changeset): bool {
    $isEmpty = $changeset->isEmptyChange();
    $isTaggedEmptyCommit = $changeset->getIsTaggedEmptyCommit();
    return (
      !$isEmpty ||
      ($this->syncConfig->getAllowEmptyCommits() && $isTaggedEmptyCommit)
    );
  }

  private static function checkLastRev(?string $diff): string {
    if ($diff === null) {
      throw new ShipItException(
        "Unable to determine last differential revision pushed to dest repo",
      );
    }
    if (!Regex\matches($diff, re"/^D[0-9]{6,}$/")) {
      throw new ShipItException(
        "Last differential revision number ('{$diff}') is invalid",
      );
    }
    return $diff;
  }

  private static function checkFindDiff(?string $id, string $diff): string {
    if ($id === null) {
      throw new ShipItException("Unable to find $diff in source repo");
    }
    return $id;
  }

  <<__Memoize>>
  private function getRepo<Trepo as ShipItRepo>(
    classname<Trepo> $class,
  ): Trepo {
    $config = $this->baseConfig;

    if ($class === ShipItSourceRepo::class) {
      return ShipItRepo::typedOpen(
        $class,
        $config->getSourceSharedLock(),
        $config->getSourcePath(),
        $config->getSourceBranch(),
      );
    }

    if ($class === ShipItDestinationRepo::class) {
      return ShipItRepo::typedOpen(
        $class,
        $config->getDestinationSharedLock(),
        $config->getDestinationPath(),
        $config->getDestinationBranch(),
      );
    }

    invariant_violation(
      'Got class %s, expected %s or %s',
      $class,
      ShipItSourceRepo::class,
      ShipItDestinationRepo::class,
    );
  }

  private function findLastSyncedCommit(): string {
    $dest = $this->getRepo(ShipItDestinationRepo::class);

    $src_commit = $dest->findLastSourceCommit(
      $this->syncConfig->getDestinationRoots(),
    );
    if ($src_commit === null) {
      throw new ShipItException("Couldn't find synced commit id");
    }
    return $src_commit;
  }

  public static function addTrackingData(
    ShipItBaseConfig $config,
    ShipItChangeset $changeset,
    ?string $rev = null,
  ): ShipItChangeset {
    if ($rev === null) {
      $rev = $changeset->getID();
    }
    $new_message = Str\format(
      "%s\n\n%sshipit-source-id: %s",
      $changeset->getMessage(),
      $config->getCommitMarkerPrefix() ? 'fb' : '',
      $rev,
    );
    // Co-authored-by must be the absolute last thing in the message
    $co_author_lines = $changeset->getCoAuthorLines();
    if (Str\length($co_author_lines) > 0) {
      $new_message .= "\n\n".$co_author_lines;
    }
    return $changeset->withMessage(Str\trim($new_message));
  }
}
