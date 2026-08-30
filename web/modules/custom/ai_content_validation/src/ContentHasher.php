<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Hashes the content a validation verdict is based on.
 *
 * A verdict depends on the validated field values — the ones the validator
 * is shown — so the hash covers exactly the set ValidatedFields resolves
 * for the entity. Hashing only those lets a repeat run on unchanged
 * content reuse the stored score instead of asking the model again, and
 * correctly treats a new revision that only touched entity plumbing as
 * unchanged for validation purposes. Nothing volatile — timestamps,
 * revision ids, uid — may ever enter the hash: it would guarantee a
 * permanent cache miss and defeat the memoization entirely.
 *
 * Static by design: this is a pure function of the entity's own values
 * with no services behind it, so both the review form and the FlowDrop
 * normalize processor can call it without container wiring, and both are
 * guaranteed to compute the same digest.
 */
final class ContentHasher {

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
   *   A sha256 hex digest of the validated field values.
   */
  public static function hash(FieldableEntityInterface $entity): string {
    $values = [];
    foreach (array_keys(ValidatedFields::labels($entity)) as $field) {
      $values[] = self::fieldValue($entity, $field);
    }
    return hash('sha256', implode(self::SEPARATOR, $values));
  }

  /**
   * Hashes each validated field of an entity separately.
   *
   * The whole-entity hash answers "must this content be validated again";
   * these per-field digests answer the narrower question "which fields
   * changed", so a report can keep the verdicts of the fields nobody
   * touched instead of re-rolling them on every run.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The node (or node revision) to hash.
   *
   * @return array<string, string>
   *   Field machine name => sha256 digest of that field's value.
   */
  public static function fieldHashes(FieldableEntityInterface $entity): array {
    $hashes = [];
    foreach (array_keys(ValidatedFields::labels($entity)) as $field) {
      $hashes[$field] = hash('sha256', self::fieldValue($entity, $field));
    }
    return $hashes;
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
   *   A stable serialisation of every item's stored properties, or an
   *   empty string when the field is absent or empty. Reference fields
   *   have no "value" property, so the whole item list is encoded rather
   *   than one property.
   */
  private static function fieldValue(FieldableEntityInterface $entity, string $field): string {
    if (!$entity->hasField($field)) {
      return '';
    }
    $items = self::withoutEmptyItems($entity->get($field)->getValue());
    if ($items === []) {
      return '';
    }
    // A media reference's verdict also depends on the referenced image's
    // alt/title (the validator judges them), so they must enter the hash
    // — otherwise an alt-text fix would never invalidate the old verdict.
    $items = MediaReferenceDetails::enrich($entity, [$field => $items])[$field];
    return json_encode($items, JSON_UNESCAPED_SLASHES);
  }

  /**
   * Drops empty properties and the empty items they leave behind.
   *
   * A field's value depends on how the entity was loaded: building a node
   * form materialises an empty delta for an untouched field, so a plain
   * load yields `[]` where the form yields `[['value' => '']]`. Both mean
   * "no content", and the digest must not tell them apart — otherwise the
   * report written from one context reads as stale in the other.
   *
   * @param array<int, mixed> $items
   *   The raw field values.
   *
   * @return array<int, mixed>
   *   The values with empty items removed and their keys renumbered.
   */
  private static function withoutEmptyItems(array $items): array {
    $kept = [];
    foreach ($items as $item) {
      if (is_array($item)) {
        $item = array_filter($item, static fn ($value) => $value !== '' && $value !== NULL && $value !== []);
      }
      if ($item !== '' && $item !== NULL && $item !== []) {
        $kept[] = $item;
      }
    }

    return $kept;
  }

}
