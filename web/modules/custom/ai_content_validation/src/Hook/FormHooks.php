<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Hook;

use Drupal\ai_content_validation\Form\AiReviewForm;
use Drupal\ai_content_validation\ValidatedFields;
use Drupal\Component\Utility\Html;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\flowdrop_interrupt\Service\InterruptManagerInterface;
use Drupal\flowdrop_session\Service\SessionService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Form alters bringing the AI validation into the node edit form.
 */
final class FormHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly ClassResolverInterface $classResolver,
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'flowdrop_interrupt.manager')]
    private readonly InterruptManagerInterface $interruptManager,
    #[Autowire(service: 'flowdrop_session.service')]
    private readonly SessionService $sessionService,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for flowdrop_interrupt_resolve.
   *
   * Prepends the latest assistant message of the interrupt's session so the
   * user sees the AI's proposal (normally shown in the playground chat) while
   * answering the human-in-the-loop question.
   */
  #[Hook('form_flowdrop_interrupt_resolve_alter')]
  public function interruptResolveAlter(array &$form, FormStateInterface $form_state): void {
    $interrupt_id = $form_state->get('interrupt_id');
    if (!$interrupt_id) {
      return;
    }
    $interrupt = $this->interruptManager->getInterrupt($interrupt_id);
    $session_id = $interrupt?->getSessionId();
    if (!$session_id) {
      return;
    }
    foreach (array_reverse($this->sessionService->getMessages($session_id, NULL, 50, NULL, TRUE)) as $message) {
      if ($message->getRole() === 'assistant' && $message->getContent() !== '') {
        $form['ai_proposal'] = [
          '#markup' => '<blockquote><p>' . nl2br(Html::escape($message->getContent())) . '</p></blockquote>',
          '#weight' => -10,
        ];
        break;
      }
    }
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for node_form.
   *
   * Brings the AI validation into the node edit form itself: a quality
   * score panel in the sidebar, a colored status dot per validated field
   * (title, body, meta tags), a per-field "Fix with AI" button when the
   * report flags the field, and the inline Accept / Reject / Edit panel
   * for a pending suggestion. All heavy lifting is delegated to the
   * public readers and submit handlers of AiReviewForm, so both UIs share
   * one implementation.
   */
  #[Hook('form_node_form_alter')]
  public function nodeFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $form_state->getFormObject()->getEntity();
    if ($node->isNew() || !$this->currentUser->hasPermission('create flowdrop_session')) {
      return;
    }
    $review = $this->review();
    // The reused AiReviewForm submit handlers locate the node through this
    // form-state key.
    $form_state->set('nid', $node->id());
    $form['#attached']['library'][] = 'ai_content_validation/ai_review';
    $form['#attached']['library'][] = 'ai_content_validation/node_form';
    // The dots and the sidebar are built from validation items, so the
    // dynamic page cache must drop this form when a report lands — the
    // post-save run finishes after the page was already cached.
    $form['#cache']['tags'][] = 'ai_content_validation_item_list';

    $report = $review->latestReport($node);
    $parsed = $report === NULL
      ? NULL
      : $review->parseResult((string) ($report->get('field_validation_result')->value ?? ''));
    // A report only speaks for the content it actually saw — measured by
    // the same content hash the run gate uses.
    // Measured against the SAVED node, never the form object's entity:
    // EntityForm::afterBuild() rebuilds that entity from user input on
    // every POST, so mid-session it reflects unsaved edits and staged AI
    // values, and its hash would flip the report to "stale" — hiding the
    // other fields' Fix buttons. The report speaks for the saved content;
    // in-form changes are tracked by the pen dots instead.
    $saved = $this->entityTypeManager->getStorage('node')->load($node->id());
    $current = $review->reportIsCurrent($saved instanceof NodeInterface ? $saved : $node, $report, $parsed);
    $pending = $review->pendingSuggestions($node);
    $decisions = $review->fieldDecisions($node);
    // The pen state for manual edits lives client-side (typing cannot be
    // seen by the server), so the JS records each edited field into this
    // hidden input and the rebuild reads it back — otherwise a POST that
    // rebuilds the form (a Fix with AI run, an accepted suggestion) would
    // reset an edited field's dot to the report verdict.
    $form['acv_edited'] = [
      '#type' => 'hidden',
      '#default_value' => '',
    ];
    // Alt-text fixes staged for media fields (JSON field => alt): applied
    // to the media entities by Save, right before the node itself saves,
    // so the post-save validation already reads the new alt.
    $form['acv_staged_alt'] = [
      '#type' => 'hidden',
      '#default_value' => '',
    ];
    if (isset($form['actions']['submit']['#submit'])) {
      array_unshift($form['actions']['submit']['#submit'], 'ai_content_validation_node_form_staged_alts_submit');
    }
    $edited_input = $form_state->getUserInput()['acv_edited'] ?? '';
    foreach (explode(',', is_string($edited_input) ? $edited_input : '') as $edited_field) {
      if ($edited_field !== '') {
        $decisions[$edited_field] ??= 'edited';
      }
    }
    foreach ($form_state->get('acv_staged') ?? [] as $staged_field) {
      $decisions[$staged_field] = 'accepted';
    }

    $this->buildSidebar($form, $review, $node, $current);

    // The validated set comes from the bundle's own field definitions, so
    // a field added to the content type later gets its status dot without
    // a code change here.
    $labels = ValidatedFields::labels($node);
    $verdicts = is_array($parsed['field_verdicts'] ?? NULL) ? $parsed['field_verdicts'] : [];
    $findings = is_array($parsed['field_findings'] ?? NULL) ? $parsed['field_findings'] : [];
    // A report that fails wholesale describes content that does not exist
    // yet; there is nothing for the improver to work from.
    $thin = $current && $this->contentTooThin($parsed);
    // A stale report still shows its breakdown — findings and dots are
    // shown from it anyway — flagged as stale so the dialog says the
    // score predates the current content instead of downgrading to the
    // bare popover.
    $details = $this->guidelineDetails($parsed, $thin, !$current);
    $overrides = $review->editorOverrides($parsed);
    foreach ($labels as $field => $label) {
      if (!isset($form[$field])) {
        continue;
      }
      $element = $this->buildFieldStatus(
        $review,
        $field,
        $label,
        $report === NULL ? NULL : (int) $report->id(),
        $current,
        $verdicts[$field] ?? NULL,
        is_string($findings[$field] ?? NULL) ? trim($findings[$field]) : '',
        $pending[$field] ?? NULL,
        $decisions[$field] ?? NULL,
        $details,
        ValidatedFields::isFixable($node, $field) && !$thin,
        $overrides[$field] ?? NULL,
      );
      if ($element !== []) {
        // The meta tags widget is a details grouped into the sidebar; the
        // dot must live inside that details, or it strands in the main
        // column while the widget renders elsewhere.
        $target = &$form[$field];
        $group_class = '';
        if (($form[$field]['widget'][0]['#type'] ?? '') === 'details') {
          $target = &$form[$field]['widget'][0];
          $group_class = 'ai-nodeform-field--group';
        }
        // ponytail: our element sits inside the widget for layout only.
        // Re-parenting keeps its values out of the widget's value tree —
        // otherwise MetatagFirehose reads "fix"/"dot" as metatag plugin ids.
        $target['ai_review'] = $element + [
          '#weight' => 100,
          '#parents' => ['ai_review_' . $field],
        ];
        // Positioning anchor for the absolutely placed status dot.
        $target['#attributes']['class'][] = 'ai-nodeform-field';
        if ($group_class !== '') {
          $target['#attributes']['class'][] = $group_class;
        }
        unset($target);
      }
    }
  }

  /**
   * Submit handler on Save: writes staged alt-text fixes to their media.
   *
   * Runs before NodeForm::save() so the validation triggered by the node
   * save already sees the new alt. The media entity is shared — the alt
   * applies everywhere that media item is used.
   */
  public function applyStagedAlts(array &$form, FormStateInterface $form_state): void {
    $staged = json_decode((string) ($form_state->getUserInput()['acv_staged_alt'] ?? ''), TRUE);
    $node = $form_state->getFormObject()->getEntity();
    if (!is_array($staged) || !$node instanceof NodeInterface) {
      return;
    }
    foreach ($staged as $field => $alt) {
      if (is_string($field) && is_string($alt) && trim($alt) !== '') {
        $this->review()->applyMediaAlt($node, $field, $alt);
      }
    }
  }

  /**
   * Submit handler: accepts one field's pending AI suggestion.
   *
   * Delegates to AiReviewForm, which applies the value to the node as a new
   * revision and records the decision. The default redirect reloads the
   * edit form from the fresh revision.
   */
  public function applySubmit(array &$form, FormStateInterface $form_state): void {
    $this->review()->applyFieldSuggestion($form, $form_state);
  }

  /**
   * Submit handler: rejects one field's pending AI suggestion.
   */
  public function rejectSubmit(array &$form, FormStateInterface $form_state): void {
    $this->review()->ignoreFieldSuggestion($form, $form_state);
  }

  /**
   * Submit handler: runs the improvement workflow for one flagged field.
   */
  public function improveSubmit(array &$form, FormStateInterface $form_state): void {
    $this->review()->improveField($form, $form_state);
  }

  /**
   * Submit handler: marks one flagged field's finding as OK.
   */
  public function markOkSubmit(array &$form, FormStateInterface $form_state): void {
    $this->review()->markFieldOk($form, $form_state);
  }

  /**
   * Submit handler: reopens a finding that was marked as OK.
   */
  public function reopenSubmit(array &$form, FormStateInterface $form_state): void {
    $this->review()->reopenFieldFinding($form, $form_state);
  }

  /**
   * Returns the review form instance whose readers and handlers are reused.
   */
  private function review(): AiReviewForm {
    return $this->classResolver->getInstanceFromDefinition(AiReviewForm::class);
  }

  /**
   * Builds the AI Quality details panel for the node form sidebar.
   *
   * @param array<string, mixed> $form
   *   The node form, altered by reference.
   * @param \Drupal\ai_content_validation\Form\AiReviewForm $review
   *   The review form instance whose readers and handlers are reused.
   * @param \Drupal\node\NodeInterface $node
   *   The node being edited.
   * @param bool $current
   *   Whether the latest report still matches the content being edited.
   */
  private function buildSidebar(array &$form, AiReviewForm $review, NodeInterface $node, bool $current): void {
    [$score, $date, $summary] = $review->acceptedScore($node);

    // Tone drives the score colour, the rating chip and the summary box.
    $tone = match (TRUE) {
      $score === NULL, !$current => 'none',
      $score >= 80 => 'good',
      $score >= 50 => 'warn',
      default => 'bad',
    };
    $rating = match ($tone) {
      'good' => $this->t('Excellent'),
      'warn' => $this->t('Needs review'),
      'bad' => $this->t('Poor'),
      default => NULL,
    };
    $status = match (TRUE) {
      $score === NULL => $this->t('Not validated yet.'),
      !$current => $this->t('The content has changed since this score — re-run the validation.'),
      default => NULL,
    };

    $form['ai_review_status'] = [
      '#type' => 'details',
      '#title' => $this->t('AI validation'),
      '#group' => 'advanced',
      '#open' => TRUE,
      '#weight' => -10,
      '#attributes' => ['class' => ['ai-nodeform-sidebar', 'ai-nodeform-sidebar--' . $tone]],
      'score' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-nodeform-sidebar__score']],
        'donut' => $this->scoreDonut($score, !$current),
        'text' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-nodeform-sidebar__score-text']],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#value' => $score === NULL
              ? $this->t('No quality score')
              : $this->t('Quality score: <b>@score/100</b>', ['@score' => $score]),
            '#attributes' => ['class' => ['ai-nodeform-sidebar__label']],
          ],
          'rating' => $rating === NULL ? [] : [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $rating,
            '#attributes' => ['class' => ['ai-nodeform-sidebar__rating']],
          ],
        ],
        'date' => $score === NULL || !$current ? [] : [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-nodeform-sidebar__date']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->t('Validated'),
            '#attributes' => ['class' => ['ai-nodeform-sidebar__date-title']],
          ],
          'value' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => date('d M Y, H:i', (int) $date),
            '#attributes' => ['class' => ['ai-nodeform-sidebar__date-value']],
          ],
        ],
      ],
      'status' => $status === NULL ? [] : [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $status,
        '#attributes' => ['class' => ['ai-nodeform-sidebar__status']],
      ],
      'box' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-nodeform-sidebar__box']],
        // Model output is untrusted: #plain_text is escaped by the renderer.
        'summary' => $summary === '' || !$current ? [] : [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['ai-nodeform-sidebar__summary']],
          'text' => ['#plain_text' => $summary],
        ],
        'auto' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Validation runs automatically every time this content is saved.'),
          '#attributes' => ['class' => ['ai-nodeform-sidebar__auto']],
        ],
      ],
      'review_link' => [
        '#type' => 'link',
        '#title' => $this->t('Open full AI validation'),
        '#url' => Url::fromRoute('ai_content_validation.node_review', ['node' => $node->id()]),
        '#attributes' => ['class' => ['ai-nodeform-sidebar__link']],
      ],
    ];
  }

  /**
   * Summarises a report's guideline breakdown for the passing popover.
   *
   * The model scores each of the ten EU content guidelines either
   * numerically (out of 10) or as a verdict word ("pass", "minor", …);
   * both shapes are normalised here into one list the dialog renders.
   *
   * @param array<string, mixed>|null $parsed
   *   The parsed validation result, or NULL when there is no report.
   * @param bool $thin
   *   Whether the content is too thin to improve.
   * @param bool $stale
   *   Whether the report predates the content currently being edited.
   *
   * @return string
   *   JSON with the overall score, summary and per-guideline rows; the
   *   empty string when the report carries no guideline scores.
   */
  private function guidelineDetails(?array $parsed, bool $thin = FALSE, bool $stale = FALSE): string {
    $scores = is_array($parsed['scores'] ?? NULL) ? $parsed['scores'] : [];
    if ($scores === []) {
      return '';
    }
    $items = [];
    foreach (AiReviewForm::GUIDELINES as $number => $name) {
      if (!isset($scores[$number]) && !isset($scores[(string) $number])) {
        continue;
      }
      $value = $scores[$number] ?? $scores[(string) $number];
      $items[] = [
        'n' => $number,
        'name' => $name,
        'desc' => AiReviewForm::GUIDELINE_DESCRIPTIONS[$number] ?? '',
        'ok' => is_numeric($value) ? (int) $value >= 10 : strtolower(trim((string) $value)) === 'pass',
        'value' => is_numeric($value) ? (int) $value . '/10' : ucfirst(strtolower(trim((string) $value))),
      ];
    }
    if ($items === []) {
      return '';
    }
    $summary = is_scalar($parsed['summary'] ?? NULL) ? trim((string) $parsed['summary']) : '';

    return (string) json_encode([
      'score' => is_numeric($parsed['score'] ?? NULL) ? (int) $parsed['score'] : NULL,
      'summary' => $summary,
      'thin' => $thin,
      'stale' => $stale,
      'items' => $items,
    ]);
  }

  /**
   * Whether the report describes content with nothing to improve.
   *
   * A rewrite needs something to rewrite: when the content fails outright
   * on half its guidelines, or scores near zero, the model could only
   * invent the text, so no fix is offered and the editor writes it.
   *
   * @param array<string, mixed>|null $parsed
   *   The parsed validation result.
   *
   * @return bool
   *   TRUE when the content is too thin to improve.
   */
  private function contentTooThin(?array $parsed): bool {
    $score = $parsed['score'] ?? NULL;
    if (is_numeric($score) && (int) $score < 30) {
      return TRUE;
    }
    $scores = is_array($parsed['scores'] ?? NULL) ? $parsed['scores'] : [];
    if ($scores === []) {
      return FALSE;
    }
    $failed = count(array_filter(
      $scores,
      fn ($v) => is_numeric($v) ? (int) $v <= 3 : strtolower(trim((string) $v)) === 'fail',
    ));

    return $failed * 2 >= count($scores);
  }

  /**
   * Builds the quality score donut used in the node form sidebar.
   *
   * Same visual language as the content overview column: red / yellow /
   * green ring with the score in the center, gray dash when unscored, an
   * amber "!" badge when the score no longer matches the content. The ring
   * geometry lives in CSS; only the percentage is passed as a custom
   * property (#markup would strip an inline style attribute).
   *
   * @param int|null $score
   *   The accepted quality score, or NULL when none exists.
   * @param bool $stale
   *   Whether the content changed since the score was produced.
   *
   * @return array<string, mixed>
   *   Render array for the donut.
   */
  private function scoreDonut(?int $score, bool $stale): array {
    $percent = $score === NULL ? 100 : max(0, min(100, $score));
    $text = $score === NULL ? '–' : $percent . '%';

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-donut'],
        'style' => '--ai-donut-percent: ' . $percent . ';',
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $text,
        '#attributes' => ['class' => ['ai-donut__value']],
      ],
      'badge' => !$stale || $score === NULL ? [] : [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => '!',
        '#attributes' => ['class' => ['ai-donut__badge'], 'aria-hidden' => 'true'],
      ],
    ];
  }

  /**
   * Builds the status row rendered under one validated field's widget.
   *
   * @param \Drupal\ai_content_validation\Form\AiReviewForm $review
   *   The review form instance, for the inline suggestion panel.
   * @param string $field
   *   The field machine name.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The field display label.
   * @param int|null $report_id
   *   The latest validation report id, or NULL when none exists.
   * @param bool $current
   *   Whether the report matches the content being edited.
   * @param mixed $verdict
   *   The report's per-field verdict ("pass" / "review"), when present.
   * @param string $finding
   *   The report's per-field finding text.
   * @param array{id: int, suggestion: array<string, mixed>}|null $pending
   *   The field's pending improve suggestion, when one awaits review.
   * @param string|null $decision
   *   The session decision for the field: 'accepted', 'rejected' or NULL.
   * @param string $details
   *   The report's guideline breakdown as JSON, for the popover.
   * @param bool $fixable
   *   Whether the improve workflow may rewrite this field's value.
   * @param array{uid: int, at: int}|null $override
   *   The field's "Marked as OK" editor override, when one exists.
   *
   * @return array<string, mixed>
   *   Render array; empty when there is nothing to show for the field.
   */
  private function buildFieldStatus(AiReviewForm $review, string $field, $label, ?int $report_id, bool $current, mixed $verdict, string $finding, ?array $pending, ?string $decision, string $details, bool $fixable, ?array $override = NULL): array {
    if ($report_id === NULL) {
      return [];
    }
    // A field the report never spoke about (one added to the bundle after
    // the report was produced) gets no dot at all — a green "passed" dot
    // for a field that was never assessed is worse than none.
    if ($verdict === NULL && $finding === '' && $pending === NULL && $decision === NULL) {
      return [];
    }
    // The explicit verdict is authoritative; older reports without one fall
    // back to the guideline-number heuristic the review page uses.
    $issue = match (TRUE) {
      is_string($verdict) => strtolower(trim($verdict)) !== 'pass',
      $finding === '' => $pending !== NULL,
      default => (bool) preg_match('/guidelines?\s*(?:no\.?\s*|#\s*)?\d/i', $finding),
    };
    // A human override quiets the flag: the dot goes blue and names who
    // decided the finding is acceptable. A session decision or a pending
    // suggestion still outranks it. $issue stays the RAW flag — the
    // hidden Fix / Mark as OK submits render from it so the "Reopen
    // finding" action can restore them without a page load; only the
    // visible state reads the override.
    $override = ($issue && $pending === NULL && $decision === NULL) ? $override : NULL;
    $display_issue = $issue && $override === NULL;
    [$state, $text] = match (TRUE) {
      $override !== NULL => [
        'ok',
        $this->t('Reviewed and accepted by @name — @date', [
          '@name' => $review->overrideName($override['uid']),
          '@date' => date('d.m.Y H:i', $override['at']),
        ]),
      ],
      $decision === 'accepted' => [
        'edited',
        $this->t('AI suggestion applied to the field — save the content to keep it.'),
      ],
      $decision === 'edited' => [
        'edited',
        $this->t('You edited this field after the last AI validation. Save to validate it again.'),
      ],
      $decision === 'rejected' => ['issue', $this->t('AI change rejected — the finding remains open.')],
      $pending !== NULL => ['issue', $this->t('An AI suggestion is ready for your review.')],
      $display_issue => ['issue', $finding === '' ? $this->t('Needs attention.') : $finding],
      // A passing field still carries a per-field note ("Accurate and
      // specific."); it says more than a generic "passed".
      default => ['pass', $finding === '' ? $this->t('Passed AI validation.') : $finding],
    };

    // Icon-only UI: the finding text lives in a dialog opened by clicking
    // the dot (js/ai-node-form.js); the real Fix submit stays in the DOM,
    // visually hidden, so the dialog button can trigger the form submit.
    $has_fix = $fixable && $current && $issue && $pending === NULL && $decision === NULL;
    $fix_name = 'improvefield:' . $report_id . ':' . $field;
    // Any open flag can be overridden by a human — even on fields the AI
    // never rewrites — as long as the report still matches the content.
    $has_ok = $current && $issue && $pending === NULL && $decision === NULL;
    $ok_name = 'markokfield:' . $report_id . ':' . $field;
    // An overridden finding can be reopened: the override is removed and
    // the flag (with its Fix / Mark as OK actions) comes back.
    $has_reopen = $override !== NULL && $current;
    $reopen_name = 'reopenfield:' . $report_id . ':' . $field;
    $info = $this->t('@label — @text', ['@label' => $label, '@text' => $text]);
    $element = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-nodeform-status']],
      'dot' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => '',
        '#attributes' => [
          'type' => 'button',
          'class' => [
            'ai-nodeform-status__dot',
            'ai-nodeform-status__dot--' . $state,
          ],
          'title' => $info,
          'aria-label' => $info,
          'aria-haspopup' => 'dialog',
          'data-ai-field' => $field,
          'data-ai-title' => $this->t('AI Validation Assistant'),
          'data-ai-label' => $label,
          'data-ai-text' => (string) $text,
          'data-ai-fix' => $has_fix && $override === NULL ? $fix_name : '',
          'data-ai-mark-ok' => $has_ok && $override === NULL ? $ok_name : '',
          'data-ai-reopen' => $has_reopen ? $reopen_name : '',
          // The raw AI finding, kept even when the display text is a
          // decision ("Marked as OK by …"): the dialog parses guideline
          // numbers out of it to badge the flagged rows.
          'data-ai-finding' => $finding,
          'data-ai-state' => $state,
          // Every state shows the breakdown; an edited field's dialog
          // leads with a banner saying it predates the change.
          'data-ai-details' => $details,
        ],
      ],
    ];

    if ($has_fix) {
      $element['fix'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['visually-hidden']],
        'button' => [
          '#type' => 'submit',
          '#value' => $this->t('Fix with AI'),
          '#name' => $fix_name,
          '#submit' => ['ai_content_validation_node_form_improve_submit'],
          '#limit_validation_errors' => [],
        ],
      ];
    }

    if ($has_ok) {
      // Only touches the validation item, never the node — so it skips
      // form validation and the default rebuild reloads the fresh state.
      $element['mark_ok'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['visually-hidden']],
        'button' => [
          '#type' => 'submit',
          '#value' => $this->t('Mark as OK'),
          '#name' => $ok_name,
          '#submit' => ['ai_content_validation_node_form_mark_ok_submit'],
          '#limit_validation_errors' => [],
          // Like Reject: records the decision without navigating, so the
          // editor's unsaved edits stay in the form. The callback only
          // flushes status messages; the dialog JS flips the dot.
          '#ajax' => [
            'callback' => 'ai_content_validation_node_form_reject_ajax',
            'progress' => ['type' => 'none'],
          ],
        ],
      ];
    }

    if ($has_reopen) {
      $element['reopen'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['visually-hidden']],
        'button' => [
          '#type' => 'submit',
          '#value' => $this->t('Reopen finding'),
          '#name' => $reopen_name,
          '#submit' => ['ai_content_validation_node_form_reopen_submit'],
          '#limit_validation_errors' => [],
          '#ajax' => [
            'callback' => 'ai_content_validation_node_form_reject_ajax',
            'progress' => ['type' => 'none'],
          ],
        ],
      ];
    }

    if ($pending !== NULL) {
      $panel = $review->buildInlineSuggestion($pending['id'], $pending['suggestion'], $label);
      // The '::method' submit notation resolves against the node form
      // object, which has no such handlers — rewire to the procedural
      // wrappers that delegate back to AiReviewForm.
      $panel['actions']['apply']['#submit'] = ['ai_content_validation_node_form_apply_submit'];
      // Accepting saves the node, and it saves the editor's unsaved edits
      // with it — so it validates the whole form like any other save.
      // Limiting validation here would write unvalidated content.
      $panel['actions']['ignore']['#submit'] = ['ai_content_validation_node_form_reject_submit'];
      $panel['actions']['ignore']['#limit_validation_errors'] = [];
      // Rejecting changes no content, so it must not navigate: a full
      // submit reloads the edit form, which abandons the editor's unsaved
      // edits (and trips the autosave "resume editing?" prompt). The AJAX
      // submit records the decision in place; the dialog JS updates the
      // dot optimistically.
      $panel['actions']['ignore']['#ajax'] = [
        'callback' => 'ai_content_validation_node_form_reject_ajax',
        'progress' => ['type' => 'none'],
      ];
      $panel['actions']['hint']['#value'] = $this->t('Accepting puts the suggestion into the field — nothing is saved until you save the content.');
      // The panel stays in the form (its submit buttons must POST) but is
      // hidden: the dialog clones its diff and reason for display and
      // proxy-clicks the real Accept / Reject buttons.
      $panel['#attributes']['data-ai-panel'] = $field;
      $panel['#attributes']['hidden'] = 'hidden';
      $element['dot']['#attributes']['data-ai-suggestion'] = $field;
      $element['suggestion'] = $panel;
    }

    return $element;
  }

}
