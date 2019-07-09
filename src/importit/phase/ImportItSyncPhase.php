<?hh // strict
/**
 * Copyright (c) Facebook, Inc. and its affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * This file was moved from fbsource to www. View old history in diffusion:
 * https://fburl.com/tvejazta
 */
namespace Facebook\ImportIt;

use namespace HH\Lib\Str;
use type Facebook\ShipIt\{
  ShipItBaseConfig,
  ShipItChangeset,
  ShipItDestinationRepo,
};

final class ImportItSyncPhase extends \Facebook\ShipIt\ShipItPhase {

  private ?string $expectedHeadRev;
  private ?string $patchesDirectory;
  private ?string $pullRequestNumber;
  private bool $skipPullRequest = false;
  private bool $applyToLatest = false;

  public function __construct(
    private (function(ShipItChangeset): ShipItChangeset) $filter,
  ) {
  }

  <<__Override>>
  protected function isProjectSpecific(): bool {
    return false;
  }

  <<__Override>>
  final public function getReadableName(): string {
    return 'Import Commits';
  }

  <<__Override>>
  final public function getCLIArguments(
  ): ImmVector<\Facebook\ShipIt\ShipItCLIArgument> {
    return ImmVector {
      shape(
        'long_name' => 'expected-head-revision::',
        'description' => 'The expected revision at the HEAD of the PR',
        'write' => $x ==> {
          $this->expectedHeadRev = $x;
          return $this->expectedHeadRev;
        },
      ),
      shape(
        'long_name' => 'pull-request-number::',
        'description' => 'The number of the Pull Request to import',
        'write' => $x ==> {
          $this->pullRequestNumber = $x;
          return $this->pullRequestNumber;
        },
      ),
      shape(
        'long_name' => 'save-patches-to::',
        'description' =>
          'Directory to copy created patches to. Useful for '.'debugging',
        'write' => $x ==> {
          $this->patchesDirectory = $x;
          return $this->patchesDirectory;
        },
      ),
      shape(
        'long_name' => 'skip-pull-request',
        'description' => 'Dont fetch a PR, instead just use the local '.
          'expected-head-revision',
        'write' => $_ ==> {
          $this->skipPullRequest = true;
          return $this->skipPullRequest;
        },
      ),
      shape(
        'long_name' => 'apply-to-latest',
        'description' => 'Apply the PR patch to the latest internal revision, '.
          'instead of on the internal commit that matches the '.
          'PR base.',
        'write' => $_ ==> {
          $this->applyToLatest = true;
          return $this->applyToLatest;
        },
      ),
    };
  }

  <<__Override>>
  final protected function runImpl(ShipItBaseConfig $config): void {
    list($changeset, $destination_base_rev) =
      $this->getSourceChangsetAndDestinationBaseRevision($config);
    $this->applyPatchToDestination($config, $changeset, $destination_base_rev);
  }

  private function getSourceChangsetAndDestinationBaseRevision(
    ShipItBaseConfig $config,
  ): (ShipItChangeset, ?string) {
    $pr_number = null;
    $expected_head_rev = $this->expectedHeadRev;
    if ($this->skipPullRequest) {
      invariant(
        $expected_head_rev !== null,
        '--expected-head-revision must be set!',
      );
    } else {
      $pr_number = $this->pullRequestNumber;
      invariant(
        $pr_number !== null && $expected_head_rev !== null,
        '--expected-head-revision must be set! '.
        'And either --pull-request-number or --skip-pull-request must be set',
      );
    }
    $source_repo = new ImportItRepoGIT(
      $config->getSourcePath(),
      $config->getSourceBranch(),
    );
    return $source_repo->getChangesetAndBaseRevisionForPullRequest(
      $pr_number,
      $expected_head_rev,
      $config->getSourceBranch(),
      $this->applyToLatest,
    );
  }

  private function applyPatchToDestination(
    ShipItBaseConfig $config,
    ShipItChangeset $changeset,
    ?string $base_rev,
  ): void {
    $destination_repo = ImportItRepo::open(
      $config->getDestinationPath(),
      $config->getDestinationBranch(),
    );
    if ($base_rev !== null) {
      echo "  Updating destination branch to new base revision...\n";
      $destination_repo->updateBranchTo($base_rev);
    }
    invariant(
      $destination_repo is ShipItDestinationRepo,
      'The destination repository must implement ShipItDestinationRepo!',
    );
    echo "  Filtering...\n";
    $filter_fn = $this->filter;
    $changeset = $filter_fn($changeset);
    if ($config->isVerboseEnabled()) {
      $changeset->dumpDebugMessages();
    }
    echo "  Exporting...\n";
    $this->maybeSavePatch($destination_repo, $changeset);
    try {
      $rev = $destination_repo->commitPatch($changeset);
      echo Str\format(
        "  Done.  %s committed in %s\n",
        $rev,
        $destination_repo->getPath(),
      );
    } catch (\Exception $e) {
      if ($this->patchesDirectory !== null) {
        echo Str\format(
          "  Failure to apply patch at %s\n",
          $this->getPatchLocationForChangeset($changeset),
        );
      } else {
        echo Str\format(
          "  Failure to apply patch:\n%s\n",
          $destination_repo::renderPatch($changeset),
        );
      }
      throw $e;
    }
  }

  private function maybeSavePatch(
    ShipItDestinationRepo $destination_repo,
    ShipItChangeset $changeset,
  ): void {
    $patchesDirectory = $this->patchesDirectory;
    if ($patchesDirectory === null) {
      return;
    }
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    if (!\file_exists($patchesDirectory)) {
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      \mkdir($patchesDirectory, 0755, /* recursive = */ true);
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    } else if (!\is_dir($patchesDirectory)) {
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      ShipItLogger::err(
        "Cannot log to %s: the path exists and is not a directory.\n",
        $patchesDirectory,
      );
      return;
    }
    $file = $this->getPatchLocationForChangeset($changeset);
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    \file_put_contents($file, $destination_repo::renderPatch($changeset));
    $changeset->withDebugMessage('Saved patch file: %s', $file);
  }

  private function getPatchLocationForChangeset(
    ShipItChangeset $changeset,
  ): string {
    return $this->patchesDirectory.'/'.$changeset->getID().'.patch';
  }
}
