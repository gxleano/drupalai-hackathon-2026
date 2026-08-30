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
 * The one exception is a taxonomy reference with auto_create (tags): the
 * model suggests term NAMES, which resolve to existing terms or create
 * new ones exactly as the tags widget would — no ids are ever invented.
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
    if (in_array($entity->getFieldDefinition($field)->getType(), self::FIXABLE_TYPES, TRUE)) {
      return TRUE;
    }
    return self::isTagsField($entity, $field) || self::isMediaField($entity, $field);
  }

  /**
   * Whether a field is a media reference whose fix rewrites the alt text.
   *
   * The "Fix with AI" for a media field never touches the file: the
   * suggestion is the new alt text, written onto the referenced media
   * entity's source field. Note the media entity is shared — the fixed
   * alt applies everywhere that media item is used.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The node being validated.
   * @param string $field
   *   The field machine name.
   *
   * @return bool
   *   TRUE for an entity_reference to media.
   */
  public static function isMediaField(FieldableEntityInterface $entity, string $field): bool {
    if (!$entity->hasField($field)) {
      return FALSE;
    }
    $definition = $entity->getFieldDefinition($field);
    return $definition->getType() === 'entity_reference' && $definition->getSetting('target_type') === 'media';
  }

  /**
   * Whether a field is a tags-style taxonomy reference the AI may rewrite.
   *
   * Safe to fix because the suggestion carries term names, and the field's
   * own auto_create setting already licenses creating terms from names.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The node being validated.
   * @param string $field
   *   The field machine name.
   *
   * @return bool
   *   TRUE for an entity_reference to taxonomy terms with auto_create.
   */
  public static function isTagsField(FieldableEntityInterface $entity, string $field): bool {
    if (!$entity->hasField($field)) {
      return FALSE;
    }
    $definition = $entity->getFieldDefinition($field);
    if ($definition->getType() !== 'entity_reference' || $definition->getSetting('target_type') !== 'taxonomy_term') {
      return FALSE;
    }
    $settings = $definition->getSetting('handler_settings') ?? [];
    return !empty($settings['auto_create']);
  }

  /**
   * Parses a suggested tags value into a clean list of term names.
   *
   * Accepts the comma-separated form the improve prompt asks for, and
   * tolerates a JSON array of strings should the model emit one anyway.
   *
   * @param string $value
   *   The suggested value.
   *
   * @return list<string>
   *   Trimmed, de-duplicated (case-insensitive) term names.
   */
  public static function parseTagNames(string $value): array {
    $trimmed = trim($value);
    if ($trimmed === '') {
      return [];
    }
    $names = NULL;
    if (str_starts_with($trimmed, '[')) {
      $decoded = json_decode($trimmed, TRUE);
      if (is_array($decoded)) {
        $names = array_filter($decoded, 'is_scalar');
      }
    }
    $names ??= preg_split('/[,;\n]+/', $trimmed) ?: [];
    $unique = [];
    foreach ($names as $name) {
      $name = trim((string) $name, " \t\"'");
      $key = mb_strtolower($name);
      if ($name !== '' && !isset($unique[$key])) {
        $unique[$key] = $name;
      }
    }
    return array_values($unique);
  }

}
