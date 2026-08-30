<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\media\MediaInterface;

/**
 * Enriches serialized media references with their alt and title text.
 *
 * The FlowDrop entity serializer reduces a media reference to target_id
 * plus the media label (usually the filename), so the validator could
 * only ever judge an image by its filename. This helper appends the
 * source image's alt and title so accessibility text is assessed too.
 *
 * Static by design, like ValidatedFields: a pure function of the entity,
 * reachable from the payload node and the improve-gate serializer without
 * container wiring. The media entities are read through the node's own
 * reference items, so no storage lookups happen here.
 */
final class MediaReferenceDetails {

  /**
   * Appends target_alt / target_title to serialized media reference items.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The node the serialized fields belong to.
   * @param array<string, mixed> $fields
   *   The serialized fields, as the FlowDrop entity serializer emits them.
   *
   * @return array<string, mixed>
   *   The fields with target_alt / target_title added to every media
   *   reference item whose source field carries those properties.
   */
  public static function enrich(FieldableEntityInterface $entity, array $fields): array {
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() !== 'entity_reference'
        || $definition->getSetting('target_type') !== 'media'
        || !is_array($fields[$name] ?? NULL)) {
        continue;
      }
      $media_by_id = [];
      foreach ($entity->get($name)->referencedEntities() as $media) {
        if ($media instanceof MediaInterface) {
          $media_by_id[(int) $media->id()] = $media;
        }
      }
      foreach ($fields[$name] as $delta => $item) {
        $media = is_array($item) && isset($item['target_id']) ? ($media_by_id[(int) $item['target_id']] ?? NULL) : NULL;
        if ($media === NULL) {
          continue;
        }
        $source_field = $media->getSource()->getConfiguration()['source_field'] ?? '';
        if (!is_string($source_field) || $source_field === '' || !$media->hasField($source_field)) {
          continue;
        }
        $value = $media->get($source_field)->first()?->getValue() ?? [];
        // Only image-style sources carry alt/title; a document or remote
        // video media item simply keeps its label.
        if (array_key_exists('alt', $value)) {
          $fields[$name][$delta]['target_alt'] = (string) ($value['alt'] ?? '');
        }
        if (array_key_exists('title', $value)) {
          $fields[$name][$delta]['target_title'] = (string) ($value['title'] ?? '');
        }
      }
    }
    return $fields;
  }

}
