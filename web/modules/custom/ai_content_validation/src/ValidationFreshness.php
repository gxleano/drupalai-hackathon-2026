<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Decides whether a stored score still describes an entity's content.
 *
 * Freshness must be measured the same way the run gate memoizes, or the
 * two disagree: the gate skips the model when the content hash matches,
 * so a revision-id comparison would then flag "content has changed" on
 * content the validator already judged. Both sides use the hash.
 *
 * Static, like ContentHasher: a pure comparison of stored values, called
 * from a views field plugin and from hooks alike.
 */
final class ValidationFreshness {

  /**
   * Whether a batched score record matches the entity's current content.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The node the score belongs to.
   * @param array{hash?: string|null, vid?: int, created?: int} $record
   *   One record from the batched score lookup.
   *
   * @return bool
   *   TRUE when the score is current. Records written before the hash
   *   existed fall back to the revision-and-timestamp comparison.
   */
  public static function isCurrent(FieldableEntityInterface $entity, array $record): bool {
    if (is_string($record['hash'] ?? NULL)) {
      return $record['hash'] === ContentHasher::hash($entity);
    }

    return ($record['vid'] ?? 0) === (int) $entity->getRevisionId()
      && (int) $entity->getChangedTime() <= ($record['created'] ?? 0);
  }

}
