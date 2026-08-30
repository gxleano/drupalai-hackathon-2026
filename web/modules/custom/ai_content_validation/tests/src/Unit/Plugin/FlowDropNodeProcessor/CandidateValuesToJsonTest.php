<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_validation\Unit\Plugin\FlowDropNodeProcessor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_content_validation\Plugin\FlowDropNodeProcessor\CandidateValuesToJson;
use Drupal\flowdrop\DTO\ParameterBag;
use Drupal\flowdrop\Service\EntitySerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the candidate-values serializer node processor.
 */
#[CoversClass(CandidateValuesToJson::class)]
#[Group('ai_content_validation')]
final class CandidateValuesToJsonTest extends UnitTestCase {

  /**
   * Builds the processor with unused mocked collaborators.
   *
   * @return \Drupal\ai_content_validation\Plugin\FlowDropNodeProcessor\CandidateValuesToJson
   *   The processor under test.
   */
  private function processor(): CandidateValuesToJson {
    return new CandidateValuesToJson(
      [],
      'ai_content_validation_candidate_json',
      [],
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(EntitySerializer::class),
    );
  }

  /**
   * Invokes the private candidate guard on the processor.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The candidate base entity.
   * @param string $field_name
   *   The candidate field name.
   * @param mixed $value
   *   The candidate value.
   */
  private function assertCandidate(FieldableEntityInterface $entity, string $field_name, mixed $value): void {
    $method = new \ReflectionMethod(CandidateValuesToJson::class, 'assertCandidateIsApplicable');
    $method->invoke($this->processor(), $entity, $field_name, $value);
  }

  /**
   * Tests that the parameter schema names the three inputs.
   */
  public function testParameterSchemaShape(): void {
    $schema = $this->processor()->getParameterSchema();
    $this->assertSame(
      ['entity_id', 'revision_id', 'candidate_values'],
      array_keys($schema['properties']),
    );
  }

  /**
   * Tests that an empty node id is rejected.
   */
  public function testProcessRequiresEntityId(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('node ID is required');
    $this->processor()->process(new ParameterBag(['entity_id' => '  ']));
  }

  /**
   * Tests that protected base fields are rejected as candidates.
   *
   * @param string $field_name
   *   The protected field name.
   */
  #[DataProvider('providerProtectedFields')]
  public function testProtectedFieldsAreRejected(string $field_name): void {
    $entity = $this->createMock(FieldableEntityInterface::class);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('may not be supplied as a candidate value');
    $this->assertCandidate($entity, $field_name, [['value' => 'x']]);
  }

  /**
   * Provides protected field names.
   *
   * @return array<string, array{string}>
   *   The cases.
   */
  public static function providerProtectedFields(): array {
    return [
      'created' => ['created'],
      'changed' => ['changed'],
      'vid' => ['vid'],
      'revision_timestamp' => ['revision_timestamp'],
      'uuid' => ['uuid'],
      'type' => ['type'],
    ];
  }

  /**
   * Tests that an unknown field is rejected as a candidate.
   */
  public function testUnknownFieldIsRejected(): void {
    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('hasField')->willReturn(FALSE);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('unknown field "field_bogus"');
    $this->assertCandidate($entity, 'field_bogus', [['value' => 'x']]);
  }

  /**
   * Tests that an object candidate value is rejected.
   */
  public function testObjectValueIsRejected(): void {
    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('hasField')->willReturn(TRUE);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('must be a scalar or a field-item array');
    $this->assertCandidate($entity, 'field_body', new \stdClass());
  }

  /**
   * Tests that a field-item array candidate passes the guard.
   */
  public function testFieldItemArrayIsAccepted(): void {
    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('hasField')->willReturn(TRUE);
    $this->assertCandidate($entity, 'field_body', [['value' => 'x', 'format' => 'basic_html']]);
    // No exception means the guard accepted the shape.
    $this->addToAssertionCount(1);
  }

}
