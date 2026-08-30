<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_validation\Unit\Plugin\FlowDropNodeProcessor;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_content_validation\Plugin\FlowDropNodeProcessor\ValidationPayload;
use Drupal\flowdrop\DTO\ParameterBag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the validator payload node processor.
 */
#[CoversClass(ValidationPayload::class)]
#[Group('ai_content_validation')]
final class ValidationPayloadTest extends UnitTestCase {

  /**
   * Builds the processor with a node storage that loads nothing.
   *
   * @return \Drupal\ai_content_validation\Plugin\FlowDropNodeProcessor\ValidationPayload
   *   The processor under test.
   */
  private function processor(): ValidationPayload {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('node')->willReturn($storage);
    return new ValidationPayload([], 'ai_content_validation_payload', [], $entity_type_manager);
  }

  /**
   * Tests that the parameter schema requires the data input.
   */
  public function testParameterSchemaRequiresData(): void {
    $schema = $this->processor()->getParameterSchema();
    $this->assertArrayHasKey('data', $schema['properties']);
    $this->assertSame(['data'], $schema['required']);
  }

  /**
   * Tests that the output schema exposes a json string.
   */
  public function testOutputSchemaExposesJson(): void {
    $schema = $this->processor()->getOutputSchema();
    $this->assertSame('string', $schema['properties']['json']['type']);
  }

  /**
   * Tests that empty input data is rejected.
   */
  public function testProcessThrowsOnEmptyData(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('no entity data');
    $this->processor()->process(new ParameterBag(['data' => []]));
  }

  /**
   * Tests the emitted message when the node cannot be loaded.
   *
   * The header is derived from the serialized data alone, and the JSON
   * body follows on the next line.
   */
  public function testProcessBuildsHeaderAndJsonFromDataAlone(): void {
    $data = [
      'id' => 1,
      'title' => 'Hello world',
      'fields' => [
        'field_body' => [['value' => 'Body text']],
        'field_tags' => [],
      ],
    ];
    $result = $this->processor()->process(new ParameterBag(['data' => $data]));
    $this->assertArrayHasKey('json', $result);
    [$header, $json] = explode("\n", $result['json'], 2);
    $this->assertStringStartsWith('ASSESSED FIELDS (3, exactly these', $header);
    $this->assertStringContainsString('"title"', $header);
    $this->assertStringContainsString('"field_body"', $header);
    $this->assertStringContainsString('"field_tags"', $header);
    $this->assertSame($data, json_decode($json, TRUE));
  }

  /**
   * Tests the header contract rendered without a loadable node.
   */
  public function testHeaderListsOnlyFieldPrefixedKeys(): void {
    $header = ValidationPayload::header(NULL, [
      'title' => 'Hello',
      'fields' => [
        'field_body' => [['value' => 'x']],
        'uid' => [['target_id' => 1]],
      ],
    ]);
    $this->assertStringStartsWith('ASSESSED FIELDS (2', $header);
    $this->assertStringContainsString('"title"', $header);
    $this->assertStringContainsString('"field_body"', $header);
    $this->assertStringNotContainsString('"uid"', $header);
    $this->assertStringContainsString('field_findings', $header);
    $this->assertStringContainsString('field_verdicts', $header);
  }

}
