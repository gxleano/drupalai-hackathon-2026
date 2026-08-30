<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_validation\Unit\Form;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_content_validation\Form\AiReviewForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the pure value helpers of the AI review form.
 *
 * The form itself is container-heavy; these tests exercise only its
 * side-effect-free private helpers through reflection, on an instance
 * created without the constructor.
 */
#[CoversClass(AiReviewForm::class)]
#[Group('ai_content_validation')]
final class AiReviewFormTest extends UnitTestCase {

  /**
   * Invokes a private helper on a constructor-less form instance.
   *
   * @param string $method
   *   The private method name.
   * @param mixed ...$arguments
   *   The method arguments.
   *
   * @return mixed
   *   The method's return value.
   */
  private function invoke(string $method, mixed ...$arguments): mixed {
    $form = (new \ReflectionClass(AiReviewForm::class))->newInstanceWithoutConstructor();
    return (new \ReflectionMethod(AiReviewForm::class, $method))->invoke($form, ...$arguments);
  }

  /**
   * Tests fragment vs. full replacement when merging a suggestion.
   *
   * @param string $stored
   *   The stored field value.
   * @param string $suggested
   *   The AI-suggested value.
   * @param string $current
   *   The AI's quote of the current text.
   * @param string $expected
   *   The merged result.
   */
  #[DataProvider('providerMergeValue')]
  public function testMergeValue(string $stored, string $suggested, string $current, string $expected): void {
    $this->assertSame($expected, $this->invoke('mergeValue', $stored, $suggested, $current));
  }

  /**
   * Provides cases for testMergeValue().
   *
   * @return array<string, array{string, string, string, string}>
   *   The cases.
   */
  public static function providerMergeValue(): array {
    return [
      'fragment replacement' => [
        'The quick brown fox jumps.',
        'swift',
        'quick',
        'The swift brown fox jumps.',
      ],
      'full replacement when current is empty' => [
        'Old text.',
        'New text.',
        '',
        'New text.',
      ],
      'full replacement when current not found' => [
        'Old text.',
        'New text.',
        'missing fragment',
        'New text.',
      ],
      'full replacement when current equals stored' => [
        'Old text.',
        'New text.',
        'Old text.',
        'New text.',
      ],
      'every occurrence of the fragment is replaced' => [
        'aa bb aa',
        'cc',
        'aa',
        'cc bb cc',
      ],
    ];
  }

  /**
   * Tests detection of the value kind a suggestion carries.
   *
   * @param string $raw
   *   The raw suggested value.
   * @param string $expected
   *   The expected kind.
   */
  #[DataProvider('providerValueKind')]
  public function testValueKind(string $raw, string $expected): void {
    $this->assertSame($expected, $this->invoke('valueKind', $raw));
  }

  /**
   * Provides cases for testValueKind().
   *
   * @return array<string, array{string, string}>
   *   The cases.
   */
  public static function providerValueKind(): array {
    return [
      'json object' => ['{"title": "x", "description": "y"}', 'json'],
      'json with leading whitespace' => ["  \n {\"a\": 1}", 'json'],
      'html markup' => ['<p>Hello <strong>world</strong></p>', 'html'],
      'plain text' => ['Just a sentence.', 'plain'],
      'brace but invalid json without markup' => ['{not json at all', 'plain'],
      'brace but invalid json with markup' => ['{oops <p>x</p>', 'html'],
      'empty string' => ['', 'plain'],
      'angle bracket that is not a tag' => ['a < b and b > c', 'plain'],
    ];
  }

}
