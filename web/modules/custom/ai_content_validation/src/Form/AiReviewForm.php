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
use Drupal\Core\Render\Markup;
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
    $form['#prefix'] = '<div id="ai-review-form-wrapper">';
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

    $form['current_score'] = $this->buildCurrentScore($node, $revision_match);

    if ($latest_report === NULL) {
      $form['intro'] = [
        '#markup' => '<p>' . $this->t('AI validation checks this content against the 10 EU content guidelines (accuracy, clarity, neutrality, completeness, …) and produces a quality score with concrete improvement suggestions.') . '</p>',
      ];
    }
    if (!$show_improve && $operations !== []) {
      $form['rerun'] = [
        '#type' => 'submit',
        '#value' => $latest_report !== NULL
          ? $this->t('Re-run validation on the new version')
          : $this->t('Run validation'),
        '#name' => 'rerun:' . $operations[0]['workflow_id'],
        '#submit' => ['::rerunValidation'],
        '#button_type' => 'primary',
        '#ajax' => $this->ajaxAction($this->aiProgressMessage($this->t('Analyzing the content against the 10 EU guidelines… (~30s)'))),
      ];
      $form['rerun_help'] = [
        '#markup' => '<p style="font-size: 0.85em; color: var(--gin-color-text-light, #55565b);">'
          . $this->t('Takes about 30 seconds. Nothing is changed on your content until you apply a suggestion.') . '</p>',
      ];
    }
    if ($show_improve) {
      $form['improve'] = [
        '#type' => 'submit',
        '#value' => $this->t('Improve content'),
        '#name' => 'improve:' . $latest_report->id(),
        '#submit' => ['::improveArticle'],
        '#button_type' => 'primary',
        '#ajax' => $this->ajaxAction($this->aiProgressMessage($this->t('Rewriting the content based on the findings… (~1 min)'))),
      ];
    }
    $form['validations'] = $this->buildValidations($node);

    return $form;
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
   * Builds an inline warning icon in the Gin warning color.
   *
   * Core's warning triangle, masked so it follows the admin theme's
   * warning color — same technique as the AI spark icon.
   *
   * @return string
   *   A warning icon span.
   */
  private function warningIcon(): string {
    $mask = 'mask-image: url(/core/misc/icons/e29700/warning.svg); mask-repeat: no-repeat; mask-position: center; mask-size: 14px;';
    return '<span style="display: inline-block; width: 14px; height: 14px; vertical-align: text-bottom;'
      . ' background-color: var(--gin-color-warning, #e29700);'
      . ' -webkit-' . $mask . ' ' . $mask . '"></span>';
  }

  /**
   * Builds the current quality score header for the node.
   *
   * The authoritative score is the newest accepted (done) validation with
   * a numeric score — the same rule the content overview donut uses.
   *
   * @return array<string, mixed>
   *   Render array with the big score (or a hint when unscored).
   */
  private function buildCurrentScore(NodeInterface $node, bool $validated_current = TRUE): array {
    [$score, $date, $summary] = $this->acceptedScore($node);

    // Same grade colors and thresholds as the content overview donut, so
    // the number carries meaning without cross-referencing the list.
    if ($score === NULL) {
      $color = 'var(--gin-color-disabled, #8f939a)';
      $word = $this->t('Not validated yet');
    }
    elseif ($score >= 80) {
      $color = 'var(--gin-color-green, #26a769)';
      $word = $this->t('Good');
    }
    elseif ($score >= 50) {
      $color = 'var(--gin-color-warning, #e29700)';
      $word = $this->t('Needs work');
    }
    else {
      $color = 'var(--gin-color-danger, #dc2323)';
      $word = $this->t('Poor');
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-review-score'],
        'style' => 'margin: 0 0 1em;',
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => $score !== NULL
          ? $this->t('@score / 100', ['@score' => $score])
          : $this->t('– / 100'),
        '#attributes' => ['style' => 'font-size: 2.5em; line-height: 1; color: ' . $color . ';'],
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        // Markup::create(): the stale variant embeds the core warning icon
        // and t() output is already safe.
        '#value' => $score !== NULL
          ? ($validated_current
            ? $this->t('@word — quality score, validated @date', [
              '@word' => $word,
              '@date' => date('d.m.Y H:i', $date),
            ])
            : Markup::create($this->warningIcon() . ' ' . $this->t('@word — score from a previous version (@date) — the content has changed since', [
              '@word' => $word,
              '@date' => date('d.m.Y H:i', $date),
            ])))
          : $this->t('Quality score — not validated yet'),
        '#attributes' => ['style' => 'font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.05em;'],
      ],
      'summary' => $score !== NULL && $summary !== ''
        ? [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => nl2br($this->escapeText($summary)),
          '#attributes' => ['style' => 'max-width: 60em; margin: 0.75em 0 0;'],
        ]
        : [],
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
        if ((string) $report->get('field_validation_status')->value === 'pending') {
          $this->acceptValidation($report);
        }
        else {
          $report->save();
        }
      }
    }
    $form_state->setRebuild();
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
        '#title' => $this->t('Superseded (@count)', ['@count' => count($grouped['superseded'])]),
        '#open' => FALSE,
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

    // Never print the stored JSON: only the parsed summary is shown, and
    // the raw value only when it is not JSON at all (legacy plain text).
    if ($parsed !== NULL) {
      $summary = is_scalar($parsed['summary'] ?? NULL) ? trim((string) $parsed['summary']) : '';
      $element['summary'] = [
        '#markup' => '<p>' . ($summary !== ''
          ? nl2br($this->escapeText($summary))
          : $this->t('No summary provided.')) . '</p>',
      ];
    }
    else {
      $element['summary'] = [
        '#markup' => '<p>' . nl2br($this->escapeText($result_raw)) . '</p>',
      ];
    }

    // Attribution: who moved this item to done (applied the changes or
    // accepted the score), stamped in the result JSON at that moment.
    if ($group === 'done' && is_numeric($parsed['done_by'] ?? NULL)) {
      $account = $this->entityTypeManager->getStorage('user')->load((int) $parsed['done_by']);
      $element['done_by'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Reviewed and accepted by @user@date', [
          '@user' => $account?->getDisplayName() ?? $this->t('unknown user (@uid)', ['@uid' => $parsed['done_by']]),
          '@date' => is_numeric($parsed['done_at'] ?? NULL)
            ? ' — ' . date('d.m.Y H:i', (int) $parsed['done_at'])
            : '',
        ]),
        '#attributes' => ['style' => 'font-size: 0.85em; color: var(--gin-color-text-light, #55565b);'],
      ];
    }

    $suggestions = is_array($parsed['suggestions'] ?? NULL) ? $parsed['suggestions'] : [];
    $applied = is_array($parsed['applied_fields'] ?? NULL) ? $parsed['applied_fields'] : NULL;
    $prepared = $this->prepareSuggestions($suggestions, $node);

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
        '#ajax' => $this->ajaxAction($this->t('Applying the changes…')),
      ];
    }
    elseif ($prepared !== []) {
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
          // Same marker styling the node status uses, so applied/not
          // applied reads exactly like published/unpublished elsewhere.
          $row['status'] = [
            'data' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => $is_applied ? $this->t('Applied') : $this->t('Not applied'),
              '#attributes' => [
                'class' => array_filter(['marker', $is_applied ? 'marker--published' : NULL]),
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
      $raw = $suggestion['suggested'] ?? '';
      $suggested_raw = is_scalar($raw) ? (string) $raw : (string) json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $prepared['s' . $delta] = [
        'field' => $field,
        'label' => (string) ($suggestion['label'] ?? $field),
        'current' => $current,
        'suggested_display' => $this->displayValue($raw),
        'suggested_raw' => $suggested_raw,
        'kind' => $this->valueKind($suggested_raw),
        'reason' => (string) ($suggestion['reason'] ?? ''),
      ];
    }
    return $prepared;
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
   * break), HTML values are not editable here at all — apply, then refine
   * in the content editor. Only plain text gets a free-form textarea.
   *
   * @param array{suggested_raw: string, kind: string} $s
   *   One prepared suggestion.
   *
   * @return array<string, mixed>
   *   Render/form array for the edit control.
   */
  private function buildEditControl(array $s): array {
    if ($s['kind'] === 'html') {
      return [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('This suggestion contains formatting and cannot be edited here. Apply it, then refine the text in the content editor.'),
        '#attributes' => ['style' => 'font-size: 0.85em; color: var(--gin-color-text-light, #55565b); margin: 0.5em 0 0;'],
      ];
    }
    $edit = [
      '#type' => 'details',
      '#title' => $this->t('Edit suggestion'),
      '#open' => FALSE,
    ];
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
    $line = 'padding: 0.25em 0.5em; color: #1f2328; border-radius: ';
    if ($current === $suggested) {
      return ['#plain_text' => $suggested];
    }
    if ($current === '') {
      // Markup::create() instead of #markup: Xss::filterAdmin() would strip
      // the inline styles. Safe — all text is escaped above.
      return [
        '#markup' => Markup::create('<div style="background: #e6ffec; ' . $line . '4px;">+ '
          . $this->escapeText($suggested) . '</div>'),
      ];
    }
    $diff = new WordLevelDiff([$this->escapeText($current)], [$this->escapeText($suggested)]);
    $del = str_replace(
      '<span class="diffchange">',
      '<span style="background: #ffb3ad; text-decoration: line-through;">',
      implode('<br>', $diff->orig()),
    );
    $ins = str_replace(
      '<span class="diffchange">',
      '<span style="background: #abf2bc;">',
      implode('<br>', $diff->closing()),
    );
    return [
      '#markup' => Markup::create('<div style="background: #ffebe9; ' . $line . '4px 4px 0 0;">− ' . $del . '</div>'
        . '<div style="background: #e6ffec; ' . $line . '0 0 4px 4px;">+ ' . $ins . '</div>'),
    ];
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
      // suggestions have no edit control and always apply as suggested.
      $suggested_raw = is_scalar($suggestion['suggested'])
        ? (string) $suggestion['suggested']
        : (string) json_encode($suggestion['suggested'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
      elseif (trim((string) ($edit['value'] ?? '')) !== '') {
        $value = trim((string) $edit['value']);
      }
      if ($this->applyToNode($node, (string) $suggestion['field'], $value, (string) ($suggestion['current'] ?? ''))) {
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
    $form_state->setRebuild();
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
