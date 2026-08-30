<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_validation\Unit;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_content_validation\ValidatedFields;
use Drupal\field\FieldConfigInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the validated-fields resolver.
 */
#[CoversClass(ValidatedFields::class)]
#[Group('ai_content_validation')]
final class ValidatedFieldsTest extends UnitTestCase {

  /**
   * Builds a base field definition mock (not a configured bundle field).
   *
   * @param string $label
   *   The field label.
   * @param string $type
   *   The field type.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface
   *   The mocked definition.
   */
  private function baseDefinition(string $label, string $type = 'string'): FieldDefinitionInterface {
    $definition = $this->createMock(FieldDefinitionInterface::class);
    $definition->method('getLabel')->willReturn($label);
    $definition->method('getType')->willReturn($type);
    return $definition;
  }

  /**
   * Builds a configured (bundle) field definition mock.
   *
   * @param string $label
   *   The field label.
   * @param string $type
   *   The field type.
   * @param array<string, mixed> $settings
   *   Field settings returned by getSetting().
   *
   * @return \Drupal\field\FieldConfigInterface
   *   The mocked definition.
   */
  private function configDefinition(string $label, string $type, array $settings = []): FieldConfigInterface {
    $definition = $this->createMock(FieldConfigInterface::class);
    $definition->method('getLabel')->willReturn($label);
    $definition->method('getType')->willReturn($type);
    $definition->method('getSetting')->willReturnCallback(
      static fn (string $key) => $settings[$key] ?? NULL,
    );
    return $definition;
  }

  /**
   * Builds an entity mock exposing the given field definitions.
   *
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $definitions
   *   Definitions keyed by field name.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The mocked entity.
   */
  private function entity(array $definitions): FieldableEntityInterface {
    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('getFieldDefinitions')->willReturn($definitions);
    $entity->method('hasField')->willReturnCallback(
      static fn (string $name): bool => isset($definitions[$name]),
    );
    $entity->method('getFieldDefinition')->willReturnCallback(
      static fn (string $name) => $definitions[$name] ?? NULL,
    );
    return $entity;
  }

  /**
   * Tests that labels() keeps the title and configured fields only.
   */
  public function testLabelsSkipsBaseFieldsExceptTitle(): void {
    $entity = $this->entity([
      'title' => $this->baseDefinition('Title'),
      'uid' => $this->baseDefinition('Authored by'),
      'status' => $this->baseDefinition('Published', 'boolean'),
      'field_body' => $this->configDefinition('Body', 'text_with_summary'),
    ]);
    $this->assertSame(
      ['title' => 'Title', 'field_body' => 'Body'],
      ValidatedFields::labels($entity),
    );
  }

  /**
   * Tests that fixableLabels() drops plain reference fields.
   */
  public function testFixableLabelsExcludesPlainReferences(): void {
    $entity = $this->entity([
      'title' => $this->baseDefinition('Title'),
      'field_body' => $this->configDefinition('Body', 'text_with_summary'),
      'field_related' => $this->configDefinition('Related', 'entity_reference', [
        'target_type' => 'node',
      ]),
    ]);
    $this->assertSame(
      ['title' => 'Title', 'field_body' => 'Body'],
      ValidatedFields::fixableLabels($entity),
    );
  }

  /**
   * Tests that a tags-style reference with auto_create is fixable.
   */
  public function testTagsFieldWithAutoCreateIsFixable(): void {
    $entity = $this->entity([
      'field_tags' => $this->configDefinition('Tags', 'entity_reference', [
        'target_type' => 'taxonomy_term',
        'handler_settings' => ['auto_create' => TRUE],
      ]),
    ]);
    $this->assertTrue(ValidatedFields::isTagsField($entity, 'field_tags'));
    $this->assertTrue(ValidatedFields::isFixable($entity, 'field_tags'));
  }

  /**
   * Tests that a taxonomy reference without auto_create is not a tags field.
   */
  public function testTaxonomyReferenceWithoutAutoCreateIsNotTags(): void {
    $entity = $this->entity([
      'field_category' => $this->configDefinition('Category', 'entity_reference', [
        'target_type' => 'taxonomy_term',
        'handler_settings' => [],
      ]),
    ]);
    $this->assertFalse(ValidatedFields::isTagsField($entity, 'field_category'));
    $this->assertFalse(ValidatedFields::isFixable($entity, 'field_category'));
  }

  /**
   * Tests media reference detection.
   */
  public function testIsMediaField(): void {
    $entity = $this->entity([
      'field_image' => $this->configDefinition('Image', 'entity_reference', [
        'target_type' => 'media',
      ]),
      'field_body' => $this->configDefinition('Body', 'text_long'),
    ]);
    $this->assertTrue(ValidatedFields::isMediaField($entity, 'field_image'));
    $this->assertTrue(ValidatedFields::isFixable($entity, 'field_image'));
    $this->assertFalse(ValidatedFields::isMediaField($entity, 'field_body'));
    $this->assertFalse(ValidatedFields::isMediaField($entity, 'field_missing'));
  }

  /**
   * Tests that a missing field is never fixable.
   */
  public function testMissingFieldIsNotFixable(): void {
    $entity = $this->entity([]);
    $this->assertFalse(ValidatedFields::isFixable($entity, 'field_missing'));
  }

  /**
   * Tests the tag-name parser.
   *
   * @param string $value
   *   The raw suggested value.
   * @param list<string> $expected
   *   The expected parsed names.
   */
  #[DataProvider('providerParseTagNames')]
  public function testParseTagNames(string $value, array $expected): void {
    $this->assertSame($expected, ValidatedFields::parseTagNames($value));
  }

  /**
   * Provides cases for testParseTagNames().
   *
   * @return array<string, array{string, list<string>}>
   *   The cases.
   */
  public static function providerParseTagNames(): array {
    return [
      'empty string' => ['', []],
      'whitespace only' => ["  \n ", []],
      'comma separated' => ['Drupal, AI, Content', ['Drupal', 'AI', 'Content']],
      'semicolons and newlines' => ["Drupal; AI\nContent", ['Drupal', 'AI', 'Content']],
      'case-insensitive dedupe keeps first' => ['Drupal, drupal, DRUPAL, AI', ['Drupal', 'AI']],
      'quotes trimmed' => ['"Drupal", \'AI\'', ['Drupal', 'AI']],
      'json array' => ['["Drupal", "AI"]', ['Drupal', 'AI']],
      'json array with duplicates' => ['["Tag", "tag", "Other"]', ['Tag', 'Other']],
      'invalid json falls back to splitting' => ['[Drupal, AI', ['[Drupal', 'AI']],
      'blank entries removed' => ['Drupal,, ,AI', ['Drupal', 'AI']],
    ];
  }

}
