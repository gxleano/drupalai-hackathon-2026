<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\flowdrop_interrupt\Service\InterruptManagerInterface;
use Drupal\flowdrop_node_session\Service\NodeSessionService;
use Drupal\flowdrop_session\DTO\TurnOptions;
use Drupal\flowdrop_session\DTO\TurnResult;
use Drupal\flowdrop_session\Service\SessionService;
use Drupal\flowdrop_session\Service\SessionTurnServiceInterface;
use Drupal\flowdrop_workflow\Entity\FlowDropWorkflow;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Review page for AI content validations on a node.
 *
 * Replaces the FlowDrop playground UX: runs the configured workflows
 * server-side against the node, lists the resulting validation items and
 * lets the editor apply or ignore the suggested changes.
 */
final class AiReviewForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly NodeSessionService $nodeSessionService,
    private readonly SessionTurnServiceInterface $turnService,
    private readonly TimeInterface $time,
    private readonly InterruptManagerInterface $interruptManager,
    private readonly SessionService $sessionService,
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
      $container->get('flowdrop_interrupt.manager'),
      $container->get('flowdrop_session.service'),
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
    $form['#title'] = $this->t('AI Review: @title', ['@title' => $node->label()]);

    // Run buttons, one per workflow configured in the node session settings.
    $operations = $this->configFactory()->get('flowdrop_node_session.settings')->get('entity_operations') ?: [];
    $form['run'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form-actions']],
    ];
    foreach ($operations as $operation) {
      $form['run']['run_' . $operation['workflow_id']] = [
        '#type' => 'submit',
        '#value' => $this->t('Run @label', ['@label' => $operation['label']]),
        '#name' => 'run:' . $operation['workflow_id'],
        '#submit' => ['::runWorkflow'],
        '#button_type' => 'primary',
      ];
    }

    $form['interrupts'] = $this->buildPendingInterrupts($node, $operations);
    $form['responses'] = $this->buildRecentResponses($node, $operations);
    $form['validations'] = $this->buildValidations($node);

    return $form;
  }

  /**
   * Builds the "needs your input" section for paused workflow runs.
   *
   * Finds pending HITL interrupts whose session was started with this node
   * as entity context and links each to the contrib resolve form, bouncing
   * back here via the destination query parameter.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being reviewed.
   * @param array<int, array{workflow_id: string, label: string}> $operations
   *   The configured entity operations (workflow id + label).
   *
   * @return array<string, mixed>
   *   Render array of pending interrupts, empty when there are none.
   */
  private function buildPendingInterrupts(NodeInterface $node, array $operations): array {
    $build = [];
    $destination = Url::fromRoute('ai_content_validation.node_review', ['node' => $node->id()])->toString();

    foreach ($operations as $operation) {
      foreach ($this->interruptManager->getPendingInterruptsForWorkflow($operation['workflow_id']) as $interrupt) {
        $session_id = $interrupt->getSessionId();
        $session = $session_id ? $this->sessionService->getSession($session_id) : NULL;
        if ($session === NULL) {
          continue;
        }
        $context = $this->nodeSessionService->getEntityContext($session);
        if (($context['entity_type'] ?? '') !== 'node' || (string) ($context['entity_id'] ?? '') !== (string) $node->id()) {
          continue;
        }

        $element = [
          '#type' => 'details',
          '#title' => $this->t('@label needs your input', ['@label' => $operation['label']]),
          '#open' => TRUE,
        ];
        // Show what the workflow produced for review (latest assistant
        // message), so the user has context before answering.
        if ($assistant = $this->latestAssistantMessage((int) $session_id)) {
          $element['proposal'] = [
            '#markup' => '<blockquote><p>' . nl2br($this->escapeText($assistant)) . '</p></blockquote>',
          ];
        }
        $element['question'] = [
          '#markup' => '<p><strong>' . $this->escapeText($interrupt->getMessage()) . '</strong></p>',
        ];
        $element['respond'] = [
          '#type' => 'link',
          '#title' => $this->t('Respond'),
          '#url' => Url::fromRoute('flowdrop_interrupt.detail', ['interrupt_id' => $interrupt->uuid()], [
            'query' => ['destination' => $destination],
          ]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ];
        $build['interrupt_' . $interrupt->id()] = $element;
      }
    }

    return $build;
  }

  /**
   * Builds the latest AI response per workflow for this node.
   *
   * Workflows like the Assistance fixer end in chat output rather than a
   * saved validation item; without this their result would be invisible
   * outside the playground.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being reviewed.
   * @param array<int, array{workflow_id: string, label: string}> $operations
   *   The configured entity operations (workflow id + label).
   *
   * @return array<string, mixed>
   *   Render array with at most one response per workflow.
   */
  private function buildRecentResponses(NodeInterface $node, array $operations): array {
    $build = [];
    foreach ($operations as $operation) {
      foreach ($this->sessionService->getSessionsForWorkflow($operation['workflow_id'], NULL, 10) as $session) {
        $context = $this->nodeSessionService->getEntityContext($session);
        if (($context['entity_type'] ?? '') !== 'node' || (string) ($context['entity_id'] ?? '') !== (string) $node->id()) {
          continue;
        }
        // Sessions still waiting on input are shown in the interrupts
        // section instead.
        if ($this->interruptManager->hasPendingInterruptsForSession((int) $session->id())) {
          continue;
        }
        $assistant = $this->latestAssistantMessage((int) $session->id());
        if ($assistant === NULL) {
          continue;
        }
        $build['response_' . $session->id()] = [
          '#type' => 'details',
          '#title' => $this->t('@label — latest response', ['@label' => $operation['label']]),
          '#open' => TRUE,
          'content' => [
            '#markup' => '<blockquote><p>' . nl2br($this->escapeText($assistant)) . '</p></blockquote>',
          ],
        ];
        // Newest matching session per workflow is enough.
        break;
      }
    }
    return $build;
  }

  /**
   * Returns the content of the newest assistant message in a session.
   */
  private function latestAssistantMessage(int $session_id): ?string {
    foreach (array_reverse($this->sessionService->getMessages($session_id, NULL, 50, NULL, TRUE)) as $message) {
      $content = $message->getContent();
      // Skip machine output (raw JSON meant for downstream nodes); those
      // results surface as validation items instead.
      if ($message->getRole() === 'assistant' && $content !== '' && json_decode($content) === NULL) {
        return $content;
      }
    }
    return NULL;
  }

  /**
   * Builds the list of validation items for the node.
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
      ->range(0, 20)
      ->execute();

    $build = ['#tree' => TRUE];
    if (!$ids) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('No AI validations yet. Run one of the checks above.') . '</p>',
      ];
      return $build;
    }

    foreach ($storage->loadMultiple($ids) as $id => $validation) {
      $status = $validation->get('field_validation_status')->value ?? 'pending';
      $workflow = $validation->get('field_flowdrop_workflow')->entity;
      $result_raw = (string) ($validation->get('field_validation_result')->value ?? '');
      $parsed = json_decode($result_raw, TRUE);

      $element = [
        '#type' => 'details',
        '#title' => $this->t('@workflow — @status (@date)', [
          '@workflow' => $workflow?->label() ?? $this->t('Unknown workflow'),
          '@status' => $status,
          '@date' => date('d.m.Y H:i', (int) $validation->get('created')->value),
        ]),
        '#open' => $status === 'pending',
      ];

      $summary = is_array($parsed) ? ($parsed['summary'] ?? NULL) : NULL;
      $element['summary'] = [
        '#markup' => '<p>' . nl2br($this->escapeText($summary ?? $result_raw)) . '</p>',
      ];

      $suggestions = is_array($parsed) ? ($parsed['suggestions'] ?? []) : [];
      if ($suggestions && $status === 'pending') {
        $options = [];
        // Keys are prefixed: a raw 0 key would be dropped by array_filter()
        // when reading the tableselect value back.
        foreach ($suggestions as $delta => $suggestion) {
          $options['s' . $delta] = [
            'field' => $suggestion['label'] ?? $suggestion['field'] ?? '',
            'current' => $this->truncate((string) ($suggestion['current'] ?? '')),
            'suggested' => $this->truncate((string) ($suggestion['suggested'] ?? '')),
            'reason' => (string) ($suggestion['reason'] ?? ''),
          ];
        }
        $element['suggestions'] = [
          '#type' => 'tableselect',
          '#header' => [
            'field' => $this->t('Field'),
            'current' => $this->t('Current'),
            'suggested' => $this->t('Suggested'),
            'reason' => $this->t('Reason'),
          ],
          '#options' => $options,
          '#default_value' => array_fill_keys(array_keys($options), TRUE),
          '#empty' => $this->t('No suggestions.'),
        ];
        $element['apply'] = [
          '#type' => 'submit',
          '#value' => $this->t('Apply selected changes'),
          '#name' => 'apply:' . $id,
          '#submit' => ['::applySuggestions'],
          '#button_type' => 'primary',
        ];
      }

      if ($status === 'pending') {
        $element['ignore'] = [
          '#type' => 'submit',
          '#value' => $this->t('Ignore'),
          '#name' => 'ignore:' . $id,
          '#submit' => ['::ignoreValidation'],
        ];
      }

      $build[$id] = $element;
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // All buttons use dedicated #submit handlers.
  }

  /**
   * Runs a configured workflow against the node, synchronously.
   */
  public function runWorkflow(array &$form, FormStateInterface $form_state): void {
    [, $workflow_id] = explode(':', $form_state->getTriggeringElement()['#name'], 2);
    $workflow = $this->entityTypeManager->getStorage('flowdrop_workflow')->load($workflow_id);
    $node = $this->loadNode($form_state);
    if (!$workflow instanceof FlowDropWorkflow || $node === NULL) {
      $this->messenger()->addError($this->t('Workflow or content not found.'));
      return;
    }

    try {
      $session = $this->nodeSessionService->createSessionWithEntityContext(
        $workflow,
        'node',
        (string) $node->id(),
        '',
        (string) $node->getRevisionId(),
        'AI Review: ' . $node->label(),
      );
      $result = $this->turnService->executeTurn((string) $session->id(), 'Run', new TurnOptions(wait: TRUE));
      if ($result->status === TurnResult::STATUS_COMPLETED) {
        $this->messenger()->addStatus($this->t('@label finished. Review the results below.', ['@label' => $workflow->label()]));
      }
      elseif ($result->status === TurnResult::STATUS_AWAITING_INPUT) {
        // The workflow paused on a human-in-the-loop interrupt: send the
        // user straight to the resolve form and bounce back here after.
        $interrupts = $this->interruptManager->getPendingInterruptsForSession((int) $session->id());
        $interrupt = reset($interrupts);
        if ($interrupt !== FALSE) {
          $this->messenger()->addStatus($this->t('@label needs your input.', ['@label' => $workflow->label()]));
          $form_state->setRedirect('flowdrop_interrupt.detail', ['interrupt_id' => $interrupt->uuid()], [
            'query' => [
              'destination' => Url::fromRoute('ai_content_validation.node_review', ['node' => $node->id()])->toString(),
            ],
          ]);
          return;
        }
        $this->messenger()->addWarning($this->t('@label is waiting for input, but no pending question was found.', ['@label' => $workflow->label()]));
      }
      else {
        $this->messenger()->addWarning($this->t('@label finished with status: @status.', [
          '@label' => $workflow->label(),
          '@status' => $result->status,
        ]));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Workflow run failed: @message', ['@message' => $e->getMessage()]));
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

    $parsed = json_decode((string) $validation->get('field_validation_result')->value, TRUE);
    $suggestions = is_array($parsed) ? ($parsed['suggestions'] ?? []) : [];
    $selected = array_keys(array_filter($form_state->getValue(['validations', $validation_id, 'suggestions'], [])));

    $applied = [];
    foreach ($selected as $key) {
      $suggestion = $suggestions[(int) substr((string) $key, 1)] ?? NULL;
      if ($suggestion === NULL || !isset($suggestion['field'], $suggestion['suggested'])) {
        continue;
      }
      if ($this->applyToNode($node, (string) $suggestion['field'], (string) $suggestion['suggested'], (string) ($suggestion['current'] ?? ''))) {
        $applied[] = $suggestion['label'] ?? $suggestion['field'];
      }
      else {
        $this->messenger()->addWarning($this->t('Could not apply suggestion for field %field.', ['%field' => $suggestion['field']]));
      }
    }

    if ($applied) {
      $node->setNewRevision(TRUE);
      if ($node instanceof RevisionLogInterface) {
        $node->setRevisionLogMessage('Applied AI suggestions (' . implode(', ', $applied) . ') from validation #' . $validation_id);
        $node->setRevisionUserId((int) $this->currentUser()->id());
        $node->setRevisionCreationTime($this->time->getRequestTime());
      }
      $node->save();
      $validation->set('field_validation_status', 'done')->save();
      $this->messenger()->addStatus($this->t('Applied @count change(s) to %title as a new revision.', [
        '@count' => count($applied),
        '%title' => $node->label(),
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('No changes were applied.'));
    }
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

  /**
   * Truncates long values for the suggestions table.
   */
  private function truncate(string $text, int $length = 300): string {
    return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
  }

}
