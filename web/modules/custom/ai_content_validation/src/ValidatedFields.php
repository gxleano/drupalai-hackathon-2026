<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\field\FieldConfigInterface;

/**
 * Resolves which fields of a node the AI validation covers.
 *
 * The set is derived from the bundle's own field definitions rather than
 * hardcoded, so a field added to a content type later is validated without
 * touching this module: every configured (non-base) field counts, plus the
 * node title. The validator prompt applies the same rule to the payload it
 * receives, so both sides agree without a list being kept in two places.
 *
 * Fixability is narrower than validation. "Fix with AI" replaces the whole
 * field value with model-authored text, which is only safe for the text
 * field types below — a reference field would need real target ids the
 * model cannot invent, so such a field gets a verdict but no fix action.
 *
 * Static by design: a pure function of the entity's field definitions with
 * no services behind it, so the hasher, the form alter and the review form
 * all resolve the same set without container wiring.
 */
final class ValidatedFields {

  /**
   * Field types whose value the improve workflow may rewrite wholesale.
   *
   * @var string[]
   */
  private const FIXABLE_TYPES = [
    'string',
    'string_long',
    'text',
    'text_long',
    'text_with_summary',
    'metatag',
  ];

  /**
   * Returns the validated fields of an entity, keyed by machine name.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The node (or node revision) being validated.
   *
   * @return array<string, string>
   *   Field machine name => human label, title first.
   */
  public static function labels(FieldableEntityInterface $entity): array {
    $labels = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      // Base fields are the entity's plumbing (uid, status, revision
      // metadata); only the title is content an editor writes. Everything
      // else that counts is a configured bundle field.
      if ($name === 'title' || $definition instanceof FieldConfigInterface) {
        $labels[$name] = (string) $definition->getLabel();
      }
    }
    return $labels;
  }

  /**
   * Returns the validated fields the improve workflow may rewrite.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The node being validated.
   *
   * @return array<string, string>
   *   Field machine name => human label, a subset of ::labels().
   */
  public static function fixableLabels(FieldableEntityInterface $entity): array {
    return array_filter(
      self::labels($entity),
      static fn (string $label, string $field) => self::isFixable($entity, $field),
      ARRAY_FILTER_USE_BOTH,
    );
  }

  /**
   * Whether "Fix with AI" may be offered for one field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The node being validated.
   * @param string $field
   *   The field machine name.
   *
   * @return bool
   *   TRUE when the improve workflow can safely replace the value.
   */
  public static function isFixable(FieldableEntityInterface $entity, string $field): bool {
    if (!$entity->hasField($field)) {
      return FALSE;
    }
    return in_array($entity->getFieldDefinition($field)->getType(), self::FIXABLE_TYPES, TRUE);
  }

}
