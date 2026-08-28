<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Diff\WordLevelDiff;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Markup;
use Drupal\ai_content_validation\ContentHasher;
use Drupal\flowdrop_node_session\Service\NodeSessionService;
use Drupal\flowdrop_session\DTO\TurnOptions;
use Drupal\flowdrop_session\DTO\TurnResult;
use Drupal\flowdrop_session\Service\SessionTurnServiceInterface;
use Drupal\flowdrop_workflow\Entity\FlowDropWorkflow;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Review page for AI content validations on a node.
 *
 * Replaces the FlowDrop playground UX: runs the validation workflow
 * server-side against the node, lists the resulting validation items and
 * lets the editor apply or ignore the suggested changes.
 */
final class AiReviewForm extends FormBase {

  /**
   * The workflow whose reports carry the authoritative quality score.
   *
   * Single-sourced here because a past module rename already broke
   * hardcoded workflow ids once; every report query filters on this.
   */
  private const REPORT_WORKFLOW = 'content_validation_fixer';

  /**
   * Constructs the form.
   *
   * Services are deliberately protected and non-readonly: AJAX submissions
   * rebuild this form from the form cache, and FormBase's serialization
   * trait cannot restore private or readonly promoted properties (they
   * resurface uninitialized).
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected NodeSessionService $nodeSessionService,
    protected SessionTurnServiceInterface $turnService,
    protected TimeInterface $time,
    protected LockBackendInterface $lock,
    protected ModuleExtensionList $moduleExtensionList,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('flowdrop_node_session.service'),
      $container->get('flowdrop_session.turn_service'),
      $container->get('datetime.time'),
      $container->get('lock'),
      $container->get('extension.list.module'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_content_validation_review_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if ($node === NULL) {
      return $form;
    }
    $form_state->set('nid', $node->id());
    $form['#title'] = $this->t('AI Validation: @title', ['@title' => $node->label()]);

    $operations = $this->configFactory()->get('flowdrop_node_session.settings')->get('entity_operations') ?: [];

    // Every action submits over AJAX and replaces this wrapper, so the page
    // never freezes on a full-page load while the model runs — the button's
    // throbber tells the editor what is happening.
    $form['#attached']['library'][] = 'ai_content_validation/ai_review';
    $form['#prefix'] = '<div id="ai-review-form-wrapper" class="ai-review">';
    $form['#suffix'] = '</div>';
    // The page content derives from the node and its validation items, so
    // any cached representation must invalidate when either changes.
    $form['#cache']['tags'] = Cache::mergeTags(
      $node->getCacheTags(),
      ['ai_content_validation_item_list'],
    );
    $form['messages'] = [
      '#type' => 'status_messages',
      '#weight' => -100,
    ];

    // The primary action alternates: Improve content shows ONLY right
    // after an explicit Run validation on this page (report stamped
    // manual_run and still matching the current revision). Any node edit
    // or applied improvement produces a new revision and/or an unstamped
    // report (entity triggers validate on save too), so the page falls
    // back to Run validation — changed content gets validated first.
    $latest_report = $this->latestReport($node);
    // The changed-time guard catches edits saved WITHOUT a new revision
    // (content types with "Create new revision" off keep the same
    // revision id, which the revision comparison alone cannot see).
    $revision_match = $latest_report !== NULL
      && (int) ($latest_report->get('field_content_revision')->target_revision_id ?? 0) === (int) $node->getRevisionId()
      && (int) $node->getChangedTime() <= (int) $latest_report->get('created')->value;
    $report_parsed = $latest_report === NULL
      ? NULL
      : $this->parseResult((string) ($latest_report->get('field_validation_result')->value ?? ''));
    $show_improve = $revision_match && !empty($report_parsed['manual_run']);
    // A report whose ten verdicts are all pass/minor has nothing left to
    // rewrite: the improver would only churn prose that already complies.
    // The button disappears and the editor is told to edit manually.
    $nothing_to_improve = $show_improve && !$this->hasWeakGuideline($report_parsed);
    $show_improve = $show_improve && !$nothing_to_improve;

    // ---- Status hero -----------------------------------------------------
    // One banner answers the editor's first question — "is this content OK?"
    // — with a state color, a plain-words verdict, the three key numbers and
    // the actions, before any detail below.
    [$score, $date] = $this->acceptedScore($node);
    $scores_map = $revision_match && is_array($report_parsed['scores'] ?? NULL) && $report_parsed['scores'] !== []
      ? $report_parsed['scores']
      : NULL;
    $pass_count = $scores_map === NULL ? NULL : count(array_filter(
      $scores_map,
      fn ($v) => is_numeric($v) ? (int) $v >= 10 : strtolower(trim((string) $v)) === 'pass',
    ));
    $state = match (TRUE) {
      $score === NULL => 'none',
      !$revision_match => 'stale',
      $scores_map !== NULL && !$this->hasWeakGuideline($report_parsed) => 'passed',
      default => 'issues',
    };
    [$title, $subtitle] = match ($state) {
      'passed' => [
        $this->t('Validation passed'),
        $this->t('All 10 content guidelines have been met.'),
      ],
      'issues' => [
        $this->t('Improvements suggested'),
        $pass_count === NULL
          ? $this->t('Some guidelines need attention — see the results below.')
          : $this->t('@count of 10 guidelines need attention — see the results below.', ['@count' => 10 - $pass_count]),
      ],
      'stale' => [
        $this->t('Content has changed'),
        $this->t('These results are from a previous version. Re-run the validation for a current verdict.'),
      ],
      default => [
        $this->t('Not validated yet'),
        $this->t('AI validation checks this content against the 10 EU content guidelines (accuracy, clarity, neutrality, completeness, …) and produces a quality score with concrete improvement suggestions.'),
      ],
    };
    $word = match (TRUE) {
      $score === NULL => NULL,
      $score >= 90 => $this->t('Excellent'),
      $score >= 80 => $this->t('Good'),
      $score >= 50 => $this->t('Needs work'),
      default => $this->t('Poor'),
    };

    $form['hero'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-review-hero', 'ai-review-hero--' . $state]],
      'icon' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['ai-review-hero__icon'], 'aria-hidden' => 'true'],
        '#value' => '',
      ],
      'body' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-review-hero__body']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $title,
          '#attributes' => ['class' => ['ai-review-hero__title']],
        ],
        'subtitle' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $subtitle,
          '#attributes' => ['class' => ['ai-review-hero__subtitle']],
        ],
        'stats' => $score === NULL ? [] : [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-review-hero__stats']],
          'guidelines' => $pass_count === NULL ? [] : $this->heroStat($this->t('@count / 10', ['@count' => $pass_count]), $this->t('Guidelines satisfied')),
          'score' => $this->heroStat($this->t('@score / 100', ['@score' => $score]), $this->t('Quality score')),
          'word' => $this->heroStat($word, $this->t('AI assessment')),
        ],
        'validated' => $date === NULL ? [] : [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Last validated @date', ['@date' => date('d M Y \\a\\t H:i', $date)]),
          '#attributes' => ['class' => ['ai-review-hero__date']],
        ],
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-review-hero__actions']],
      ],
    ];
    // A cached report means a plain re-run would only replay the stored
    // score — offering it NEXT TO "Re-validate anyway" is two buttons for
    // one action. So there is exactly one validate button: forced-fresh
    // when a cached report exists, the normal run otherwise.
    $cached = $operations !== [] ? $this->cachedReport($node, $operations[0]['workflow_id']) : NULL;
    if (!$show_improve && $operations !== []) {
      $form['hero']['actions']['rerun'] = [
        '#type' => 'submit',
        '#value' => match (TRUE) {
          $cached !== NULL, $state === 'passed' => $this->t('Re-run validation'),
          $latest_report !== NULL => $this->t('Re-run validation on the new version'),
          default => $this->t('Run validation'),
        },
        '#name' => ($cached !== NULL ? 'revalidate:' : 'rerun:') . $operations[0]['workflow_id'],
        '#submit' => [$cached !== NULL ? '::forceRerunValidation' : '::rerunValidation'],
        // A passed report needs no urgent action — the button stays
        // secondary so the green banner remains the loudest element.
        '#button_type' => $state === 'passed' ? NULL : 'primary',
        '#ajax' => $this->ajaxAction($this->aiProgressMessage($this->t('Analyzing the content against the 10 EU guidelines… (~30s)'))),
      ];
    }
    if ($show_improve) {
      $form['hero']['actions']['improve'] = [
        '#type' => 'submit',
        '#value' => $this->t('Improve content'),
        '#name' => 'improve:' . $latest_report->id(),
        '#submit' => ['::improveArticle'],
        '#button_type' => 'primary',
        '#ajax' => $this->ajaxAction($this->aiProgressMessage($this->t('Rewriting the content based on the findings… (~1 min)'))),
      ];
    }
    // Next to Improve content, a fresh verdict is still one click away —
    // but only while a cached report exists (otherwise a run is fresh by
    // definition and the single rerun button above covers it).
    if ($show_improve && $cached !== NULL) {
      $form['hero']['actions']['revalidate_anyway'] = [
        '#type' => 'submit',
        '#value' => $this->t('Re-validate anyway'),
        '#name' => 'revalidate:' . $operations[0]['workflow_id'],
        '#submit' => ['::forceRerunValidation'],
        '#ajax' => $this->ajaxAction($this->aiProgressMessage($this->t('Re-analyzing the content against the 10 EU guidelines… (~30s)'))),
      ];
    }
    if (count(Element::children($form['hero']['actions'])) > 0) {
      $form['hero']['actions']['hint'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['ai-review-hint']],
        '#value' => $this->t('A run takes 30–60 seconds. Nothing is changed until you apply a suggestion.'),
      ];
    }

    // ---- Validation results ------------------------------------------------
    $form['report'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-review-results']],
      'field_findings' => $this->buildFieldFindings($report_parsed, $latest_report),
      'overall_summary' => $this->buildOverallSummary($report_parsed, $latest_report),
    ];

    $form['validations'] = $this->buildValidations($node);

    return $form;
  }

  /**
   * Builds one stat block (value over label) for the status hero.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string|null $value
   *   The stat value.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The stat label.
   *
   * @return array<string, mixed>
   *   The render array, empty when there is no value.
   */
  private function heroStat($value, $label): array {
    if ($value === NULL) {
      return [];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-review-hero__stat']],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $value,
        '#attributes' => ['class' => ['ai-review-hero__stat-value']],
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $label,
        '#attributes' => ['class' => ['ai-review-hero__stat-label']],
      ],
    ];
  }

  /**
   * Whether any of the ten guideline verdicts is worth rewriting for.
   *
   * Mirrors the instruction the improver itself receives (fix everything
   * below a full "pass"): when every verdict is a pass, the improve run has
   * nothing to act on. A report with no verdict map at all is treated as
   * improvable — the old behaviour.
   *
   * @param array<string, mixed>|null $result
   *   The parsed validation result.
   *
   * @return bool
   *   TRUE when at least one guideline is weak.
   */
  private function hasWeakGuideline(?array $result): bool {
    $scores = $result === NULL ? NULL : ($result['scores'] ?? NULL);
    if (!is_array($scores) || $scores === []) {
      return TRUE;
    }
    foreach ($scores as $verdict) {
      if (is_numeric($verdict) ? (int) $verdict < 10 : strtolower(trim((string) $verdict)) !== 'pass') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Shared #ajax definition for the action buttons.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $message
   *   The progress message shown next to the throbber while the model runs.
   *
   * @return array<string, mixed>
   *   The #ajax array.
   */
  private function ajaxAction($message): array {
    return [
      'callback' => '::ajaxRefresh',
      'wrapper' => 'ai-review-form-wrapper',
      'progress' => [
        'type' => 'throbber',
        'message' => $message,
      ],
    ];
  }

  /**
   * AJAX callback: returns the rebuilt form.
   */
  public function ajaxRefresh(array &$form, FormStateInterface $form_state): array {
    return $form;
  }

  /**
   * Prefixes a progress message with the Drupal AI spark icon.
   *
   * The throbber message is inserted as HTML by Drupal.theme
   * .ajaxProgressThrobber, so an inline icon renders. The icon is the AI
   * module's own spark, masked over the admin theme's primary color.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $text
   *   The plain progress text.
   *
   * @return \Drupal\Core\Render\Markup
   *   Icon plus text markup.
   */
  private function aiProgressMessage($text): Markup {
    return Markup::create($this->aiIcon() . ' ' . Html::escape((string) $text));
  }

  /**
   * Builds the inline AI spark icon HTML.
   *
   * @return string
   *   A span masked with the AI module's spark SVG.
   */
  private function aiIcon(): string {
    $sprite = '/' . $this->moduleExtensionList->getPath('ai') . '/icons/ai_spark.svg';
    $mask = 'mask-image: url(' . $sprite . '); mask-repeat: no-repeat; mask-position: center; mask-size: 16px;';
    return '<span style="display: inline-block; width: 16px; height: 16px; vertical-align: text-bottom;'
      . ' background-color: var(--gin-color-primary, #0550e6);'
      . ' -webkit-' . $mask . ' ' . $mask . '"></span>';
  }

  /**
   * Builds the per-field findings of a validation report.
   *
   * The validator reports one finding per validated field. Only the three
   * known fields are rendered, in a fixed order, so an unexpected extra
   * key in the model's response can never inject a section of its own.
   * Reports written before per-field findings existed simply render
   * nothing here.
   *
   * @param array<string, mixed>|null $result
   *   The decoded validation result, or NULL when there is none.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $report
   *   The report the findings came from, for cache metadata.
   *
   * @return array<string, mixed>
   *   Render array, empty when there are no per-field findings.
   */
  private function buildFieldFindings(?array $result, ?ContentEntityInterface $report): array {
    $labels = [
      'title' => $this->t('Title'),
      'field_body' => $this->t('Body'),
      'field_metatags' => $this->t('Meta tags'),
    ];
    $findings = $result === NULL ? NULL : ($result['field_findings'] ?? NULL);
    if (!is_array($findings)) {
      return [];
    }

    $rows = [];
    $all_passed = TRUE;
    foreach ($labels as $field => $label) {
      $text = $findings[$field] ?? NULL;
      // An affirmative finding ("Accurate and specific.") is still a
      // finding: only an absent or empty value hides the row.
      if (!is_string($text) || trim($text) === '') {
        continue;
      }
      // A finding that names a guideline is a problem to act on; anything
      // else is a pass. Icon and badge tell the editor at a glance which
      // fields need attention.
      $issue = (bool) preg_match('/guideline\\s*\\d/i', $text);
      $all_passed = $all_passed && !$issue;
      $rows[$field] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => array_merge(['ai-review-finding'], $issue ? ['ai-review-finding--issue'] : []),
        ],
        'icon' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => '',
          '#attributes' => ['class' => ['ai-review-finding__icon'], 'aria-hidden' => 'true'],
        ],
        'body' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-review-finding__body']],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#value' => $label,
            '#attributes' => ['class' => ['ai-review-finding__label']],
          ],
          // The finding text comes from the model and is untrusted:
          // #plain_text is escaped by the renderer, #markup would not be.
          'text' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#attributes' => ['class' => ['ai-review-finding__text']],
            'value' => ['#plain_text' => trim($text)],
          ],
        ],
        'badge' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $issue ? $this->t('Review') : $this->t('Passed'),
          '#attributes' => [
            'class' => ['ai-review-badge', $issue ? 'ai-review-badge--review' : 'ai-review-badge--passed'],
          ],
        ],
      ];
    }
    if ($rows === []) {
      return [];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-review-findings']],
      '#cache' => [
        'tags' => $report === NULL ? [] : $report->getCacheTags(),
      ],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-review-findings__header']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Validation results'),
          '#attributes' => ['class' => ['ai-review-findings__heading']],
        ],
        'all_passed' => !$all_passed ? [] : [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('All fields passed'),
          '#attributes' => ['class' => ['ai-review-findings__all-passed']],
        ],
      ],
    ] + $rows;
  }

  /**
   * Builds the report's overall closing assessment.
   *
   * Rendered after the per-field findings: the summary judges the content
   * as a whole, the findings say which field to act on.
   *
   * @param array<string, mixed>|null $result
   *   The decoded validation result, or NULL when there is none.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $report
   *   The report the summary came from, for cache metadata.
   *
   * @return array<string, mixed>
   *   Render array, empty when the report carries no summary.
   */
  private function buildOverallSummary(?array $result, ?ContentEntityInterface $report): array {
    $summary = is_scalar($result['summary'] ?? NULL) ? trim((string) $result['summary']) : '';
    if ($summary === '') {
      return [];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-review-summary']],
      '#cache' => [
        'tags' => $report === NULL ? [] : $report->getCacheTags(),
      ],
      'icon' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => '',
        '#attributes' => ['class' => ['ai-review-summary__icon'], 'aria-hidden' => 'true'],
      ],
      'body' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-review-summary__body']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $this->t('Overall assessment'),
          '#attributes' => ['class' => ['ai-review-summary__heading']],
        ],
        // Untrusted model output: escaped by the renderer, never #markup.
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['ai-review-summary__text']],
          'value' => ['#plain_text' => $summary],
        ],
      ],
    ];
  }

  /**
   * Finds the node's current accepted quality score.
   *
   * The authoritative score is the newest accepted (done) validation with
   * a numeric score — the same rule the content overview donut uses. A
   * pending score is provisional until the editor accepts it, and ignored
   * ones never count.
   *
   * @return array{0: int|null, 1: int|null, 2: string}
   *   The score, its creation timestamp and the report's summary text.
   */
  private function acceptedScore(NodeInterface $node): array {
    $storage = $this->entityTypeManager->getStorage('ai_content_validation_item');
    // Only validation reports may carry the published score: without the
    // workflow filter one non-compliant model response from another
    // workflow (a proposal emitting a stray "score") would corrupt the
    // score and the no-regression guard.
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_content_revision.target_id', $node->id())
      ->condition('field_flowdrop_workflow', self::REPORT_WORKFLOW)
      ->condition('field_validation_status', 'done')
      ->sort('created', 'DESC')
      ->range(0, 10)
      ->execute();

    $items = $storage->loadMultiple($ids);
    // $ids is ordered newest first; loadMultiple() is not.
    foreach ($ids as $id) {
      $item = $items[$id] ?? NULL;
      $parsed = $item === NULL ? NULL : $this->parseResult((string) ($item->get('field_validation_result')->value ?? ''));
      if (is_numeric($parsed['score'] ?? NULL)) {
        $summary = is_scalar($parsed['summary'] ?? NULL) ? trim((string) $parsed['summary']) : '';
        return [(int) $parsed['score'], (int) $item->get('created')->value, $summary];
      }
    }
    return [NULL, NULL, ''];
  }

  /**
   * Loads the newest validation report (pending or done) for the node.
   *
   * Improvements are always based on an existing report's findings, so
   * this decides whether the Improve article button exists at all.
   */
  private function latestReport(NodeInterface $node): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('ai_content_validation_item');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_content_revision.target_id', $node->id())
      ->condition('field_flowdrop_workflow', self::REPORT_WORKFLOW)
      ->condition('field_validation_status', ['pending', 'done'], 'IN')
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();
    $report = $ids ? $storage->load(reset($ids)) : NULL;
    return $report instanceof ContentEntityInterface ? $report : NULL;
  }

  /**
   * Runs the validation workflow and accepts the resulting score.
   *
   * Clicking Run validation IS the intent to get a fresh score, so the
   * report is accepted right away instead of waiting in pending — no
   * separate Accept step.
   */
  public function rerunValidation(array &$form, FormStateInterface $form_state): void {
    [, $workflow_id] = explode(':', $form_state->getTriggeringElement()['#name'], 2);
    $node = $this->loadNode($form_state);
    if ($node !== NULL) {
      $cached = $this->cachedReport($node, $workflow_id);
      if ($cached !== NULL) {
        // A hit does no work at all: no run is dispatched, the run lock is
        // never taken, and the existing item is left exactly as it is —
        // stamping manual_run here would flip the primary button without
        // a fresh verdict behind it.
        $parsed = $this->parseResult((string) ($cached->get('field_validation_result')->value ?? '')) ?? [];
        $this->messenger()->addStatus($this->t('The validated content has not changed since the last validation, so the score of @score/100 still applies and no new AI validation was run. Use "Re-validate anyway" to force a fresh run.', [
          '@score' => (int) ($parsed['score'] ?? 0),
        ]));
        $form_state->setRebuild();
        return;
      }
      $this->runAndAccept($node, $workflow_id);
    }
    $form_state->setRebuild();
  }

  /**
   * Runs the validation workflow unconditionally, bypassing the cache.
   *
   * The editor asked for a fresh verdict on content the memoization
   * considers unchanged, so no lookup happens here.
   */
  public function forceRerunValidation(array &$form, FormStateInterface $form_state): void {
    [, $workflow_id] = explode(':', $form_state->getTriggeringElement()['#name'], 2);
    $node = $this->loadNode($form_state);
    if ($node !== NULL) {
      $this->runAndAccept($node, $workflow_id);
    }
    $form_state->setRebuild();
  }

  /**
   * Finds a current report whose stored hash matches the node's content.
   *
   * Only reports — items without suggestions — that are still current
   * (pending or done) for this node and workflow can satisfy the cache: a
   * superseded or ignored item is history and must never present its score
   * as the current one. A report without a numeric score is skipped too,
   * as a hit has to have something to show.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node about to be validated.
   * @param string $workflow_id
   *   The workflow whose report may be reused.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The reusable report, or NULL when the model has to run.
   */
  private function cachedReport(NodeInterface $node, string $workflow_id): ?ContentEntityInterface {
    $hash = ContentHasher::hash($node);
    $storage = $this->entityTypeManager->getStorage('ai_content_validation_item');
    // accessCheck(FALSE): an internal consistency lookup deciding whether
    // the model must run again. The route already gates who may open this
    // page, and the decision itself discloses no item content.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_content_revision.target_id', $node->id())
      ->condition('field_flowdrop_workflow', $workflow_id)
      ->condition('field_validation_status', ['pending', 'done'], 'IN')
      ->sort('created', 'DESC')
      ->range(0, 10)
      ->execute();

    $items = $storage->loadMultiple($ids);
    // $ids is ordered newest first; loadMultiple() is not.
    foreach ($ids as $id) {
      $item = $items[$id] ?? NULL;
      if (!$item instanceof ContentEntityInterface) {
        continue;
      }
      $parsed = $this->parseResult((string) ($item->get('field_validation_result')->value ?? ''));
      if ($parsed === NULL || ($parsed['suggestions'] ?? []) !== [] || !is_numeric($parsed['score'] ?? NULL)) {
        continue;
      }
      if (is_string($parsed['content_hash'] ?? NULL) && $parsed['content_hash'] === $hash) {
        return $item;
      }
    }
    return NULL;
  }

  /**
   * Runs the workflow and accepts or defends the resulting score.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to validate.
   * @param string $workflow_id
   *   The validation workflow to run.
   */
  private function runAndAccept(NodeInterface $node, string $workflow_id): void {
    [$previous_score] = $this->acceptedScore($node);
    $this->startRun($node, $workflow_id);
    // Stamp the report this run just produced as an explicit manual run:
    // that flag is what unlocks the Improve content button. Reports from
    // entity-save triggers or the post-apply re-validation are never
    // stamped, so any content change falls back to Run validation.
    $report = $this->latestReport($node);
    if ($report !== NULL
      && (int) $report->get('created')->value >= $this->time->getRequestTime()
      && (int) ($report->get('field_content_revision')->target_revision_id ?? 0) === (int) $node->getRevisionId()
    ) {
      $parsed = $this->parseResult((string) $report->get('field_validation_result')->value) ?? [];
      $parsed['manual_run'] = TRUE;
      $report->set('field_validation_result', json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      $new_score = is_numeric($parsed['score'] ?? NULL) ? (int) $parsed['score'] : NULL;
      // No-regression guard: a re-validation may never silently replace
      // an accepted score with a lower one (typical after applying AI
      // improvements). The report stays pending — the editor sees why
      // and can still accept the lower score deliberately.
      if ($new_score !== NULL && $previous_score !== NULL && $new_score < $previous_score
        && (string) $report->get('field_validation_status')->value === 'pending'
      ) {
        $report->save();
        $this->messenger()->addWarning($this->t('The new validation scored @new/100, lower than the current @old/100 — the current content could not reach a better quality score, so the previous score is kept. You can still accept the lower score below.', [
          '@new' => $new_score,
          '@old' => $previous_score,
        ]));
      }
      elseif ((string) $report->get('field_validation_status')->value === 'pending') {
        $this->acceptValidation($report);
      }
      else {
        $report->save();
      }
    }
  }

  /**
   * Builds the list of validation items for the node.
   *
   * Items are grouped Pending / Done / Ignored, with superseded items in a
   * single collapsed group at the bottom. Only the newest pending item is
   * expanded. Items stay direct children of the section (ordered by
   * `#weight`) so the suggestion table value paths remain
   * `validations][<id>][suggestions`.
   *
   * @return array<string, mixed>
   *   Render/form array of validations, newest first.
   */
  private function buildValidations(NodeInterface $node): array {
    $storage = $this->entityTypeManager->getStorage('ai_content_validation_item');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_content_revision.target_id', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 30)
      ->execute();

    $build = ['#tree' => TRUE];
    if (!$ids) {
      return $build;
    }

    $items = $storage->loadMultiple($ids);
    $grouped = ['pending' => [], 'done' => [], 'ignored' => [], 'superseded' => []];
    $has_open_pending = FALSE;
    // $ids is ordered newest first; loadMultiple() is not.
    foreach ($ids as $id) {
      $validation = $items[$id] ?? NULL;
      if ($validation === NULL) {
        continue;
      }
      $status = (string) ($validation->get('field_validation_status')->value ?? 'pending');
      $group = isset($grouped[$status]) ? $status : 'pending';
      $open = $group === 'pending' && !$has_open_pending;
      $has_open_pending = $has_open_pending || $open;
      $grouped[$group][$id] = $this->buildValidationItem($validation, (int) $id, $group, $open, $node);
    }

    $headings = [
      'pending' => $this->t('Pending'),
      'done' => $this->t('Done'),
      'ignored' => $this->t('Ignored'),
    ];
    $weight = 0;
    foreach ($headings as $group => $heading) {
      if ($grouped[$group] === []) {
        continue;
      }
      $build['heading_' . $group] = [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('@heading (@count)', [
          '@heading' => $heading,
          '@count' => count($grouped[$group]),
        ]),
        '#weight' => $weight++,
      ];
      foreach ($grouped[$group] as $id => $element) {
        $element['#weight'] = $weight++;
        $build[$id] = $element;
      }
    }

    if ($grouped['superseded'] !== []) {
      $build['superseded'] = [
        '#type' => 'details',
        '#title' => $this->t('Score history (@count)', ['@count' => count($grouped['superseded'])]),
        '#open' => TRUE,
        '#weight' => $weight++,
      ] + $grouped['superseded'];
    }

    return $build;
  }

  /**
   * Builds one validation item as a collapsible details element.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $validation
   *   The validation item entity.
   * @param int $id
   *   The validation item id, used in the action button names.
   * @param string $group
   *   The status group: pending, done, ignored or superseded.
   * @param bool $open
   *   Whether the details element starts expanded.
   * @param \Drupal\node\NodeInterface $node
   *   The node the validation belongs to, for current-value fallbacks.
   *
   * @return array<string, mixed>
   *   Render/form array for the single item.
   */
  private function buildValidationItem(ContentEntityInterface $validation, int $id, string $group, bool $open, NodeInterface $node): array {
    $workflow = $validation->get('field_flowdrop_workflow')->entity;
    $result_raw = (string) ($validation->get('field_validation_result')->value ?? '');
    $parsed = $this->parseResult($result_raw);
    $score = $parsed['score'] ?? NULL;
    $has_score = is_numeric($score);

    $title_args = [
      '@workflow' => $workflow?->label() ?? $this->t('Unknown workflow'),
      '@status' => $group,
      '@date' => date('d.m.Y H:i', (int) $validation->get('created')->value),
      '@score' => $has_score ? (int) $score : 0,
    ];
    $element = [
      '#type' => 'details',
      '#title' => $has_score
        ? $this->t('@workflow — score @score/100 — @status (@date)', $title_args)
        : $this->t('@workflow — @status (@date)', $title_args),
      '#open' => $open,
    ];

    $suggestions = is_array($parsed['suggestions'] ?? NULL) ? $parsed['suggestions'] : [];
    $applied = is_array($parsed['applied_fields'] ?? NULL) ? $parsed['applied_fields'] : NULL;
    $prepared = $this->prepareSuggestions($suggestions, $node);
    $summary = $parsed !== NULL && is_scalar($parsed['summary'] ?? NULL) ? trim((string) $parsed['summary']) : '';

    // Attribution: who moved this item to done (applied the changes or
    // accepted the score), stamped in the result JSON at that moment.
    $reviewed_by = NULL;
    if ($group === 'done' && is_numeric($parsed['done_by'] ?? NULL)) {
      $account = $this->entityTypeManager->getStorage('user')->load((int) $parsed['done_by']);
      $reviewed_by = $this->t('Reviewed and accepted by @user@date', [
        '@user' => $account?->getDisplayName() ?? $this->t('unknown user (@uid)', ['@uid' => $parsed['done_by']]),
        '@date' => is_numeric($parsed['done_at'] ?? NULL)
          ? ' — ' . date('d.m.Y H:i', (int) $parsed['done_at'])
          : '',
      ]);
    }

    // An applied improvement gets the celebratory banner: what happened,
    // who accepted it, how many fields changed, and a jump to the result.
    // Everything else keeps the plain summary paragraph.
    if ($group === 'done' && $applied !== NULL) {
      $element['applied_banner'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-review-applied']],
        'icon' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => '',
          '#attributes' => ['class' => ['ai-review-applied__icon'], 'aria-hidden' => 'true'],
        ],
        'body' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-review-applied__body']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('AI improvements applied'),
            '#attributes' => ['class' => ['ai-review-applied__title']],
          ],
          'subtitle' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#attributes' => ['class' => ['ai-review-applied__subtitle']],
            'value' => ['#plain_text' => $summary !== '' ? $summary : (string) $this->t('The content has been improved to better comply with the EU content guidelines.')],
          ],
          'reviewed' => $reviewed_by === NULL ? [] : [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $reviewed_by,
            '#attributes' => ['class' => ['ai-review-applied__reviewed']],
          ],
        ],
        'stats' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-review-applied__stats']],
          'fields' => $this->heroStat((string) count($applied), $this->t('Fields updated')),
          'reviewed' => $this->heroStat((string) count($prepared), $this->t('Suggestions reviewed')),
        ],
        'view' => [
          '#type' => 'link',
          '#title' => $this->t('View updated content'),
          '#url' => $node->toUrl(),
          '#attributes' => ['class' => ['button', 'button--small', 'ai-review-applied__view']],
        ],
      ];
    }
    elseif ($parsed !== NULL) {
      // Never print the stored JSON: only the parsed summary is shown, and
      // the raw value only when it is not JSON at all (legacy plain text).
      $element['summary'] = [
        '#markup' => '<p>' . ($summary !== ''
          ? nl2br($this->escapeText($summary))
          : $this->t('No summary provided.')) . '</p>',
      ];
      if ($reviewed_by !== NULL) {
        $element['done_by'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $reviewed_by,
          '#attributes' => ['class' => ['ai-review-applied__reviewed']],
        ];
      }
    }
    else {
      $element['summary'] = [
        '#markup' => '<p>' . nl2br($this->escapeText($result_raw)) . '</p>',
      ];
    }

    if ($prepared !== [] && $group === 'pending') {
      $element['suggestions'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Apply'),
          $this->t('Field'),
          $this->t('Change'),
          $this->t('Reason'),
        ],
      ];
      foreach ($prepared as $key => $s) {
        $element['suggestions'][$key] = [
          'apply' => [
            '#type' => 'checkbox',
            '#default_value' => TRUE,
            '#title' => $this->t('Apply @field', ['@field' => $s['label']]),
            '#title_display' => 'invisible',
          ],
          'field' => ['#plain_text' => $s['label']],
          'change' => [
            'diff' => $this->diffMarkup($s['current'], $s['suggested_display']),
            'edit' => $this->buildEditControl($s),
          ],
          'reason' => ['#plain_text' => $s['reason']],
        ];
      }
      $element['apply'] = [
        '#type' => 'submit',
        '#value' => $this->t('Apply selected changes'),
        '#name' => 'apply:' . $id,
        '#submit' => ['::applySuggestions'],
        '#button_type' => 'primary',
        // Deliberately NOT AJAX: applying changes the node revision, which
        // flips the primary button from Improve content to Run validation.
        // A full submit + redirect guarantees a fresh page state; the AJAX
        // rebuild proved unreliable for that switch (same-second changed
        // time comparisons).
      ];
    }
    elseif ($prepared !== []) {
      $element['detailed_heading'] = [
        '#type' => 'html_tag',
        '#tag' => 'h4',
        '#value' => $this->t('Detailed changes'),
        '#attributes' => ['class' => ['ai-review-detailed-heading']],
      ];
      $header = [
        $this->t('Field'),
        $this->t('Change'),
        $this->t('Reason'),
      ];
      if ($applied !== NULL) {
        $header[] = $this->t('Status');
      }
      $rows = [];
      foreach ($prepared as $s) {
        $row = [
          'field' => $s['label'],
          'change' => ['data' => $this->diffMarkup($s['current'], $s['suggested_display'])],
          'reason' => $s['reason'],
        ];
        if ($applied !== NULL) {
          $is_applied = in_array($s['field'], $applied, TRUE);
          // Same pill styling as the per-field Passed/Review badges, so
          // "Applied" reads like a pass everywhere on the page.
          $row['status'] = [
            'data' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => $is_applied ? $this->t('Applied') : $this->t('Not applied'),
              '#attributes' => [
                'class' => ['ai-review-badge', $is_applied ? 'ai-review-badge--passed' : 'ai-review-badge--neutral'],
              ],
            ],
          ];
        }
        $rows[] = $row;
      }
      $element['suggestions'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
      ];
    }

    // A validation report diagnoses only; the human decides: improve the
    // content (top-level Improve article button), accept the score as-is,
    // or ignore the run entirely.
    $is_report = $workflow?->id() === self::REPORT_WORKFLOW;
    if ($group === 'pending') {
      if ($is_report) {
        $element['accept'] = [
          '#type' => 'submit',
          '#value' => $this->t('Accept score'),
          '#name' => 'accept:' . $id,
          '#submit' => ['::acceptScore'],
          '#ajax' => $this->ajaxAction($this->t('Accepting the score…')),
        ];
      }
      $element['ignore'] = [
        '#type' => 'submit',
        '#value' => $this->t('Ignore'),
        '#name' => 'ignore:' . $id,
        '#submit' => ['::ignoreValidation'],
        '#ajax' => $this->ajaxAction($this->t('Ignoring…')),
      ];
    }

    return $element;
  }

  /**
   * Accepts a pending validation score, making it the active one.
   */
  public function acceptScore(array &$form, FormStateInterface $form_state): void {
    [, $validation_id] = explode(':', $form_state->getTriggeringElement()['#name'], 2);
    $validation = $this->entityTypeManager->getStorage('ai_content_validation_item')->load($validation_id);
    if ($validation !== NULL) {
      $this->acceptValidation($validation);
      $this->messenger()->addStatus($this->t('Quality score accepted.'));
    }
    $form_state->setRebuild();
  }

  /**
   * Marks a validation done and retires the previously accepted score.
   *
   * Only one accepted (done) report per node and workflow stays active:
   * older done reports become superseded so the score history is
   * unambiguous.
   */
  private function acceptValidation(ContentEntityInterface $validation): void {
    $storage = $this->entityTypeManager->getStorage('ai_content_validation_item');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_content_revision.target_id', $validation->get('field_content_revision')->target_id)
      ->condition('field_flowdrop_workflow', $validation->get('field_flowdrop_workflow')->target_id)
      ->condition('field_validation_status', 'done')
      ->condition('id', $validation->id(), '<>')
      ->execute();
    foreach ($storage->loadMultiple($ids) as $old) {
      $old->set('field_validation_status', 'superseded')->save();
    }
    $this->stampDoneBy($validation);
    $validation->set('field_validation_status', 'done')->save();
  }

  /**
   * Records who moved a validation to done, and when, in the result JSON.
   *
   * Stored in the JSON blob instead of a dedicated field so no schema
   * change or update hook is needed.
   */
  private function stampDoneBy(ContentEntityInterface $validation): void {
    $parsed = $this->parseResult((string) ($validation->get('field_validation_result')->value ?? '')) ?? [];
    $parsed['done_by'] = (int) $this->currentUser()->id();
    $parsed['done_at'] = $this->time->getRequestTime();
    $validation->set('field_validation_result', json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Runs the improve workflow using a validation's findings.
   */
  public function improveArticle(array &$form, FormStateInterface $form_state): void {
    [, $validation_id] = explode(':', $form_state->getTriggeringElement()['#name'], 2);
    $validation = $this->entityTypeManager->getStorage('ai_content_validation_item')->load($validation_id);
    $node = $this->loadNode($form_state);
    if ($validation === NULL || $node === NULL) {
      return;
    }
    $parsed = $this->parseResult((string) $validation->get('field_validation_result')->value);
    $findings = is_scalar($parsed['summary'] ?? NULL) ? trim((string) $parsed['summary']) : '';
    $score = $parsed['score'] ?? NULL;
    if (is_numeric($score)) {
      $findings = 'Quality score: ' . (int) $score . '/100. ' . $findings;
    }
    // Per-guideline scores tell the improver what is weak (fix it) and
    // what already scores well (leave it alone) — see the workflow's
    // non-regression rule. Named explicitly so the model never has to
    // guess which number maps to which guideline.
    if (is_array($parsed['scores'] ?? NULL) && $parsed['scores'] !== []) {
      $names = [
        1 => 'Accuracy & Evidence',
        2 => 'Clarity & Plain Language',
        3 => 'Neutrality & Objectivity',
        4 => 'Source Transparency',
        5 => 'Legal & Policy Consistency',
        6 => 'Audience Relevance',
        7 => 'Structure & Coherence',
        8 => 'Completeness & Context',
        9 => 'Inclusivity & Language Ethics',
        10 => 'Practical Value',
      ];
      $lines = [];
      foreach ($parsed['scores'] as $key => $value) {
        if (isset($names[(int) $key]) && (is_numeric($value) || is_string($value))) {
          $lines[] = $names[(int) $key] . ': ' . (is_numeric($value) ? ((int) $value . '/10') : $value);
        }
      }
      $findings .= ' Per-guideline verdicts: ' . implode('; ', $lines)
        . '. Concentrate ONLY on the guidelines marked minor, major or fail (or scoring below 10); do not change anything marked pass.';
    }
    // The per-field findings name the exact guideline that failed on each
    // field ("Title — Guideline 3: promotional rather than neutral"). Without
    // them the improver only sees the overall prose summary and rewrites
    // whatever it feels like; with them each suggestion targets its own
    // field's named guideline.
    if (is_array($parsed['field_findings'] ?? NULL)) {
      $labels = [
        'title' => 'Title (field "title")',
        'field_body' => 'Body (field "field_body")',
        'field_metatags' => 'Meta Tags (field "field_metatags")',
      ];
      $lines = [];
      foreach ($labels as $field => $label) {
        $text = $parsed['field_findings'][$field] ?? NULL;
        if (is_scalar($text) && trim((string) $text) !== '') {
          $lines[] = $label . ' → ' . trim((string) $text);
        }
      }
      if ($lines !== []) {
        $findings .= ' Per-field findings (each line is the ONLY reason that field may be changed): ' . implode(' | ', $lines);
      }
    }
    $form_state->setRebuild();
    if ($findings === '') {
      $this->messenger()->addWarning($this->t('This validation has no findings to improve from.'));
      return;
    }
    $this->startRun($node, 'content_improve', $findings);
  }

  /**
   * Normalizes AI suggestions into display-ready data.
   *
   * @param array<int, mixed> $suggestions
   *   The suggestions as parsed from the validation result.
   * @param \Drupal\node\NodeInterface $node
   *   The node, used to fall back to the stored field value when the AI
   *   omitted the "current" value (typical for empty body or meta tags).
   *
   * @return array<string, array{field: string, label: string, current: string, suggested_display: string, suggested_raw: string, reason: string}>
   *   Prepared suggestions keyed by "s<delta>": a raw 0 key would be
   *   dropped by array_filter() when reading checkbox values back.
   */
  private function prepareSuggestions(array $suggestions, NodeInterface $node): array {
    $prepared = [];
    foreach ($suggestions as $delta => $suggestion) {
      if (!is_array($suggestion)) {
        continue;
      }
      $field = (string) ($suggestion['field'] ?? '');
      // The AI sometimes sends no "current" value (e.g. the field was
      // empty); fall back to the node's stored value so the diff never
      // reads as broken.
      $current = $this->displayValue($suggestion['current'] ?? '');
      if ($current === '') {
        $current = $this->displayValue($this->currentNodeValue($node, $field));
      }
      $suggested_raw = $this->rawValue($suggestion['suggested'] ?? '');
      // The improve model is told to OMIT fields it cannot edit without
      // inventing content, but it sometimes emits them with an empty
      // suggested value anyway — an empty "change" is never applicable,
      // so drop the row instead of offering to blank a field.
      if ($this->plainText($suggested_raw) === '') {
        continue;
      }
      $prepared['s' . $delta] = [
        'field' => $field,
        'label' => (string) ($suggestion['label'] ?? $field),
        'current' => $current,
        'suggested_display' => $this->displayValue($suggested_raw),
        'suggested_raw' => $suggested_raw,
        'kind' => $this->valueKind($suggested_raw),
        'format' => $this->fieldTextFormat($node, $field),
        'reason' => (string) ($suggestion['reason'] ?? ''),
      ];
    }
    return $prepared;
  }

  /**
   * Reads the text format of a node field, when it has one.
   */
  private function fieldTextFormat(NodeInterface $node, string $field): ?string {
    if ($field === '' || !$node->hasField($field)) {
      return NULL;
    }
    $value = $node->get($field)->first()?->getValue() ?? [];
    return isset($value['format']) ? (string) $value['format'] : NULL;
  }

  /**
   * Detects what kind of value a suggestion carries.
   *
   * @return string
   *   One of 'json' (serialized object, e.g. meta tags), 'html' (markup,
   *   e.g. body) or 'plain'.
   */
  private function valueKind(string $raw): string {
    $trimmed = ltrim($raw);
    if (str_starts_with($trimmed, '{') && is_array(json_decode($trimmed, TRUE))) {
      return 'json';
    }
    if (preg_match('/<[a-z][^>]*>/i', $trimmed)) {
      return 'html';
    }
    return 'plain';
  }

  /**
   * Builds the per-suggestion edit control, shaped by the value kind.
   *
   * Non-technical editors must never see raw JSON or HTML: JSON values get
   * one text field per key (re-encoded on apply, so the JSON can never
   * break), HTML values (body) get a rich-text editor pinned to the
   * field's own text format, and plain text gets a free-form textarea.
   *
   * @param array{suggested_raw: string, kind: string, format: string|null} $s
   *   One prepared suggestion.
   *
   * @return array<string, mixed>
   *   Render/form array for the edit control.
   */
  private function buildEditControl(array $s): array {
    $edit = [
      '#type' => 'details',
      '#title' => $this->t('Edit suggestion'),
      '#open' => FALSE,
    ];
    if ($s['kind'] === 'html') {
      $format = $s['format'] ?: 'basic_html';
      $edit['value'] = [
        '#type' => 'text_format',
        '#format' => $format,
        '#allowed_formats' => [$format],
        '#default_value' => $s['suggested_raw'],
        '#rows' => 8,
        '#description' => $this->t('Your edited text replaces the AI suggestion when applied.'),
      ];
      return $edit;
    }
    if ($s['kind'] === 'json') {
      foreach (json_decode($s['suggested_raw'], TRUE) as $key => $item) {
        if (!is_scalar($item)) {
          continue;
        }
        $edit['json'][$key] = [
          '#type' => 'textarea',
          '#title' => ucfirst((string) $key),
          '#default_value' => (string) $item,
          '#rows' => 2,
        ];
      }
      $edit['json']['#type'] = 'container';
      return $edit;
    }
    $edit['value'] = [
      '#type' => 'textarea',
      '#default_value' => $s['suggested_raw'],
      '#rows' => 4,
      '#description' => $this->t('Your edited text replaces the AI suggestion when applied.'),
    ];
    return $edit;
  }

  /**
   * Renders a GitHub-style word diff between current and suggested text.
   *
   * Uses core's WordLevelDiff (the config-diff engine): a red deletion
   * line and a green insertion line with the changed words highlighted.
   * Both inputs are escaped before diffing — the engine outputs its marker
   * spans around otherwise verbatim text.
   *
   * @param string $current
   *   The current plain-text value.
   * @param string $suggested
   *   The suggested plain-text value.
   *
   * @return array<string, mixed>
   *   Render array for the change cell.
   */
  private function diffMarkup(string $current, string $suggested): array {
    if ($current === $suggested) {
      return ['#plain_text' => $suggested];
    }
    if ($current === '') {
      // Markup::create() instead of #markup: all text is escaped above and
      // the class names must survive Xss::filterAdmin().
      return [
        '#markup' => Markup::create('<div class="ai-diff ai-diff--ins">+ '
          . $this->escapeText($suggested) . '</div>'),
      ];
    }
    $diff = new WordLevelDiff([$this->escapeText($current)], [$this->escapeText($suggested)]);
    $del = str_replace(
      '<span class="diffchange">',
      '<span class="ai-diff__change ai-diff__change--del">',
      implode('<br>', $diff->orig()),
    );
    $ins = str_replace(
      '<span class="diffchange">',
      '<span class="ai-diff__change ai-diff__change--ins">',
      implode('<br>', $diff->closing()),
    );
    return [
      '#markup' => Markup::create('<div class="ai-diff ai-diff--del">− ' . $del . '</div>'
        . '<div class="ai-diff ai-diff--ins">+ ' . $ins . '</div>'),
    ];
  }

  /**
   * Normalizes a model-sent value (scalar or array) to a string.
   *
   * Arrays are JSON-encoded (never cast — casting an array to string is a
   * PHP warning and yields the literal "Array"), and key/value-pair lists
   * are normalized to the stored object shape.
   */
  private function rawValue(mixed $value): string {
    return $this->normalizeKeyValueJson(is_scalar($value)
      ? (string) $value
      : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Converts a JSON list of {key, value} pairs into a plain JSON object.
   *
   * The improve model sometimes serializes meta tags as
   * [{"key":"title","value":"…"}, …] instead of {"title":"…"} — usually
   * when the current value is empty and gives it no format to mirror.
   * The stored metatag format is the object shape, so normalize before
   * display, edit and apply. Anything else passes through unchanged.
   */
  private function normalizeKeyValueJson(string $raw): string {
    $trimmed = ltrim($raw);
    if (!str_starts_with($trimmed, '[')) {
      return $raw;
    }
    $decoded = json_decode($trimmed, TRUE);
    if (!is_array($decoded) || $decoded === []) {
      return $raw;
    }
    $assoc = [];
    foreach ($decoded as $item) {
      if (!is_array($item) || !is_scalar($item['key'] ?? NULL) || !is_scalar($item['value'] ?? NULL)) {
        return $raw;
      }
      $assoc[(string) $item['key']] = $item['value'];
    }
    return (string) json_encode($assoc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  /**
   * Reads a field's current value from the node for display.
   */
  private function currentNodeValue(NodeInterface $node, string $field): string {
    if ($field === 'title') {
      return $node->getTitle() ?? '';
    }
    if ($field === '' || !$node->hasField($field)) {
      return '';
    }
    $value = $node->get($field)->first()?->getValue() ?? [];
    return is_scalar($value['value'] ?? NULL) ? (string) $value['value'] : '';
  }

  /**
   * Formats a suggested or current field value for display.
   *
   * Some fields store serialized JSON themselves (meta tags, for example).
   * Those are shown as readable "key: value" pairs instead of raw JSON.
   * HTML markup (body values) is reduced to plain readable text. The value
   * applied to the node is always the unmodified original.
   *
   * @param mixed $value
   *   The raw value from the suggestion.
   *
   * @return string
   *   Plain display text.
   */
  private function displayValue(mixed $value): string {
    if (is_array($value)) {
      $decoded = $value;
    }
    else {
      $text = is_scalar($value) ? (string) $value : '';
      if ($text === '' || !str_starts_with(ltrim($text), '{')) {
        return $this->plainText($text);
      }
      $decoded = json_decode($text, TRUE);
      if (!is_array($decoded)) {
        return $this->plainText($text);
      }
    }

    $parts = [];
    foreach ($decoded as $key => $item) {
      $parts[] = is_scalar($item)
        ? $key . ': ' . $item
        : $key . ': …';
    }
    return $this->plainText(implode(' · ', $parts));
  }

  /**
   * Reduces an HTML value to readable plain text for the table.
   */
  private function plainText(string $text): string {
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)) ?? $text);
  }

  /**
   * Decodes a stored validation result into an array.
   *
   * The workflow stores the result object JSON-encoded. Legacy items can be
   * double-encoded (a JSON string containing JSON), so decode twice before
   * giving up.
   *
   * @param string $raw
   *   The stored field value.
   *
   * @return array<string, mixed>|null
   *   The decoded result, or NULL when the value is not JSON.
   */
  private function parseResult(string $raw): ?array {
    $decoded = json_decode($raw, TRUE);
    if (is_string($decoded)) {
      $decoded = json_decode($decoded, TRUE);
    }
    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // All buttons use dedicated #submit handlers.
  }

  /**
   * Starts a workflow run for the node, pausing at its first question.
   *
   * Runs synchronously; the unified workflow interrupts on the choice node
   * before any AI call, so this is fast. Quiet on the expected
   * awaiting-input outcome — the question renders inline right below.
   */
  private function startRun(NodeInterface $node, string $workflow_id, string $message = 'Run'): void {
    $workflow = $this->entityTypeManager->getStorage('flowdrop_workflow')->load($workflow_id);
    if (!$workflow instanceof FlowDropWorkflow) {
      return;
    }

    // Concurrent page views must not each start an AI run: the first
    // request holds the lock for the duration of its synchronous run.
    $lock_name = 'ai_content_validation:run:' . $node->id();
    if (!$this->lock->acquire($lock_name, 180.0)) {
      $this->messenger()->addStatus($this->t('A validation is already running for this content. Reload the page in a moment to see the result.'));
      return;
    }

    try {
      $session = $this->nodeSessionService->createSessionWithEntityContext(
        $workflow,
        'node',
        (string) $node->id(),
        '',
        (string) $node->getRevisionId(),
        'AI Validation: ' . $node->label(),
      );
      $result = $this->turnService->executeTurn((string) $session->id(), $message, new TurnOptions(wait: TRUE));
      if ($result->status === TurnResult::STATUS_COMPLETED) {
        $this->messenger()->addStatus($this->t('@label finished. Review the results below.', ['@label' => $workflow->label()]));
      }
      elseif ($result->status !== TurnResult::STATUS_AWAITING_INPUT) {
        $this->messenger()->addWarning($this->t('@label finished with status: @status.', [
          '@label' => $workflow->label(),
          '@status' => $result->status,
        ]));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Could not start @label: @message', [
        '@label' => $workflow->label(),
        '@message' => $e->getMessage(),
      ]));
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Applies the selected suggestions to the node as a new revision.
   */
  public function applySuggestions(array &$form, FormStateInterface $form_state): void {
    [, $validation_id] = explode(':', $form_state->getTriggeringElement()['#name'], 2);
    $validation = $this->entityTypeManager->getStorage('ai_content_validation_item')->load($validation_id);
    $node = $this->loadNode($form_state);
    if ($validation === NULL || $node === NULL) {
      return;
    }

    $parsed = $this->parseResult((string) $validation->get('field_validation_result')->value);
    $suggestions = is_array($parsed['suggestions'] ?? NULL) ? $parsed['suggestions'] : [];
    $rows = $form_state->getValue(['validations', $validation_id, 'suggestions'], []);
    $selected_count = 0;

    $applied = [];
    $applied_fields = [];
    foreach ($rows as $key => $row) {
      if (empty($row['apply'])) {
        continue;
      }
      $selected_count++;
      $suggestion = $suggestions[(int) substr((string) $key, 1)] ?? NULL;
      if ($suggestion === NULL || !isset($suggestion['field'], $suggestion['suggested'])) {
        continue;
      }
      // An edited suggestion wins over the AI's; an emptied field falls
      // back to the AI suggestion so a stray clear never wipes a value.
      // JSON suggestions come back as per-key fields and are re-encoded
      // here, so the serialized value can never be malformed. HTML
      // suggestions come back from a text_format element as
      // {value, format} — only the value is applied (the field keeps its
      // stored format).
      $suggested_raw = $this->rawValue($suggestion['suggested']);
      $value = $suggested_raw;
      $edit = $row['change']['edit'] ?? [];
      if (is_array($edit['json'] ?? NULL)) {
        $decoded = json_decode($suggested_raw, TRUE) ?: [];
        foreach ($edit['json'] as $json_key => $json_value) {
          if (isset($decoded[$json_key]) && trim((string) $json_value) !== '') {
            $decoded[$json_key] = (string) $json_value;
          }
        }
        $value = (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      }
      else {
        $edited = $edit['value'] ?? '';
        if (is_array($edited)) {
          $edited = $edited['value'] ?? '';
        }
        if (trim((string) $edited) !== '') {
          $value = trim((string) $edited);
        }
      }
      // Never blank a field: an empty replacement value is a model
      // mistake (skipped-field contract), not an instruction to clear.
      if ($this->plainText($value) === '') {
        continue;
      }
      if ($this->applyToNode($node, (string) $suggestion['field'], $value, $this->rawValue($suggestion['current'] ?? ''))) {
        $applied[] = $suggestion['label'] ?? $suggestion['field'];
        $applied_fields[] = (string) $suggestion['field'];
        // Store what was ACTUALLY applied, so the done item's diff shows
        // the editor's text, not the AI's; keep the AI original alongside.
        if ($value !== $suggested_raw) {
          $parsed['suggestions'][(int) substr((string) $key, 1)]['ai_suggested'] = $suggestion['suggested'];
          $parsed['suggestions'][(int) substr((string) $key, 1)]['suggested'] = $value;
        }
      }
      else {
        $this->messenger()->addWarning($this->t('Could not apply suggestion for field %field.', ['%field' => $suggestion['field']]));
      }
    }

    if ($applied) {
      $skipped = count(array_filter($suggestions, 'is_array')) - $selected_count;
      if ($skipped > 0) {
        $this->messenger()->addWarning($this->t('You skipped @count suggestion(s). Findings they address remain unfixed.', ['@count' => $skipped]));
      }
      $node->setNewRevision(TRUE);
      if ($node instanceof RevisionLogInterface) {
        $node->setRevisionLogMessage('Applied AI suggestions (' . implode(', ', $applied) . ') from validation #' . $validation_id);
        $node->setRevisionUserId((int) $this->currentUser()->id());
        $node->setRevisionCreationTime($this->time->getRequestTime());
      }
      $node->save();
      // Record which fields were applied (and which were not) so the done
      // item can show ✓/✕ markers per field afterwards, plus who applied
      // them and when.
      $parsed['applied_fields'] = $applied_fields;
      $parsed['done_by'] = (int) $this->currentUser()->id();
      $parsed['done_at'] = $this->time->getRequestTime();
      $validation->set('field_validation_result', json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      $validation->set('field_validation_status', 'done')->save();
      $this->messenger()->addStatus($this->t('Applied @count change(s) to %title as a new revision. The quality score still refers to the previous version — run validation again for a fresh score.', [
        '@count' => count($applied),
        '%title' => $node->label(),
      ]));
      // Applying suggestions deliberately does NOT re-validate or touch
      // the quality score: the new node revision no longer matches the
      // scored one, so the score header switches to "score from a previous
      // version — the content has changed since" and the editor decides
      // when to run a fresh validation.
    }
    else {
      $this->messenger()->addWarning($this->t('No changes were applied.'));
    }
    // No setRebuild(): the non-AJAX submit redirects back to this page, so
    // the button state is rebuilt from scratch on a fresh GET.
  }

  /**
   * Marks a validation as ignored.
   */
  public function ignoreValidation(array &$form, FormStateInterface $form_state): void {
    [, $validation_id] = explode(':', $form_state->getTriggeringElement()['#name'], 2);
    $validation = $this->entityTypeManager->getStorage('ai_content_validation_item')->load($validation_id);
    if ($validation !== NULL) {
      $validation->set('field_validation_status', 'ignored')->save();
      $this->messenger()->addStatus($this->t('Validation ignored.'));
    }
    $form_state->setRebuild();
  }

  /**
   * Writes a suggested value to a node field.
   *
   * Supports the title and any field whose main property is "value"
   * (string, text, text_long, ...). Text formats are preserved. When the
   * AI's "current" text is a fragment found inside the stored value, only
   * that fragment is replaced instead of overwriting the whole field.
   */
  private function applyToNode(NodeInterface $node, string $field, string $value, string $current = ''): bool {
    if ($field === 'title') {
      $node->setTitle($this->mergeValue($node->getTitle() ?? '', $value, $current));
      return TRUE;
    }
    if (!$node->hasField($field)) {
      return FALSE;
    }
    $item = $node->get($field);
    $definition = $item->getFieldDefinition()->getFieldStorageDefinition();
    if ($definition->getMainPropertyName() !== 'value') {
      return FALSE;
    }
    $existing = $item->first()?->getValue() ?? [];
    $existing['value'] = $this->mergeValue((string) ($existing['value'] ?? ''), $value, $current);
    $node->set($field, $existing);
    return TRUE;
  }

  /**
   * Merges a suggested value into the stored one.
   *
   * Fragment replacement when "current" matches a substring; full
   * replacement otherwise.
   */
  private function mergeValue(string $stored, string $suggested, string $current): string {
    if ($current !== '' && $current !== $stored && str_contains($stored, $current)) {
      return str_replace($current, $suggested, $stored);
    }
    return $suggested;
  }

  /**
   * Loads the current (default revision) node the form acts on.
   */
  private function loadNode(FormStateInterface $form_state): ?NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($form_state->get('nid'));
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Escapes plain text for markup output.
   */
  private function escapeText(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
  }

}
