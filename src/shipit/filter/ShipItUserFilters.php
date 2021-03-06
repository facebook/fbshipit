<?hh // strict
/**
 * Copyright (c) Facebook, Inc. and its affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * This file was moved from fbsource to www. View old history in diffusion:
 * https://fburl.com/cgcrhd9r
 */
namespace Facebook\ShipIt;

use namespace HH\Lib\{C, Str}; // @oss-enable

abstract final class ShipItUserFilters {
  /** Rewrite authors that match a certain pattern.
   *
   * @param $pattern a regular expression defining a 'user' named capture
   */
  public static async function genRewriteAuthorWithUserPattern(
    ShipItChangeset $changeset,
    classname<ShipItUserInfo> $user_info,
    string $pattern,
  ): Awaitable<ShipItChangeset> {
    $matches = dict[];
    if (
      PHP\preg_match($pattern, $changeset->getAuthor(), inout $matches) &&
      C\contains_key($matches as KeyedContainer<_, _>, 'user')
    ) {
      $author = await $user_info::genDestinationAuthorFromLocalUser(
        $matches['user'],
      );
      if ($author !== null) {
        return $changeset->withAuthor($author);
      }
    }
    return $changeset;
  }

  /** Rewrite author fields created by git-svn or HgSubversion.
   *
   * Original author: foobar@uuid
   * New author: Foo Bar <foobar@example.com>
   */
  public static async function genRewriteSVNAuthor(
    ShipItChangeset $changeset,
    classname<ShipItUserInfo> $user_info,
  ): Awaitable<ShipItChangeset> {
    return await self::genRewriteAuthorWithUserPattern(
      $changeset,
      $user_info,
      '/^(?<user>.*)@[a-f0-9-]{36}$/',
    );
  }

  public static function rewriteMentions(
    ShipItChangeset $changeset,
    classname<ShipItUserInfo> $user_info,
  ): ShipItChangeset {
    return ShipItMentions::rewriteMentions(
      $changeset,
      function(string $mention): string use ($user_info) {
        $mention = Str\slice($mention, 1); // chop off leading @
        // @oss-disable: $new = \Asio::awaitSynchronously(
          $new = \HH\Asio\join( // @oss-enable
          $user_info::genDestinationUserFromLocalUser($mention),
        );
        return '@'.($new ?? $mention);
      },
    );
  }

  /** Replace the author with a specially-formatted part of the commit
   * message.
   *
   * Useful for dealing with pull requests if there are restrictions on who
   * is a valid author for your internal repository.
   *
   * @param $pattern regexp pattern defining an 'author' capture group
   */
  public static function rewriteAuthorFromMessagePattern(
    ShipItChangeset $changeset,
    string $pattern,
  ): ShipItChangeset {
    $matches = dict[];
    if (PHP\preg_match($pattern, $changeset->getMessage(), inout $matches)) {
      return $changeset->withAuthor($matches['author']);
    }
    return $changeset;
  }

  /** Convenience wrapper for the above for 'GitHub Author: ' lines */
  public static function rewriteAuthorFromGitHubAuthorLine(
    ShipItChangeset $changeset,
  ): ShipItChangeset {
    return self::rewriteAuthorFromMessagePattern(
      $changeset,
      '/(^|\n)GitHub Author:\s*(?<author>.*?)(\n|$)/si',
    );
  }
}
