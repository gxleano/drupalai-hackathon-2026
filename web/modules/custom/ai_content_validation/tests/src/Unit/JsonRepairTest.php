<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_validation\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_content_validation\JsonRepair;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the deterministic JSON repairer for model output.
 */
#[CoversClass(JsonRepair::class)]
#[Group('ai_content_validation')]
final class JsonRepairTest extends UnitTestCase {

  /**
   * Tests repair of mechanically defective JSON.
   *
   * @param string $raw
   *   The raw model output.
   * @param array<string, mixed>|null $expected
   *   The expected decoded object, or NULL when unrepairable.
   */
  #[DataProvider('providerParse')]
  public function testParse(string $raw, ?array $expected): void {
    $this->assertSame($expected, JsonRepair::parse($raw));
  }

  /**
   * Provides cases for testParse().
   *
   * @return array<string, array{string, array<string, mixed>|null}>
   *   The cases.
   */
  public static function providerParse(): array {
    return [
      'valid json' => ['{"a": 1}', ['a' => 1]],
      'code fence and prose prefix' => [
        "Here is the JSON:\n```json\n{\"a\": 1}\n```",
        ['a' => 1],
      ],
      'trailing garbage after root' => [
        '{"a": 1} and that is all.',
        ['a' => 1],
      ],
      'missing closing brace' => ['{"a": {"b": 2}', ['a' => ['b' => 2]]],
      'missing array and object closers' => [
        '{"a": [1, 2',
        ['a' => [1, 2]],
      ],
      'unterminated string' => ['{"a": "truncat', ['a' => 'truncat']],
      'dangling comma before truncation' => [
        '{"a": 1,',
        ['a' => 1],
      ],
      'braces inside strings are ignored' => [
        '{"a": "curly } brace", "b": 2}',
        ['a' => 'curly } brace', 'b' => 2],
      ],
      'escaped quote inside string' => [
        '{"a": "say \"hi\""}',
        ['a' => 'say "hi"'],
      ],
      'no object at all' => ['just prose', NULL],
      'empty string' => ['', NULL],
      'brace but no json' => ['{not json at all', NULL],
    ];
  }

}
