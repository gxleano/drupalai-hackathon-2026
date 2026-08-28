<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Hashes the content a validation verdict is based on.
 *
 * A verdict depends on exactly three field values: the ones the validator
 * is shown and the improver is allowed to rewrite. Hashing only those lets
 * a repeat run on unchanged content reuse the stored score instead of
 * asking the model again, and correctly treats a new revision that only
 * touched other fields (tags, for example) as unchanged for validation
 * purposes. Nothing volatile — timestamps, revision ids, uid — may ever
 * enter the hash: it would guarantee a permanent cache miss and defeat the
 * memoization entirely.
 *
 * Static by design: this is a pure function of three strings with no
 * services behind it, so both the review form and the FlowDrop normalize
 * processor can call it without container wiring, and both are guaranteed
 * to compute the same digest.
 */
final class ContentHasher {

  /**
   * The hashed field names, in hash order.
   *
   * @var string[]
   */
  private const FIELDS = ['title', 'field_body', 'field_metatags'];

  /**
   * Separator placed between the hashed values.
   *
   * A null byte cannot occur inside a stored text field value, so moving
   * text from one field into another always changes the digest.
   */
  private const SEPARATOR = "\0";

  /**
   * Hashes the validated field values of a node or node revision.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The node (or a specific node revision) to hash.
   *
   * @return string
   *   A sha256 hex digest of the three validated field values.
   */
  public static function hash(FieldableEntityInterface $entity): string {
    $values = [];
    foreach (self::FIELDS as $field) {
      $values[] = self::fieldValue($entity, $field);
    }
    return self::hashValues($values);
  }

  /**
   * Hashes already extracted field values.
   *
   * @param array<int, string> $values
   *   The title, field_body and field_metatags values, in that order.
   *
   * @return string
   *   A sha256 hex digest.
   */
  public static function hashValues(array $values): string {
    return hash('sha256', implode(self::SEPARATOR, $values));
  }

  /**
   * Reads one hashed field value as a plain string.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to read from.
   * @param string $field
   *   The field name.
   *
   * @return string
   *   The field's main property value, or an empty string when the field
   *   is absent or empty.
   */
  private static function fieldValue(FieldableEntityInterface $entity, string $field): string {
    if (!$entity->hasField($field)) {
      return '';
    }
    $value = $entity->get($field)->first()?->getValue() ?? [];
    return is_scalar($value['value'] ?? NULL) ? (string) $value['value'] : '';
  }

}
