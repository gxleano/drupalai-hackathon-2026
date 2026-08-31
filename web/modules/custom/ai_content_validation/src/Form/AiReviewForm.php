<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Diff\WordLevelDiff;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Render\Markup;
use Drupal\ai_content_validation\ContentHasher;
use Drupal\ai_content_validation\ValidatedFields;
use Drupal\ai_content_validation\ValidationScorer;
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
   * The ten EU content guidelines, keyed by their number in the report.
   *
   * The model returns `scores` keyed by these numbers; both the improver
   * prompt and the node form's pass popover name them from here.
   */
  public const GUIDELINES = [
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

  /**
   * One-line description of each guideline, keyed as GUIDELINES.
   *
   * Shown under the guideline name in the node form's field report, so an
   * editor reading a verdict knows what was actually checked.
   */
  public const GUIDELINE_DESCRIPTIONS = [
    1 => 'Claims are factually correct and backed by verifiable evidence.',
    2 => 'Written in plain language a non-expert can follow.',
    3 => 'Neutral tone, free of promotional or one-sided wording.',
    4 => 'Sources are named and linked so a reader can check them.',
    5 => 'Consistent with current EU law and published policy.',
    6 => 'Matches the needs and context of its intended readers.',
    7 => 'Logical structure, with headings that guide the reader.',
    8 => 'Gives the context a reader needs, with no critical gaps.',
    9 => 'Inclusive, respectful language free of stereotypes.',
    10 => 'Tells the reader what they can actually do next.',
  ];

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
    // Only during an AJAX rebuild: the AJAX response replaces just this
    // wrapper, so messages must render inside the form to be seen at all.
    // On a regular page load the theme's messages region already renders
    // the queue — including the element here too made every message
    // appear twice (both placeholders resolve to the same rendered set).
    if ($form_state->isRebuilding()) {
      $form['messages'] = [
        '#type' => 'status_messages',
        '#weight' => -100,
      ];
    }

    // This page is a read-only summary: validations run automatically on
    // node save, and fixes are applied from the node edit form. Nothing
    // here starts a model run or changes content.
    $latest_report = $this->latestReport($node);
    $report_parsed = $latest_report === NULL
      ? NULL
      : $this->parseResult((string) ($latest_report->get('field_validation_result')->value ?? ''));
    $revision_match = $this->reportIsCurrent($node, $latest_report, $report_parsed);

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
        $this->t('These results are from a previous version. Saving the content runs the validation again.'),
      ],
      default => [
        $this->t('Not validated yet'),
        $this->t('AI validation checks this content against the 10 EU content guidelines (accuracy, clarity, neutrality, completeness, …) each time it is saved, and its findings and fixes appear on the edit form.'),
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
          // The per-guideline pass count is deliberately NOT repeated here:
          // the guideline results card below is its single home.
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
    // Read-only page: the only action is going where the work happens.
    $form['hero']['actions']['edit'] = [
      '#type' => 'link',
      '#title' => $this->t('Edit content'),
      '#url' => $node->toUrl('edit-form'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];
    $form['hero']['actions']['hint'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['ai-review-hint']],
      '#value' => $this->t('Validation runs automatically when the content is saved; AI fixes are applied on the edit form.'),
    ];

    // ---- Validation results ------------------------------------------------
    // Two columns, each fact rendered exactly once: the ten guideline
    // verdicts on the left (the numbers the 0-100 score derives from), the
    // per-field findings and the overall assessment on the right.
    $form['report'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-review-grid']],
      'main' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-review-grid__main']],
        'guidelines' => $this->buildGuidelineResults($scores_map, $latest_report),
      ],
      'aside' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-review-grid__aside']],
        'field_findings' => $this->buildFieldFindings(
          $node,
          $report_parsed,
          $latest_report,
          NULL,
          [],
          $this->fieldDecisions($node),
        ),
        'overall_summary' => $this->buildOverallSummary($report_parsed, $latest_report),
      ],
    ];

    $form['override_audit'] = $this->buildOverrideAudit($node);

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
   * Collects the newest pending improve item's suggestions per field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being reviewed.
   *
   * @return array<string, array{id: int, suggestion: array<string, mixed>}>
   *   Prepared suggestions keyed by field name; empty when nothing is
   *   pending.
   */
  public function pendingSuggestions(NodeInterface $node): array {
    $item = $this->latestPendingImprove($node);
    if ($item === NULL) {
      return [];
    }
    $parsed = $this->parseResult((string) ($item->get('field_validation_result')->value ?? ''));
    $suggestions = is_array($parsed['suggestions'] ?? NULL) ? $parsed['suggestions'] : [];
    $applied = is_array($parsed['applied_fields'] ?? NULL) ? $parsed['applied_fields'] : [];
    $map = [];
    foreach ($this->prepareSuggestions($suggestions, $node) as $prepared) {
      // An applied suggestion is done — offering its panel again would
      // invite applying the same change twice.
      if (in_array($prepared['field'], $applied, TRUE)) {
        continue;
      }
      $map[$prepared['field']] = ['id' => (int) $item->id(), 'suggestion' => $prepared];
    }
    return $map;
  }

  /**
   * Loads the newest pending improve item for the node, if any.
   */
  private function latestPendingImprove(NodeInterface $node): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('ai_content_validation_item');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_content_revision.target_id', $node->id())
      ->condition('field_flowdrop_workflow', 'content_improve')
      ->condition('field_validation_status', 'pending')
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();
    $item = $ids ? $storage->load(reset($ids)) : NULL;
    if (!$item instanceof ContentEntityInterface) {
      return NULL;
    }
    // Self-heal items stuck in pending by the old closing logic (every
    // suggestion decided, yet the status never flipped): close them here
    // so they stop rendering as an open review session.
    $parsed = $this->parseResult((string) ($item->get('field_validation_result')->value ?? '')) ?? [];
    $applied = is_array($parsed['applied_fields'] ?? NULL) ? $parsed['applied_fields'] : [];
    $unresolved = array_filter(
      is_array($parsed['suggestions'] ?? NULL) ? $parsed['suggestions'] : [],
      fn ($s) => !is_array($s) || !in_array($s['field'] ?? NULL, $applied, TRUE),
    );
    if ($unresolved === [] && ($applied !== [] || ($parsed['ignored_suggestions'] ?? []) !== [])) {
      // This runs during form BUILD, so a plain GET must stay read-only:
      // the item still reads as closed (return NULL), but the repair is
      // only persisted on an unsafe request — two concurrent page loads
      // must never race the same save.
      if ($this->getRequest()->isMethodSafe()) {
        return NULL;
      }
      if ($applied !== []) {
        $parsed['done_by'] = (int) $this->currentUser()->id();
        $parsed['done_at'] = $this->time->getRequestTime();
        $item->set('field_validation_result', json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      }
      $item->set('field_validation_status', $applied !== [] ? 'done' : 'ignored')->save();
      return NULL;
    }
    return $item;
  }

  /**
   * Reads the per-field accept/reject decisions of the open review session.
   *
   * While an improve item is still pending, fields already decided carry
   * their outcome here so the findings list can badge them Accepted or
   * Rejected instead of leaving the stale Review/Passed verdict. Once the
   * session closes, the automatic re-validation supplies fresh verdicts
   * and this map is empty again.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being reviewed.
   *
   * @return array<string, string>
   *   Field name to 'accepted' or 'rejected'.
   */
  public function fieldDecisions(NodeInterface $node): array {
    $item = $this->latestPendingImprove($node);
    if ($item === NULL) {
      return [];
    }
    $parsed = $this->parseResult((string) ($item->get('field_validation_result')->value ?? ''));
    $decisions = [];
    foreach ($parsed['ignored_suggestions'] ?? [] as $suggestion) {
      if (is_array($suggestion) && is_string($suggestion['field'] ?? NULL)) {
        $decisions[$suggestion['field']] = 'rejected';
      }
    }
    foreach ($parsed['applied_fields'] ?? [] as $field) {
      if (is_string($field)) {
        $decisions[$field] = 'accepted';
      }
    }
    return $decisions;
  }

  /**
   * Submit handler: reopens a finding that was marked as OK.
   *
   * Removes the override from the current report, so the flag — and its
   * Fix with AI / Mark as OK actions — come back. Overrides recorded on
   * earlier (superseded) reports are untouched, so the audit trail of the
   * original decision survives the reopen.
   */
  public function reopenFieldFinding(array &$form, FormStateInterface $form_state): void {
    [, $report_id, $field] = explode(':', $form_state->getTriggeringElement()['#name'], 3);
    $report = $this->loadOwnedValidation((int) $report_id, $form_state);
    if ($report === NULL) {
      return;
    }
    $parsed = $this->parseResult((string) $report->get('field_validation_result')->value) ?? [];
    if (!isset($parsed['editor_overrides'][$field])) {
      return;
    }
    unset($parsed['editor_overrides'][$field]);
    if ($parsed['editor_overrides'] === []) {
      unset($parsed['editor_overrides']);
    }
    $report->set('field_validation_result', json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $report->save();
    $this->messenger()->addStatus($this->t('The finding on %field was reopened.', ['%field' => $field]));
  }

  /**
   * Builds the audit list of "Marked as OK" editor decisions.
   *
   * Every override ever recorded for the node is listed — carried-forward
   * copies on newer reports are deduplicated by field + decision time, so
   * one click shows up once. Superseded reports keep their overrides, so
   * the trail survives re-validation: this answers "who accepted a finding
   * that maybe should not have been accepted", with the finding itself.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being reviewed.
   *
   * @return array<string, mixed>
   *   Render array, empty when no override was ever recorded.
   */
  private function buildOverrideAudit(NodeInterface $node): array {
    $storage = $this->entityTypeManager->getStorage('ai_content_validation_item');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_content_revision.target_id', $node->id())
      ->condition('field_flowdrop_workflow', self::REPORT_WORKFLOW)
      ->sort('created', 'DESC')
      ->range(0, 50)
      ->execute();
    $labels = ValidatedFields::labels($node);
    $items = $storage->loadMultiple($ids);
    $rows = [];
    // $ids is newest first, so the first report seen for a decision also
    // carries the freshest copy of its finding text.
    foreach ($ids as $id) {
      $item = $items[$id] ?? NULL;
      $parsed = $item === NULL ? NULL : $this->parseResult((string) ($item->get('field_validation_result')->value ?? ''));
      foreach ($this->editorOverrides($parsed) as $field => $override) {
        $key = $field . ':' . $override['at'];
        if (isset($rows[$key])) {
          continue;
        }
        $finding = $parsed['field_findings'][$field] ?? '';
        $rows[$key] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-review-finding']],
          'icon' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => '',
            '#attributes' => ['class' => ['ai-review-override__icon'], 'aria-hidden' => 'true'],
          ],
          'body' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['ai-review-finding__body']],
            'label' => [
              '#type' => 'html_tag',
              '#tag' => 'div',
              '#value' => $labels[$field] ?? Html::escape($field),
              '#attributes' => ['class' => ['ai-review-finding__label']],
            ],
            // The finding the editor accepted: untrusted model output,
            // escaped by the renderer.
            'finding' => !is_string($finding) || trim($finding) === '' ? [] : [
              '#type' => 'html_tag',
              '#tag' => 'p',
              '#attributes' => ['class' => ['ai-review-finding__text']],
              'value' => ['#plain_text' => trim($finding)],
            ],
          ],
          'badge' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->t('Marked as OK by @name — @date', [
              '@name' => $this->overrideName($override['uid']),
              '@date' => date('d M Y, H:i', $override['at']),
            ]),
            '#attributes' => ['class' => ['ai-review-badge', 'ai-review-badge--ok']],
          ],
        ];
      }
    }
    if ($rows === []) {
      return [];
    }
    // Newest decision first — the key ends in the decision timestamp, and
    // report order is not decision order once overrides carry forward.
    uksort($rows, static fn (string $a, string $b) => (int) substr($b, strrpos($b, ':') + 1) <=> (int) substr($a, strrpos($a, ':') + 1));
    $weight = 0;
    foreach ($rows as &$row) {
      $row['#weight'] = $weight++;
    }
    unset($row);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-review-overrides']],
      '#cache' => ['tags' => ['ai_content_validation_item_list']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Editor decisions (@count)', ['@count' => count($rows)]),
        '#attributes' => ['class' => ['ai-review-overrides__heading']],
      ],
      'hint' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('AI findings an editor accepted as-is, newest first. Each entry names who decided and what the AI had flagged.'),
        '#attributes' => ['class' => ['ai-review-hint']],
      ],
    ] + $rows;
  }

  /**
   * Loads a validation item, verifying it belongs to the form's node.
   *
   * Defense-in-depth for the id-parameterized submit handlers
   * (applyfieldsug:, ignorefieldsug:, markokfield:, improvefield:): the
   * ids come from button names this form itself rendered, so Form API's
   * triggering-element matching already blocks foreign ids — but the
   * security property must not rest on that detail alone.
   *
   * @param int $id
   *   The validation item id from the button name.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state carrying the node id under 'nid'.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The item, or NULL when it does not belong to this form's node.
   */
  private function loadOwnedValidation(int $id, FormStateInterface $form_state): ?ContentEntityInterface {
    $item = $this->entityTypeManager->getStorage('ai_content_validation_item')->load($id);
    $nid = (int) $form_state->get('nid');
    if (!$item instanceof ContentEntityInterface
      || $nid === 0
      || (int) ($item->get('field_content_revision')->target_id ?? 0) !== $nid
    ) {
      return NULL;
    }
    return $item;
  }

  /**
   * Reads the editor "Marked as OK" overrides from a parsed report.
   *
   * An override is a human decision that a flagged finding is acceptable
   * as-is. It lives on the report it was made against, so a fresh report
   * (new content, new verdicts) never inherits it.
   *
   * @param array<string, mixed>|null $result
   *   The parsed validation result.
   *
   * @return array<string, array{uid: int, at: int}>
   *   Overrides keyed by field name.
   */
  public function editorOverrides(?array $result): array {
    $overrides = [];
    foreach ((array) ($result['editor_overrides'] ?? []) as $field => $info) {
      if (is_string($field) && is_array($info) && is_numeric($info['uid'] ?? NULL)) {
        $overrides[$field] = [
          'uid' => (int) $info['uid'],
          'at' => (int) ($info['at'] ?? 0),
        ];
      }
    }
    return $overrides;
  }

  /**
   * Resolves a user id to a display name for override badges.
   *
   * The uid is stored (names can change); the name is resolved at render
   * time. A deleted account degrades to a generic label.
   */
  public function overrideName(int $uid): string {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    return $user === NULL ? (string) $this->t('an editor') : (string) $user->label();
  }

  /**
   * Submit handler: marks one flagged field's finding as OK.
   *
   * Records a human override on the report itself — the AI verdict and the
   * 0-100 score stay untouched; the field just stops asking for attention
   * until a new report supersedes this one.
   */
  public function markFieldOk(array &$form, FormStateInterface $form_state): void {
    [, $report_id, $field] = explode(':', $form_state->getTriggeringElement()['#name'], 3);
    $report = $this->loadOwnedValidation((int) $report_id, $form_state);
    if ($report === NULL) {
      return;
    }
    $parsed = $this->parseResult((string) $report->get('field_validation_result')->value) ?? [];
    $overrides = is_array($parsed['editor_overrides'] ?? NULL) ? $parsed['editor_overrides'] : [];
    $overrides[$field] = [
      'uid' => (int) $this->currentUser()->id(),
      'at' => $this->time->getRequestTime(),
    ];
    $parsed['editor_overrides'] = $overrides;
    $report->set('field_validation_result', json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $report->save();
    $this->messenger()->addStatus($this->t('The finding on %field was marked as OK.', ['%field' => $field]));
  }

  /**
   * Builds the inline suggestion panel shown under a field's finding row.
   *
   * @param int $validation_id
   *   The pending improve item the suggestion belongs to.
   * @param array{field: string, label: string, current: string, suggested_display: string, reason: string} $s
   *   One prepared suggestion.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The field's display label.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  public function buildInlineSuggestion(int $validation_id, array $s, $label): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-review-suggestion']],
      // Namespaced values: every panel's edit control submits under
      // ['inline_suggestion'][<field>] instead of colliding on 'edit'.
      '#tree' => TRUE,
      '#parents' => ['inline_suggestion', $s['field']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t('AI suggestion — waiting for your review'),
        '#attributes' => ['class' => ['ai-review-suggestion__heading']],
      ],
      'diff' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-review-suggestion__diff']],
        'value' => $this->diffMarkup($s['current'], $s['suggested_display']),
      ],
      'reason' => trim($s['reason']) === '' ? [] : [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['ai-review-suggestion__reason']],
        'value' => ['#plain_text' => $s['reason']],
      ],
      'edit' => $this->buildEditControl($s),
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-review-suggestion__actions']],
        // Deliberately NOT AJAX: a decision must land as a fresh full page
        // so only this field's state changes and every other panel comes
        // back from storage exactly as it is — the AJAX rebuild proved
        // unreliable for that (same-second changed-time comparisons).
        'apply' => [
          '#type' => 'submit',
          '#value' => $this->t('Accept change'),
          '#name' => 'applyfieldsug:' . $validation_id . ':' . $s['field'],
          '#submit' => ['::applyFieldSuggestion'],
          '#button_type' => 'primary',
          '#attributes' => ['class' => ['button--small']],
        ],
        'ignore' => [
          '#type' => 'submit',
          '#value' => $this->t('Reject change'),
          '#name' => 'ignorefieldsug:' . $validation_id . ':' . $s['field'],
          '#submit' => ['::ignoreFieldSuggestion'],
          '#attributes' => ['class' => ['button--small']],
        ],
        'hint' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('Edits under "Edit suggestion" replace the AI text when you accept.'),
          '#attributes' => ['class' => ['ai-review-hint']],
        ],
      ],
    ];
  }

  /**
   * Whether a report still describes the node's current content.
   *
   * Freshness is measured by the same content hash the run gate uses, so
   * the two can never disagree: a save that did not change any validated
   * value keeps its report (no model call, no "content has changed"
   * banner), and any real edit invalidates it immediately.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being viewed.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $report
   *   The report to test, or NULL.
   * @param array<string, mixed>|null $parsed
   *   The already-parsed result, when the caller has it.
   *
   * @return bool
   *   TRUE when the report describes the current content.
   */
  public function reportIsCurrent(NodeInterface $node, ?ContentEntityInterface $report, ?array $parsed = NULL): bool {
    if ($report === NULL) {
      return FALSE;
    }
    $parsed ??= $this->parseResult((string) ($report->get('field_validation_result')->value ?? ''));
    $hash = is_array($parsed) && is_string($parsed['content_hash'] ?? NULL) ? $parsed['content_hash'] : NULL;
    if ($hash !== NULL) {
      return $hash === ContentHasher::hash($node);
    }
    // Reports written before the hash existed fall back to the old
    // revision-and-timestamp comparison.
    return (int) ($report->get('field_content_revision')->target_revision_id ?? 0) === (int) $node->getRevisionId()
      && (int) $node->getChangedTime() <= (int) $report->get('created')->value;
  }

  /**
   * Applies one field's pending suggestion from the inline panel.
   *
   * The suggestion is applied unedited (editing lives in the pending
   * item's table). The item goes done once every suggested field has been
   * applied; until then it stays pending with the applied fields recorded.
   */
  public function applyFieldSuggestion(array &$form, FormStateInterface $form_state): void {
    [, $validation_id, $field] = explode(':', $form_state->getTriggeringElement()['#name'], 3);
    $validation = $this->loadOwnedValidation((int) $validation_id, $form_state);
    $node = $this->loadNode($form_state);
    if ($validation === NULL || $node === NULL) {
      return;
    }
    $parsed = $this->parseResult((string) $validation->get('field_validation_result')->value) ?? [];
    $suggestions = is_array($parsed['suggestions'] ?? NULL) ? $parsed['suggestions'] : [];
    $suggestion = NULL;
    foreach ($suggestions as $candidate) {
      if (is_array($candidate) && ($candidate['field'] ?? NULL) === $field && isset($candidate['suggested'])) {
        $suggestion = $candidate;
        break;
      }
    }
    if ($suggestion !== NULL && $node !== NULL && ValidatedFields::isTagsField($node, $field)) {
      // Same normalization as prepareSuggestions(): a subset suggestion
      // ("remove tag X") keeps every stored tag the model never mentioned.
      $suggestion['suggested'] = implode(', ', ValidatedFields::mergeTagNames(
        $this->currentNodeValue($node, $field),
        $this->rawValue($suggestion['suggested']),
        $this->rawValue($suggestion['current'] ?? ''),
      ));
    }
    $edit = $form_state->getValue(['inline_suggestion', $field, 'edit'], []);
    $value = $suggestion === NULL
      ? ''
      : $this->editedValue(is_array($edit) ? $edit : [], $this->rawValue($suggestion['suggested']));
    if ($suggestion === NULL || ($this->plainText($value) === '' && !ValidatedFields::isTagsField($node, $field))) {
      $this->messenger()->addWarning($this->t('No applicable suggestion for field %field.', ['%field' => $field]));
      return;
    }
    // On the node edit form the suggestion is STAGED into the field's own
    // widget instead of saved: the editor sees it sitting in the form like
    // a manual edit (gray pen dot) and the normal Save persists it — plus
    // any other unsaved edits — and re-validates. The review page has no
    // widgets to stage into, and a media fix writes to the media entity
    // rather than this form, so both keep the direct save.
    $staged = $form_state->getFormObject() instanceof EntityFormInterface
      && $this->stageIntoForm($form_state, $node, $field, $value);
    if (!$staged && !$this->applyToNode($node, $field, $value, $this->rawValue($suggestion['current'] ?? ''))) {
      $this->messenger()->addWarning($this->t('Could not apply suggestion for field %field.', ['%field' => $field]));
      return;
    }
    // Store what was ACTUALLY applied, so the done item's diff shows the
    // editor's text, not the AI's; keep the AI original alongside.
    if ($value !== $this->rawValue($suggestion['suggested'])) {
      foreach ($suggestions as $delta => $candidate) {
        if (is_array($candidate) && ($candidate['field'] ?? NULL) === $field) {
          $parsed['suggestions'][$delta]['ai_suggested'] = $candidate['suggested'];
          $parsed['suggestions'][$delta]['suggested'] = $value;
          break;
        }
      }
    }
    if (!$staged) {
      $node->setNewRevision(TRUE);
      if ($node instanceof RevisionLogInterface) {
        $node->setRevisionLogMessage('Applied AI suggestion (' . ($suggestion['label'] ?? $field) . ') from validation #' . $validation_id);
        $node->setRevisionUserId((int) $this->currentUser()->id());
        $node->setRevisionCreationTime($this->time->getRequestTime());
      }
      $node->save();
    }

    $applied_fields = is_array($parsed['applied_fields'] ?? NULL) ? $parsed['applied_fields'] : [];
    $applied_fields[] = $field;
    $parsed['applied_fields'] = array_values(array_unique($applied_fields));
    $all_fields = array_values(array_filter(array_map(
      fn ($sug) => is_array($sug) ? ($sug['field'] ?? NULL) : NULL,
      $suggestions,
    )));
    $done = array_diff($all_fields, $parsed['applied_fields']) === [];
    if ($done) {
      $parsed['done_by'] = (int) $this->currentUser()->id();
      $parsed['done_at'] = $this->time->getRequestTime();
      $validation->set('field_validation_status', 'done');
    }
    $validation->set('field_validation_result', json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $validation->save();
    if ($staged) {
      // The rebuilt form must show the pen dot even when this was the
      // item's last suggestion (the item just left pending, so the
      // decision map no longer speaks for it): remember it on the form
      // state, which survives into this rebuild and no further — a fresh
      // page load also loses the staged widget value itself.
      $staged_fields = $form_state->get('acv_staged') ?? [];
      $staged_fields[] = $field;
      $form_state->set('acv_staged', $staged_fields);
      $this->messenger()->addStatus($this->t('Put the suggestion into %field — save the content to keep it.', ['%field' => $suggestion['label'] ?? $field]));
      // The staged value only lives in the rebuilt form; saving the node
      // runs the validation, so no separate re-validation is started.
      $form_state->setRebuild();
      return;
    }
    $this->messenger()->addStatus($this->t('Applied the suggestion to %field as a new revision.', ['%field' => $suggestion['label'] ?? $field]));
    // One re-validation per review session, when the last suggestion is
    // resolved — not one 30-second model call per field.
    if ($done) {
      $this->revalidateAfterApply($node);
    }
    // No setRebuild(): the default redirect reloads the page from scratch,
    // so this field shows its new state and the rest rebuilds from storage.
  }

  /**
   * Writes a suggested value into the node form's own widgets, unsaved.
   *
   * The form rebuild renders widgets from user input, so overriding the
   * field's raw input is what makes the suggestion appear in the widget
   * exactly as if the editor had typed it. Nothing touches storage — the
   * editor reviews it in place and the normal Save persists it.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The node form's state.
   * @param \Drupal\node\NodeInterface $node
   *   The node being edited, source of the field definitions.
   * @param string $field
   *   The field machine name.
   * @param string $value
   *   The suggested value (raw text, tag names, or metatag JSON).
   *
   * @return bool
   *   TRUE when the value was staged; FALSE for a widget shape this does
   *   not know, in which case the caller falls back to the direct save.
   */
  private function stageIntoForm(FormStateInterface $form_state, NodeInterface $node, string $field, string $value): bool {
    $input = &$form_state->getUserInput();
    if (ValidatedFields::isMediaField($node, $field)) {
      // The alt lives on the referenced media entity — no widget on this
      // form holds it — so it is staged into the hidden acv_staged_alt
      // carrier and written to the media entity by the node form's Save
      // (FormHooks::applyStagedAlts()), never before.
      $alt = trim($this->plainText($value));
      if ($alt === '') {
        return FALSE;
      }
      $staged = json_decode((string) ($input['acv_staged_alt'] ?? ''), TRUE);
      $staged = is_array($staged) ? $staged : [];
      $staged[$field] = $alt;
      $input['acv_staged_alt'] = json_encode($staged);
      return TRUE;
    }
    if (ValidatedFields::isTagsField($node, $field)) {
      // The tags autocomplete takes a comma-separated string; plain names
      // match existing terms by title and auto-create the rest, the same
      // as typing them. The input shape depends on the widget (a plain
      // string, or nested under target_id), so the staged value mirrors
      // whatever shape this POST already carries.
      $names = implode(', ', ValidatedFields::parseTagNames($value));
      $existing = $input[$field] ?? NULL;
      if (is_array($existing) && array_key_exists('target_id', $existing)) {
        $input[$field]['target_id'] = $names;
        return TRUE;
      }
      if ($existing === NULL || is_scalar($existing)) {
        $input[$field] = $names;
        return TRUE;
      }
      return FALSE;
    }
    if ($field !== 'title' && $node->getFieldDefinition($field)?->getType() === 'metatag') {
      $decoded = json_decode($value, TRUE);
      if (!is_array($decoded) || !is_array($input[$field][0] ?? NULL)) {
        return FALSE;
      }
      // The metatag widget nests one input per tag under its group
      // ([0][basic][title], …): fill every input whose key the
      // suggestion carries and keep the rest as they are.
      foreach ($input[$field][0] as $group => $tags) {
        if (!is_array($tags)) {
          continue;
        }
        foreach (array_keys($tags) as $tag) {
          if (is_scalar($decoded[$tag] ?? NULL)) {
            $input[$field][0][$group][$tag] = (string) $decoded[$tag];
          }
        }
      }
      return TRUE;
    }
    // Title and every plain "value" widget (string, text, text_long,
    // text_with_summary) post as FIELD[0][value]; the text format input
    // next to it is left alone.
    if (is_array($input[$field][0] ?? NULL) && array_key_exists('value', $input[$field][0])) {
      $input[$field][0]['value'] = $value;
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Dismisses one field's pending suggestion from the inline panel.
   *
   * The suggestion is removed from the item (kept under
   * ignored_suggestions for inspection). When nothing is left to review
   * the item leaves the pending state: done if something was applied
   * earlier, ignored otherwise.
   */
  public function ignoreFieldSuggestion(array &$form, FormStateInterface $form_state): void {
    [, $validation_id, $field] = explode(':', $form_state->getTriggeringElement()['#name'], 3);
    $validation = $this->loadOwnedValidation((int) $validation_id, $form_state);
    if ($validation === NULL) {
      return;
    }
    $parsed = $this->parseResult((string) $validation->get('field_validation_result')->value) ?? [];
    $suggestions = is_array($parsed['suggestions'] ?? NULL) ? $parsed['suggestions'] : [];
    $kept = [];
    $ignored = is_array($parsed['ignored_suggestions'] ?? NULL) ? $parsed['ignored_suggestions'] : [];
    $label = $field;
    foreach ($suggestions as $suggestion) {
      if (is_array($suggestion) && ($suggestion['field'] ?? NULL) === $field) {
        $ignored[] = $suggestion;
        $label = $suggestion['label'] ?? $field;
        continue;
      }
      $kept[] = $suggestion;
    }
    $parsed['suggestions'] = $kept;
    $parsed['ignored_suggestions'] = $ignored;
    $closing_with_applies = FALSE;
    // An applied suggestion stays in the list (only recorded in
    // applied_fields), so "nothing left to review" means every kept
    // suggestion was already applied — not that the list is empty.
    // Without this, rejecting LAST left the item pending forever.
    $applied = is_array($parsed['applied_fields'] ?? NULL) ? $parsed['applied_fields'] : [];
    $unresolved = array_filter(
      $kept,
      fn ($s) => !is_array($s) || !in_array($s['field'] ?? NULL, $applied, TRUE),
    );
    if ($unresolved === []) {
      $closing_with_applies = $applied !== [];
      if ($closing_with_applies) {
        $parsed['done_by'] = (int) $this->currentUser()->id();
        $parsed['done_at'] = $this->time->getRequestTime();
      }
      $validation->set('field_validation_status', $closing_with_applies ? 'done' : 'ignored');
    }
    $validation->set('field_validation_result', json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $validation->save();
    $this->messenger()->addStatus($this->t('Dismissed the suggestion for %field. The content was not changed.', ['%field' => $label]));
    // The session ended with earlier applied changes still unvalidated —
    // run the one closing validation now.
    if ($closing_with_applies) {
      $node = $this->loadNode($form_state);
      if ($node !== NULL) {
        $this->revalidateAfterApply($node);
      }
    }
  }

  /**
   * Re-validates the changed content right after suggestions were applied.
   *
   * Without this, the page comes back with a stale report: the remaining
   * Review fields lose their Fix with AI buttons (revision mismatch) and
   * the header warns about a score from a previous version. One immediate
   * manual-stamped, auto-accepted run keeps everything current — fields
   * that still fail stay on Review with their button, the fixed field
   * turns Passed on a fresh verdict, and the score updates.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node that was just changed.
   */
  private function revalidateAfterApply(NodeInterface $node): void {
    $operations = $this->configFactory()->get('flowdrop_node_session.settings')->get('entity_operations') ?: [];
    if ($operations === []) {
      return;
    }
    // Quiet: "Applied the suggestion…" plus the refreshed page already
    // tell the story — a second "finished" status would be noise.
    $this->runAndAccept($node, $operations[0]['workflow_id'], TRUE);
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
   * The validator reports one finding per validated field. Only the
   * fields ValidatedFields resolves for the node are rendered, in a fixed
   * order, so an unexpected extra key in the model's response can never
   * inject a section of its own. Reports written before per-field
   * findings existed simply render nothing here.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node whose fields are being reported on.
   * @param array<string, mixed>|null $result
   *   The decoded validation result, or NULL when there is none.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $report
   *   The report the findings came from, for cache metadata.
   * @param int|null $improve_report_id
   *   When set, Review rows get a "Fix with AI" button that runs the
   *   improve workflow scoped to that single field of this report.
   * @param array<string, array{id: int, suggestion: array<string, mixed>}> $pending
   *   Pending improve suggestions keyed by field name; each is rendered
   *   inline right under its field's row so the editor sees the proposed
   *   change without scrolling to the pending list.
   * @param array<string, string> $decisions
   *   Per-field accept/reject decisions of the open review session; a
   *   decided field's badge shows the outcome instead of the report's
   *   now-stale Review/Passed verdict.
   *
   * @return array<string, mixed>
   *   Render array, empty when there are no per-field findings.
   */
  private function buildFieldFindings(NodeInterface $node, ?array $result, ?ContentEntityInterface $report, ?int $improve_report_id = NULL, array $pending = [], array $decisions = []): array {
    // Derived from the bundle's field definitions, so a field added to the
    // content type later gets a findings row without a code change here.
    $labels = ValidatedFields::labels($node);
    $findings = is_array($result['field_findings'] ?? NULL) ? $result['field_findings'] : [];
    $verdicts = is_array($result['field_verdicts'] ?? NULL) ? $result['field_verdicts'] : [];
    $overrides = $this->editorOverrides($result);
    // A suggestion or decision on a field outside the fixed list still
    // needs its own row — the inline panel is the ONLY review path (the
    // history never shows the bulk table for the newest pending item).
    // The label comes from the suggestion itself; model-supplied, so it
    // is escaped here before it lands in an html_tag #value.
    foreach ($pending as $field => $info) {
      $labels[$field] ??= Html::escape((string) ($info['suggestion']['label'] ?? $field));
    }
    foreach ($decisions as $field => $outcome) {
      $labels[$field] ??= Html::escape($field);
    }
    if ($findings === [] && $pending === [] && $decisions === []) {
      return [];
    }

    $rows = [];
    $all_passed = TRUE;
    foreach ($labels as $field => $label) {
      $text = $findings[$field] ?? NULL;
      $text = is_string($text) ? trim($text) : '';
      // An affirmative finding ("Accurate and specific.") is still a
      // finding: only an absent or empty value hides the row — unless the
      // field carries a pending suggestion or a session decision, which
      // must render inline here (otherwise the pending item falls back to
      // the bulk table in the history, duplicating the review).
      if ($text === '' && !isset($pending[$field]) && !isset($decisions[$field])) {
        continue;
      }
      // The report's explicit per-field verdict is authoritative: the
      // model states "pass" or "review" per field, so the badge never
      // depends on parsing prose. Reports from before field_verdicts
      // existed fall back to the guideline-number heuristic, and a
      // findingless row only exists because of a suggestion — by
      // definition something to review.
      $verdict = $verdicts[$field] ?? NULL;
      $issue = match (TRUE) {
        is_string($verdict) => strtolower(trim($verdict)) !== 'pass',
        $text === '' => TRUE,
        default => (bool) preg_match('/guidelines?\\s*(?:no\\.?\\s*|#\\s*)?\\d/i', $text),
      };
      $decision = $decisions[$field] ?? NULL;
      // A human override quiets the finding: the row stops asking for
      // attention, but its badge names who decided that — never the green
      // "Passed", which is the AI's verdict alone.
      $override = ($decision === NULL && isset($overrides[$field])) ? $overrides[$field] : NULL;
      $issue = $issue && $override === NULL;
      $all_passed = $all_passed && !$issue;
      [$badge_text, $badge_class] = match (TRUE) {
        $decision === 'accepted' => [$this->t('Change accepted'), 'ai-review-badge--passed'],
        $decision === 'rejected' => [$this->t('Change rejected'), 'ai-review-badge--neutral'],
        $override !== NULL => [
          $this->t('Marked as OK by @name', ['@name' => $this->overrideName($override['uid'])]),
          'ai-review-badge--ok',
        ],
        $issue => [$this->t('Review'), 'ai-review-badge--review'],
        default => [$this->t('Passed'), 'ai-review-badge--passed'],
      };
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
          'text' => $text === '' ? [] : [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#attributes' => ['class' => ['ai-review-finding__text']],
            'value' => ['#plain_text' => $text],
          ],
        ],
        'badge' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $badge_text,
          '#attributes' => [
            'class' => ['ai-review-badge', $badge_class],
          ],
        ],
        // The spark is a separate masked span overlaid on the button:
        // <input> cannot carry pseudo-elements, and a background image
        // cannot follow the text color on hover.
        'fix' => !$issue || $improve_report_id === NULL || isset($pending[$field]) || $decision !== NULL || !ValidatedFields::isFixable($node, $field) ? [] : [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-review-fix-wrap']],
          'button' => [
            '#type' => 'submit',
            '#value' => $this->t('Fix with AI'),
            '#name' => 'improvefield:' . $improve_report_id . ':' . $field,
            '#submit' => ['::improveField'],
            '#attributes' => ['class' => ['button--small', 'ai-review-finding__fix']],
            '#ajax' => $this->ajaxAction($this->aiProgressMessage($this->t('Rewriting the @field based on its findings… (~1 min)', ['@field' => $label]))),
          ],
          'icon' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => '',
            '#attributes' => ['class' => ['ai-review-fix-icon'], 'aria-hidden' => 'true'],
          ],
        ],
        'suggestion' => !isset($pending[$field]) ? [] : $this->buildInlineSuggestion($pending[$field]['id'], $pending[$field]['suggestion'], $label),
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
    // Confirmed internal contradictions are the report's sharpest warnings,
    // so they render as bullets above the closing assessment.
    $contradictions = array_values(array_filter(
      is_array($result['contradictions'] ?? NULL) ? $result['contradictions'] : [],
      fn ($c) => is_string($c) && trim($c) !== '',
    ));
    if ($summary === '' && $contradictions === []) {
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
        'contradictions' => $contradictions === [] ? [] : [
          '#theme' => 'item_list',
          '#items' => array_map(fn (string $c) => ['#plain_text' => trim($c)], $contradictions),
          '#attributes' => ['class' => ['ai-review-summary__contradictions']],
        ],
        'text' => $summary === '' ? [] : [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['ai-review-summary__text']],
          'value' => ['#plain_text' => $summary],
        ],
      ],
    ];
  }

  /**
   * Builds the per-guideline verdict card.
   *
   * One row per EU guideline with its points and verdict badge, read from
   * the report's `scores` map — the same verdicts the 0-100 total derives
   * from, so this card and the hero score can never disagree. NULL means
   * the current report carries no usable verdict map (stale, or predating
   * verdicts) and the card is simply absent.
   *
   * @param array<string, mixed>|null $scores
   *   The verdict map keyed "1"-"10", or NULL.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $report
   *   The report the verdicts came from, for cache metadata.
   *
   * @return array<string, mixed>
   *   Render array, empty when there are no verdicts to show.
   */
  private function buildGuidelineResults(?array $scores, ?ContentEntityInterface $report): array {
    if ($scores === NULL) {
      return [];
    }
    $rows = [];
    $passed = 0;
    foreach (self::GUIDELINES as $number => $name) {
      $verdict = $scores[(string) $number] ?? NULL;
      if ($verdict === NULL) {
        continue;
      }
      [$points, $key, $label] = $this->verdictDisplay($verdict);
      $passed += $key === 'pass' ? 1 : 0;
      $rows['guideline_' . $number] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-review-guideline', 'ai-review-guideline--' . $key]],
        'body' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-review-guideline__body']],
          'name' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#value' => $name,
            '#attributes' => ['class' => ['ai-review-guideline__name']],
          ],
          'description' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => self::GUIDELINE_DESCRIPTIONS[$number],
            '#attributes' => ['class' => ['ai-review-guideline__description']],
          ],
        ],
        'points' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('@points<span>/10</span>', ['@points' => $points]),
          '#attributes' => ['class' => ['ai-review-guideline__points']],
        ],
        'badge' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $label,
          '#attributes' => ['class' => ['ai-review-badge', 'ai-review-verdict--' . $key]],
        ],
      ];
    }
    if ($rows === []) {
      return [];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-review-guidelines']],
      '#cache' => [
        'tags' => $report === NULL ? [] : $report->getCacheTags(),
      ],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-review-guidelines__header']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Guideline results'),
          '#attributes' => ['class' => ['ai-review-guidelines__heading']],
        ],
        'count' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('@passed of @total passed', ['@passed' => $passed, '@total' => count($rows)]),
          '#attributes' => [
            'class' => [
              'ai-review-guidelines__count',
              $passed === count($rows) ? 'ai-review-guidelines__count--all' : 'ai-review-guidelines__count--partial',
            ],
          ],
        ],
      ],
    ] + $rows;
  }

  /**
   * Maps one stored guideline verdict to its display parts.
   *
   * Verdicts are stored either as strings (pass/minor/major/fail) or as
   * the already-mapped points; both normalize through
   * ValidationScorer::POINTS so the numbers always match the score maths.
   *
   * @param mixed $verdict
   *   The stored verdict value.
   *
   * @return array{0: int, 1: string, 2: \Drupal\Core\StringTranslation\TranslatableMarkup}
   *   Points, verdict key and badge label.
   */
  private function verdictDisplay(mixed $verdict): array {
    $key = is_numeric($verdict)
      ? match (TRUE) {
      (int) $verdict >= 10 => 'pass',
        (int) $verdict >= 8 => 'minor',
        (int) $verdict >= 4 => 'major',
        default => 'fail',
      }
    : strtolower(trim((string) $verdict));
    if (!isset(ValidationScorer::POINTS[$key])) {
      // An unrecognized verdict string still deserves a visible row; treat
      // it as needing attention rather than hiding or over-praising it.
      $key = 'major';
    }
    $points = is_numeric($verdict) ? max(0, min(10, (int) $verdict)) : ValidationScorer::POINTS[$key];
    $label = match ($key) {
      'pass' => $this->t('Pass'),
      'minor' => $this->t('Minor issues'),
      'major' => $this->t('Needs attention'),
      'fail' => $this->t('Failed'),
    };
    return [$points, $key, $label];
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
  public function acceptedScore(NodeInterface $node): array {
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
   * this decides whether the per-field Fix with AI buttons exist at all.
   */
  public function latestReport(NodeInterface $node): ?ContentEntityInterface {
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
  public function cachedReport(NodeInterface $node, string $workflow_id): ?ContentEntityInterface {
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
   * @param bool $quiet
   *   When TRUE the routine "finished" status message is suppressed —
   *   warnings and errors still surface. Used by the post-apply
   *   re-validation, whose outcome the refreshed page already shows.
   */
  public function runAndAccept(NodeInterface $node, string $workflow_id, bool $quiet = FALSE): void {
    [$previous_score] = $this->acceptedScore($node);
    $this->startRun($node, $workflow_id, 'Run', $quiet);
    // Stamp the report this run just produced as an explicit manual run:
    // that flag is what unlocks the per-field Fix with AI buttons. Reports from
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
      // A fresh validation of the CURRENT content is the truth about the
      // current content, so it is always accepted — the editor never has
      // to click Accept score. A drop against the previously accepted
      // score is still called out loudly (typical after a manual edit
      // made things worse); the non-regression duty for AI improvements
      // lives in the improve gate, not here.
      if ((string) $report->get('field_validation_status')->value === 'pending') {
        $this->acceptValidation($report);
        if ($new_score !== NULL && $previous_score !== NULL && $new_score < $previous_score) {
          $this->messenger()->addWarning($this->t('The new score is @new/100, down from @old/100 — the current version of the content scores lower than the previously validated one.', [
            '@new' => $new_score,
            '@old' => $previous_score,
          ]));
        }
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
   * @param \Drupal\node\NodeInterface $node
   *   The node being reviewed.
   * @param int|null $skip_id
   *   A validation item to omit because its suggestions render inline
   *   under the field rows above.
   *
   * @return array<string, mixed>
   *   Render/form array of validations, newest first.
   */
  private function buildValidations(NodeInterface $node, ?int $skip_id = NULL): array {
    $storage = $this->entityTypeManager->getStorage('ai_content_validation_item');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_content_revision.target_id', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 30)
      ->execute();

    // Collapsed by default: the history is an audit trail — everything an
    // editor acts on already renders in the cards above.
    $build = [
      '#tree' => TRUE,
      '#type' => 'details',
      '#title' => $this->t('Validation history'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['ai-review-history']],
    ];
    if (!$ids) {
      return ['#tree' => TRUE];
    }
    $build['#title'] = $this->t('Validation history (@count)', ['@count' => count($ids)]);

    $items = $storage->loadMultiple($ids);
    $grouped = ['pending' => [], 'done' => [], 'ignored' => [], 'superseded' => []];
    $has_open_pending = FALSE;
    // $ids is ordered newest first; loadMultiple() is not.
    foreach ($ids as $id) {
      $validation = $items[$id] ?? NULL;
      // $skip_id renders inline under its field rows above — listing it
      // here again would duplicate the whole suggestion table.
      if ($validation === NULL || (int) $id === $skip_id) {
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
        '#tag' => 'h4',
        '#value' => $this->t('@heading (@count)', [
          '@heading' => $heading,
          '@count' => count($grouped[$group]),
        ]),
        '#attributes' => ['class' => ['ai-review-history__group', 'ai-review-history__group--' . $group]],
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
        // Collapsed: superseded scores are an audit trail, not daily work.
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

    $suggestions = is_array($parsed['suggestions'] ?? NULL) ? $parsed['suggestions'] : [];
    $applied = is_array($parsed['applied_fields'] ?? NULL) ? $parsed['applied_fields'] : NULL;
    // Rejected suggestions live under ignored_suggestions — a closed item
    // must still list them (with a Rejected status), otherwise the record
    // only shows what was accepted. A pending item keeps them out: its
    // table is for open decisions only.
    $ignored_list = is_array($parsed['ignored_suggestions'] ?? NULL) ? $parsed['ignored_suggestions'] : [];
    $rejected_fields = array_values(array_filter(array_map(
      fn ($s) => is_array($s) ? ($s['field'] ?? NULL) : NULL,
      $ignored_list,
    )));
    $prepared = $this->prepareSuggestions(
      $group === 'pending' ? $suggestions : array_merge($suggestions, $ignored_list),
      $node,
    );
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

    if ($prepared !== []) {
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
      $has_status = $applied !== NULL || $rejected_fields !== [];
      if ($has_status) {
        $header[] = $this->t('Status');
      }
      $rows = [];
      foreach ($prepared as $s) {
        $row = [
          'field' => $s['label'],
          'change' => ['data' => $this->diffMarkup($s['current'], $s['suggested_display'])],
          'reason' => $s['reason'],
        ];
        if ($has_status) {
          $is_applied = in_array($s['field'], $applied ?? [], TRUE);
          // Same pill styling as the per-field Passed/Review badges, so
          // "Applied" reads like a pass everywhere on the page.
          $row['status'] = [
            'data' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => match (TRUE) {
                $is_applied => $this->t('Applied'),
                in_array($s['field'], $rejected_fields, TRUE) => $this->t('Rejected'),
                default => $this->t('Not applied'),
              },
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

    return $element;
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
   * Runs the improve workflow scoped to one field of a validation.
   *
   * Triggered by the per-field "Fix with AI" button on a Review row.
   */
  public function improveField(array &$form, FormStateInterface $form_state): void {
    [, $validation_id, $field] = explode(':', $form_state->getTriggeringElement()['#name'], 3);
    $this->runImprove($form_state, (int) $validation_id, $field);
  }

  /**
   * Starts a content_improve run from a validation's findings.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param int $validation_id
   *   The validation report to improve from.
   * @param string|null $only_field
   *   Restrict the rewrite to this field, or NULL for all flagged fields.
   */
  private function runImprove(FormStateInterface $form_state, int $validation_id, ?string $only_field): void {
    $validation = $this->loadOwnedValidation($validation_id, $form_state);
    $node = $this->loadNode($form_state);
    if ($validation === NULL || $node === NULL) {
      return;
    }
    $parsed = $this->parseResult((string) $validation->get('field_validation_result')->value);
    $findings = $this->improveFindings($node, $parsed ?? [], $only_field);
    $form_state->setRebuild();
    if ($findings === '') {
      $this->messenger()->addWarning($this->t('This validation has no findings to improve from.'));
      return;
    }
    $this->startRun($node, 'content_improve', $findings);
  }

  /**
   * Assembles the findings prompt string the improve workflow receives.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being improved, source of the rewritable field set.
   * @param array<string, mixed> $parsed
   *   The parsed validation result.
   * @param string|null $only_field
   *   Restrict the rewrite to this field, or NULL for all flagged fields.
   *
   * @return string
   *   The findings string, empty when the report carries nothing usable.
   */
  private function improveFindings(NodeInterface $node, array $parsed, ?string $only_field = NULL): string {
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
      $names = self::GUIDELINES;
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
    // Only rewritable fields are named to the improver: a field it cannot
    // safely replace (a reference field, whose target ids it would have to
    // invent) is validated but never offered for a fix.
    $labels = [];
    foreach (ValidatedFields::fixableLabels($node) as $field => $label) {
      $labels[$field] = $label . ' (field "' . $field . '")';
    }
    if (is_array($parsed['field_findings'] ?? NULL)) {
      $lines = [];
      foreach ($labels as $field => $label) {
        if ($only_field !== NULL && $field !== $only_field) {
          continue;
        }
        $text = $parsed['field_findings'][$field] ?? NULL;
        if (is_scalar($text) && trim((string) $text) !== '') {
          $lines[] = $label . ' → ' . trim((string) $text);
        }
      }
      if ($lines !== []) {
        $findings .= ' Per-field findings (each line is the ONLY reason that field may be changed): ' . implode(' | ', $lines);
      }
    }
    if ($only_field !== NULL && isset($labels[$only_field]) && $findings !== '') {
      // The [only_field:x] marker is machine-read by the improve gate,
      // which deterministically drops suggestions for any other field —
      // the sentence around it is for the model.
      $findings .= ' THE EDITOR ASKED TO FIX ONLY ' . $labels[$only_field]
        . ': emit EXACTLY ONE suggestion, for that field alone, and OMIT every other field from "suggestions". [only_field:' . $only_field . ']';
    }
    return $findings;
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
      // A tags suggestion is normalized to the FINAL tag list (unmentioned
      // stored tags kept), so the diff and the edit chips never pretend
      // that removing one tag removes them all. The "before" side must be
      // the node's real stored list for the same reason — the model's own
      // "current" often names only the tags it removed.
      if (ValidatedFields::isTagsField($node, $field)) {
        $current = $this->displayValue($this->currentNodeValue($node, $field));
        $suggested_raw = implode(', ', ValidatedFields::mergeTagNames(
          $this->currentNodeValue($node, $field),
          $suggested_raw,
          $this->rawValue($suggestion['current'] ?? ''),
        ));
      }
      // The improve model is told to OMIT fields it cannot edit without
      // inventing content, but it sometimes emits them with an empty
      // suggested value anyway — an empty "change" is never applicable,
      // so drop the row instead of offering to blank a field. A tags
      // field is the exception: "" is the only way to say "remove the
      // tags", which is exactly what a single irrelevant tag needs.
      if ($this->plainText($suggested_raw) === '' && !ValidatedFields::isTagsField($node, $field)) {
        continue;
      }
      $suggested_display = $this->displayValue($suggested_raw);
      // A markup-only change (adding a link, fixing a tag) strips to
      // identical display text, which would render no diff at all — fall
      // back to a short raw-markup excerpt around the change, so the
      // changed HTML is visible without dumping the whole raw body.
      if ($suggested_display === $current) {
        $current_raw = $this->rawValue($suggestion['current'] ?? '');
        if ($current_raw === '') {
          $current_raw = $this->currentNodeValue($node, $field);
        }
        if ($current_raw !== $suggested_raw) {
          [$current, $suggested_display] = $this->markupChangeExcerpts($current_raw, $suggested_raw);
        }
      }
      $prepared['s' . $delta] = [
        'field' => $field,
        'label' => (string) ($suggestion['label'] ?? $field),
        'current' => $current,
        'suggested_display' => $suggested_display,
        'suggested_raw' => $suggested_raw,
        'kind' => $this->valueKind($suggested_raw),
        'tags' => ValidatedFields::isTagsField($node, $field),
        'format' => $this->fieldTextFormat($node, $field),
        'reason' => (string) ($suggestion['reason'] ?? ''),
      ];
    }
    return $prepared;
  }

  /**
   * Trims two raw values to a short excerpt around their difference.
   *
   * Used for markup-only changes, where the interesting part is a small
   * HTML edit inside an otherwise identical value: everything before the
   * first differing byte and after the last one is context, and only ~120
   * characters of it are kept on each side, marked with ellipses.
   *
   * @param string $current
   *   The current raw value.
   * @param string $suggested
   *   The suggested raw value.
   *
   * @return array{0: string, 1: string}
   *   The trimmed current and suggested excerpts.
   */
  private function markupChangeExcerpts(string $current, string $suggested): array {
    $context = 120;
    $max = min(strlen($current), strlen($suggested));
    $prefix = 0;
    while ($prefix < $max && $current[$prefix] === $suggested[$prefix]) {
      $prefix++;
    }
    $suffix = 0;
    while ($suffix < $max - $prefix
      && $current[strlen($current) - 1 - $suffix] === $suggested[strlen($suggested) - 1 - $suffix]
    ) {
      $suffix++;
    }
    $start = max(0, $prefix - $context);
    // Never split a UTF-8 sequence: back up to a leading byte.
    while ($start > 0 && (ord($current[$start]) & 0xC0) === 0x80) {
      $start--;
    }
    $excerpt = function (string $value) use ($start, $suffix, $context): string {
      $end = min(strlen($value), strlen($value) - $suffix + $context);
      while ($end < strlen($value) && (ord($value[$end]) & 0xC0) === 0x80) {
        $end++;
      }
      return ($start > 0 ? '… ' : '')
        . substr($value, $start, $end - $start)
        . ($end < strlen($value) ? ' …' : '');
    };
    return [$excerpt($current), $excerpt($suggested)];
  }

  /**
   * Resolves the value to apply from an edit control's submitted values.
   *
   * An edited suggestion wins over the AI's; an emptied field falls back
   * to the AI suggestion so a stray clear never wipes a value. JSON
   * suggestions come back as per-key fields and are re-encoded here, so
   * the serialized value can never be malformed. HTML suggestions come
   * back from a text_format element as {value, format} — only the value
   * is applied (the field keeps its stored format).
   *
   * @param array<string, mixed> $edit
   *   The submitted edit-control values ('json' map or 'value').
   * @param string $suggested_raw
   *   The AI's raw suggested value, the fallback.
   *
   * @return string
   *   The value to apply.
   */
  private function editedValue(array $edit, string $suggested_raw): string {
    if (is_array($edit['json'] ?? NULL)) {
      $decoded = json_decode($suggested_raw, TRUE) ?: [];
      foreach ($edit['json'] as $json_key => $json_value) {
        if (isset($decoded[$json_key]) && trim((string) $json_value) !== '') {
          $decoded[$json_key] = (string) $json_value;
        }
      }
      return (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $edited = $edit['value'] ?? '';
    if (is_array($edited)) {
      $edited = $edited['value'] ?? '';
    }
    return trim((string) $edited) !== '' ? trim((string) $edited) : $suggested_raw;
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
    if (!empty($s['tags'])) {
      // Comma-separated term names under the hood; the modal JS mirrors
      // this as a Tagify input so editing looks like the field itself.
      $edit['value'] = [
        '#type' => 'textarea',
        '#default_value' => $s['suggested_raw'],
        '#rows' => 2,
        '#attributes' => ['data-ai-tags' => '1'],
        '#description' => $this->t('Your edited tags replace the AI suggestion when applied.'),
      ];
      return $edit;
    }
    if ($s['kind'] === 'html') {
      $format = $s['format'] ?: 'basic_html';
      $edit['value'] = [
        '#type' => 'text_format',
        '#format' => $format,
        '#allowed_formats' => [$format],
        '#default_value' => $s['suggested_raw'],
        '#rows' => 8,
        '#description' => $this->t('Your edited text replaces the AI suggestion when applied.'),
        // The modal JS mirrors this control with its own CKEditor. It must
        // know the format at build time, and core's editor.js only stamps
        // data-editor-active-text-format on the textarea once its own
        // (async) attach has run — too late when the modal opens right on
        // page load. This attribute is ours and always there.
        '#attributes' => ['data-ai-format' => $format],
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
      // Dumping the full unchanged text here reads as a broken diff; a
      // short honest note is all an identical value warrants.
      return [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('The suggested value is identical to the current one.'),
        '#attributes' => ['class' => ['ai-review-hint']],
      ];
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
    if (ValidatedFields::isTagsField($node, $field)) {
      $labels = array_map(
        static fn ($term) => (string) $term->label(),
        $node->get($field)->referencedEntities(),
      );
      return implode(', ', $labels);
    }
    if (ValidatedFields::isMediaField($node, $field)) {
      foreach ($node->get($field)->referencedEntities() as $media) {
        $source_field = $media->getSource()->getConfiguration()['source_field'] ?? '';
        if (is_string($source_field) && $source_field !== '' && $media->hasField($source_field)) {
          $item = $media->get($source_field)->first()?->getValue() ?? [];
          return (string) ($item['alt'] ?? '');
        }
      }
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
  public function parseResult(string $raw): ?array {
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
  private function startRun(NodeInterface $node, string $workflow_id, string $message = 'Run', bool $quiet = FALSE): void {
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
        if (!$quiet) {
          $this->messenger()->addStatus($this->t('@label finished. Review the results below.', ['@label' => $workflow->label()]));
        }
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
    if (ValidatedFields::isTagsField($node, $field)) {
      $node->set($field, $this->resolveTagTargets($node, $field, $value));
      return TRUE;
    }
    if (ValidatedFields::isMediaField($node, $field)) {
      return $this->applyMediaAlt($node, $field, $value);
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
   * Writes a suggested alt text onto a media field's referenced media.
   *
   * The suggestion IS the new alt text; the file reference itself is
   * never changed. The media entity is shared, so the new alt applies on
   * every usage of that media item — the same effect as an editor fixing
   * it in the media library.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node whose media field is being fixed.
   * @param string $field
   *   The media reference field machine name.
   * @param string $value
   *   The suggested alt text.
   *
   * @return bool
   *   TRUE when a referenced media item accepted the alt text.
   */
  public function applyMediaAlt(NodeInterface $node, string $field, string $value): bool {
    $alt = trim($value);
    if ($alt === '') {
      return FALSE;
    }
    foreach ($node->get($field)->referencedEntities() as $media) {
      // The media entity is shared site-wide, so the editor needs update
      // access to IT — node access alone must not reach into it.
      if (!$media->access('update')) {
        $this->messenger()->addWarning($this->t('You do not have permission to update the referenced media item, so its alternative text was not changed.'));
        continue;
      }
      $source_field = $media->getSource()->getConfiguration()['source_field'] ?? '';
      if (!is_string($source_field) || $source_field === '' || !$media->hasField($source_field)) {
        continue;
      }
      $item = $media->get($source_field)->first();
      $stored = $item?->getValue() ?? [];
      // Only alt-bearing sources (images) accept the fix; a document or
      // remote-video media item is skipped.
      if ($item === NULL || !array_key_exists('alt', $stored)) {
        continue;
      }
      $stored['alt'] = $alt;
      $media->set($source_field, $stored);
      $media->save();
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Resolves a suggested tag-name list to taxonomy term targets.
   *
   * Existing terms are matched by name inside the field's target
   * vocabularies; missing ones are created there — the same behavior the
   * field's own auto_create widget setting licenses for editors.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being fixed.
   * @param string $field
   *   The tags field machine name.
   * @param string $value
   *   The suggested value (comma-separated term names).
   *
   * @return list<array{target_id: int}>
   *   Field items ready for $node->set().
   */
  private function resolveTagTargets(NodeInterface $node, string $field, string $value): array {
    $settings = $node->getFieldDefinition($field)->getSetting('handler_settings') ?? [];
    $bundles = array_values(array_filter((array) ($settings['target_bundles'] ?? [])));
    $bundle = ($settings['auto_create_bundle'] ?? '') ?: ($bundles[0] ?? '');
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $targets = [];
    foreach (ValidatedFields::parseTagNames($value) as $name) {
      // accessCheck(FALSE): term lookup by name for a save the editor is
      // already authorized to make; term access would only hide existing
      // terms and cause duplicates.
      $query = $storage->getQuery()->accessCheck(FALSE)->condition('name', $name);
      if ($bundles !== []) {
        $query->condition('vid', $bundles, 'IN');
      }
      $ids = $query->range(0, 1)->execute();
      $id = $ids === [] ? 0 : (int) reset($ids);
      if ($id === 0) {
        if ($bundle === '') {
          continue;
        }
        $term = $storage->create(['vid' => $bundle, 'name' => $name]);
        $term->save();
        $id = (int) $term->id();
      }
      $targets[] = ['target_id' => $id];
    }
    return $targets;
  }

  /**
   * Loads the current (default revision) node the form acts on.
   */
  private function loadNode(FormStateInterface $form_state): ?NodeInterface {
    // On the node edit form the editor may have unsaved changes in other
    // fields. Building the entity from the submitted values instead of
    // loading it from storage carries them along, so accepting an AI
    // suggestion for one field never silently reverts a manual edit made
    // to another. (ContentEntityForm::validateForm() builds an entity too
    // but keeps it local — $form_object->getEntity() is still the pristine
    // one, so it has to be built here.)
    //
    // Only safe under full validation: a button with
    // #limit_validation_errors has had every value outside its own
    // section stripped from the form state, so building from it would
    // blank the very fields this is meant to protect.
    $trigger = $form_state->getTriggeringElement();
    $limited = isset($trigger['#limit_validation_errors']) && $trigger['#limit_validation_errors'] !== FALSE;
    $form_object = $form_state->getFormObject();
    if (!$limited && $form_object instanceof EntityFormInterface) {
      $complete_form = &$form_state->getCompleteForm();
      $entity = $form_object->buildEntity($complete_form, $form_state);
      if ($entity instanceof NodeInterface && (int) $entity->id() === (int) $form_state->get('nid')) {
        return $entity;
      }
    }
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
