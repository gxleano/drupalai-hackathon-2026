<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_validation\Unit;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_content_validation\MediaReferenceDetails;
use Drupal\field\FieldConfigInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests enrichment of serialized media references with alt/title text.
 */
#[CoversClass(MediaReferenceDetails::class)]
#[Group('ai_content_validation')]
final class MediaReferenceDetailsTest extends UnitTestCase {

  /**
   * Builds a media entity mock with a source field value.
   *
   * @param int $id
   *   The media id.
   * @param string $source_field
   *   The source field machine name.
   * @param array<string, mixed>|null $value
   *   The source field's first item value, or NULL for an empty field.
   *
   * @return \Drupal\media\MediaInterface
   *   The mocked media entity.
   */
  private function media(int $id, string $source_field, ?array $value): MediaInterface {
    $source = $this->createMock(MediaSourceInterface::class);
    $source->method('getConfiguration')->willReturn(['source_field' => $source_field]);

    $item = NULL;
    if ($value !== NULL) {
      $item = $this->createMock(FieldItemInterface::class);
      $item->method('getValue')->willReturn($value);
    }
    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('first')->willReturn($item);

    $media = $this->createMock(MediaInterface::class);
    $media->method('id')->willReturn((string) $id);
    $media->method('getSource')->willReturn($source);
    $media->method('hasField')->willReturnCallback(
      static fn (string $name): bool => $name === $source_field,
    );
    $media->method('get')->willReturn($list);
    return $media;
  }

  /**
   * Builds a node mock with one media reference field.
   *
   * @param list<\Drupal\media\MediaInterface> $referenced
   *   The referenced media entities.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The mocked node.
   */
  private function nodeWithMediaField(array $referenced): FieldableEntityInterface {
    $definition = $this->createMock(FieldConfigInterface::class);
    $definition->method('getType')->willReturn('entity_reference');
    $definition->method('getSetting')->willReturnCallback(
      static fn (string $key) => $key === 'target_type' ? 'media' : NULL,
    );

    $list = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $list->method('referencedEntities')->willReturn($referenced);

    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('getFieldDefinitions')->willReturn(['field_image' => $definition]);
    $entity->method('get')->willReturn($list);
    return $entity;
  }

  /**
   * Tests that alt and title from the image source reach the item.
   */
  public function testAltAndTitleAreAppended(): void {
    $entity = $this->nodeWithMediaField([
      $this->media(5, 'field_media_image', ['alt' => 'A red bicycle', 'title' => 'Bike']),
    ]);
    $fields = MediaReferenceDetails::enrich($entity, [
      'field_image' => [['target_id' => 5, 'target_label' => 'bike.jpg']],
    ]);
    $this->assertSame(
      [
        'target_id' => 5,
        'target_label' => 'bike.jpg',
        'target_alt' => 'A red bicycle',
        'target_title' => 'Bike',
      ],
      $fields['field_image'][0],
    );
  }

  /**
   * Tests that non-media fields pass through untouched.
   */
  public function testNonMediaFieldsUntouched(): void {
    $definition = $this->createMock(FieldConfigInterface::class);
    $definition->method('getType')->willReturn('text_with_summary');
    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('getFieldDefinitions')->willReturn(['field_body' => $definition]);

    $fields = ['field_body' => [['value' => 'text']]];
    $this->assertSame($fields, MediaReferenceDetails::enrich($entity, $fields));
  }

  /**
   * Tests that an item referencing a missing media entity is skipped.
   */
  public function testMissingMediaIsSkipped(): void {
    $entity = $this->nodeWithMediaField([]);
    $fields = ['field_image' => [['target_id' => 99, 'target_label' => 'gone.jpg']]];
    $this->assertSame($fields, MediaReferenceDetails::enrich($entity, $fields));
  }

  /**
   * Tests that a source without alt/title properties adds nothing.
   *
   * A document media item's file source carries no alt or title, so its
   * serialized item keeps only the label.
   */
  public function testNonImageSourceAddsNothing(): void {
    $entity = $this->nodeWithMediaField([
      $this->media(7, 'field_media_document', ['target_id' => 12]),
    ]);
    $fields = ['field_image' => [['target_id' => 7, 'target_label' => 'report.pdf']]];
    $this->assertSame($fields, MediaReferenceDetails::enrich($entity, $fields));
  }

  /**
   * Tests that an empty alt string is still appended as empty.
   *
   * An empty alt is exactly what the validator must see to flag it.
   */
  public function testEmptyAltIsAppendedAsEmptyString(): void {
    $entity = $this->nodeWithMediaField([
      $this->media(5, 'field_media_image', ['alt' => NULL, 'title' => NULL]),
    ]);
    $fields = MediaReferenceDetails::enrich($entity, [
      'field_image' => [['target_id' => 5]],
    ]);
    $this->assertSame('', $fields['field_image'][0]['target_alt']);
    $this->assertSame('', $fields['field_image'][0]['target_title']);
  }

}
