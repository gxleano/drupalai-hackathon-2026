<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_validation\Unit;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_content_validation\ContentHasher;
use Drupal\field\FieldConfigInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the content hasher used for validation memoization.
 */
#[CoversClass(ContentHasher::class)]
#[Group('ai_content_validation')]
final class ContentHasherTest extends UnitTestCase {

  /**
   * Builds an entity mock with a title and one body field.
   *
   * @param array<int, mixed> $title_items
   *   Raw field values for the title.
   * @param array<int, mixed> $body_items
   *   Raw field values for the body.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The mocked entity.
   */
  private function entity(array $title_items, array $body_items): FieldableEntityInterface {
    $title_definition = $this->createMock(FieldConfigInterface::class);
    $title_definition->method('getLabel')->willReturn('Title');
    $title_definition->method('getType')->willReturn('string');

    $body_definition = $this->createMock(FieldConfigInterface::class);
    $body_definition->method('getLabel')->willReturn('Body');
    $body_definition->method('getType')->willReturn('text_with_summary');

    $values = [
      'title' => $title_items,
      'field_body' => $body_items,
    ];
    $lists = [];
    foreach ($values as $name => $items) {
      $list = $this->createMock(FieldItemListInterface::class);
      $list->method('getValue')->willReturn($items);
      $lists[$name] = $list;
    }

    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('getFieldDefinitions')->willReturn([
      'title' => $title_definition,
      'field_body' => $body_definition,
    ]);
    $entity->method('hasField')->willReturnCallback(
      static fn (string $name): bool => isset($values[$name]),
    );
    $entity->method('get')->willReturnCallback(
      static fn (string $name) => $lists[$name],
    );
    return $entity;
  }

  /**
   * Tests that identical values produce an identical digest.
   */
  public function testIdenticalValuesHashIdentically(): void {
    $a = $this->entity([['value' => 'Hello']], [['value' => 'Body text', 'format' => 'basic_html']]);
    $b = $this->entity([['value' => 'Hello']], [['value' => 'Body text', 'format' => 'basic_html']]);
    $this->assertSame(ContentHasher::hash($a), ContentHasher::hash($b));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', ContentHasher::hash($a));
  }

  /**
   * Tests that a changed value changes the digest.
   */
  public function testChangedValueChangesHash(): void {
    $a = $this->entity([['value' => 'Hello']], [['value' => 'Body text']]);
    $b = $this->entity([['value' => 'Hello']], [['value' => 'Other text']]);
    $this->assertNotSame(ContentHasher::hash($a), ContentHasher::hash($b));
  }

  /**
   * Tests that an empty form item hashes the same as no items at all.
   *
   * A node form materialises an empty delta for an untouched field, so
   * `[['value' => '']]` and `[]` both mean "no content" and must not be
   * told apart by the digest.
   */
  public function testEmptyItemNormalization(): void {
    $loaded = $this->entity([['value' => 'Hello']], []);
    $form = $this->entity([['value' => 'Hello']], [['value' => '']]);
    $this->assertSame(ContentHasher::hash($loaded), ContentHasher::hash($form));
  }

  /**
   * Tests that moving text between fields changes the digest.
   */
  public function testMovingTextBetweenFieldsChangesHash(): void {
    $a = $this->entity([['value' => 'Hello']], []);
    $b = $this->entity([], [['value' => 'Hello']]);
    $this->assertNotSame(ContentHasher::hash($a), ContentHasher::hash($b));
  }

  /**
   * Tests per-field digests: only the touched field's digest changes.
   */
  public function testFieldHashesTrackChangesPerField(): void {
    $a = ContentHasher::fieldHashes($this->entity([['value' => 'Hello']], [['value' => 'Body']]));
    $b = ContentHasher::fieldHashes($this->entity([['value' => 'Hello']], [['value' => 'Changed']]));
    $this->assertSame(['title', 'field_body'], array_keys($a));
    $this->assertSame($a['title'], $b['title']);
    $this->assertNotSame($a['field_body'], $b['field_body']);
  }

}
