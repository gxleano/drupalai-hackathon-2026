<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_validation\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_content_validation\ValidationScorer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests derivation of the 0-100 score from the ten verdicts.
 */
#[CoversClass(ValidationScorer::class)]
#[Group('ai_content_validation')]
final class ValidationScorerTest extends UnitTestCase {

  /**
   * Builds a ten-verdict scores map.
   *
   * @param array<string, string|int> $overrides
   *   Verdicts to override, keyed by guideline number.
   *
   * @return array<string, string|int>
   *   Guideline number => verdict, guidelines 1-10.
   */
  private static function scores(array $overrides = []): array {
    $scores = [];
    foreach (range(1, 10) as $i) {
      $scores[(string) $i] = 'pass';
    }
    return $overrides + $scores;
  }

  /**
   * Tests that ten passing verdicts sum to 100.
   */
  public function testAllPassScoresHundred(): void {
    $result = ValidationScorer::applyDerivedScore([
      'scores' => self::scores(),
      'contradictions' => [],
    ]);
    $this->assertSame(100, $result['score']);
  }

  /**
   * Tests the per-verdict point values.
   */
  public function testMixedVerdictsSumPoints(): void {
    $result = ValidationScorer::applyDerivedScore([
      'scores' => self::scores(['2' => 'minor', '3' => 'major', '4' => 'fail']),
      'contradictions' => ['statement A contradicts statement B'],
    ]);
    // 7 * 10 + 8 + 4 + 0 = 82.
    $this->assertSame(82, $result['score']);
  }

  /**
   * Tests that verdict matching is case-insensitive and trims whitespace.
   */
  public function testVerdictsAreNormalized(): void {
    $result = ValidationScorer::applyDerivedScore([
      'scores' => self::scores(['2' => ' Minor ', '3' => 'MAJOR']),
      'contradictions' => ['statement A contradicts statement B'],
    ]);
    // 8 * 10 + 8 + 4 = 92.
    $this->assertSame(92, $result['score']);
  }

  /**
   * Tests that legacy numeric breakdowns still sum.
   */
  public function testLegacyNumericVerdictsSum(): void {
    $result = ValidationScorer::applyDerivedScore([
      'scores' => self::scores(['2' => 7, '3' => '5']),
      'contradictions' => ['statement A contradicts statement B'],
    ]);
    // 8 * 10 + 7 + 5 = 92.
    $this->assertSame(92, $result['score']);
  }

  /**
   * Tests that a payload without a ten-verdict map is left untouched.
   */
  public function testPayloadWithoutTenVerdictsIsUntouched(): void {
    $partial = ['scores' => ['1' => 'pass'], 'score' => 55];
    $this->assertSame($partial, ValidationScorer::applyDerivedScore($partial));
    $none = ['summary' => 'improver output'];
    $this->assertSame($none, ValidationScorer::applyDerivedScore($none));
  }

  /**
   * Tests mechanical discarding of pseudo-contradictions.
   */
  public function testPseudoContradictionsAreDiscarded(): void {
    $result = ValidationScorer::applyDerivedScore([
      'scores' => self::scores(),
      'contradictions' => [
        'The publication date is in the future.',
        'The article claims a current year fact that is future-dated.',
        'Paragraph 2 says X while paragraph 5 says not-X.',
        ['not a string'],
      ],
    ]);
    $this->assertSame(
      ['Paragraph 2 says X while paragraph 5 says not-X.'],
      $result['contradictions'],
    );
  }

  /**
   * Tests the Accuracy floor when no contradictions remain.
   */
  public function testAccuracyFlooredWithoutContradictions(): void {
    $result = ValidationScorer::applyDerivedScore([
      'scores' => self::scores(['1' => 'fail']),
      'contradictions' => [],
    ]);
    $this->assertSame('minor', $result['scores']['1']);
    // 9 * 10 + 8 = 98.
    $this->assertSame(98, $result['score']);
  }

  /**
   * Tests that a real contradiction keeps the Accuracy verdict.
   */
  public function testAccuracyKeptWithRealContradiction(): void {
    $result = ValidationScorer::applyDerivedScore([
      'scores' => self::scores(['1' => 'fail']),
      'contradictions' => ['Paragraph 2 says X while paragraph 5 says not-X.'],
    ]);
    $this->assertSame('fail', $result['scores']['1']);
    // 9 * 10 + 0 = 90.
    $this->assertSame(90, $result['score']);
  }

  /**
   * Tests that the floor also applies when the filter empties the list.
   */
  public function testAccuracyFlooredWhenFilterEmptiesContradictions(): void {
    $result = ValidationScorer::applyDerivedScore([
      'scores' => self::scores(['1' => 'major']),
      'contradictions' => ['The publication date is future-dated.'],
    ]);
    $this->assertSame([], $result['contradictions']);
    $this->assertSame('minor', $result['scores']['1']);
    $this->assertSame(98, $result['score']);
  }

  /**
   * Tests that a "review" field verdict caps an all-pass score at 95.
   */
  public function testReviewFieldVerdictCapsPerfectScore(): void {
    $result = ValidationScorer::applyDerivedScore([
      'scores' => self::scores(),
      'contradictions' => [],
      'field_verdicts' => ['title' => 'pass', 'field_tags' => 'review'],
    ]);
    $this->assertSame(95, $result['score']);
  }

  /**
   * Tests that all-pass field verdicts leave a perfect score untouched.
   */
  public function testAllPassFieldVerdictsKeepPerfectScore(): void {
    $result = ValidationScorer::applyDerivedScore([
      'scores' => self::scores(),
      'contradictions' => [],
      'field_verdicts' => ['title' => 'pass', 'field_body' => 'pass'],
    ]);
    $this->assertSame(100, $result['score']);
  }

  /**
   * Tests that an unknown verdict string leaves the score underived.
   */
  public function testUnknownVerdictSkipsScoreDerivation(): void {
    $result = ValidationScorer::applyDerivedScore([
      'scores' => self::scores(['5' => 'meh']),
      'contradictions' => ['statement A contradicts statement B'],
      'score' => 42,
    ]);
    $this->assertSame(42, $result['score']);
  }

}
