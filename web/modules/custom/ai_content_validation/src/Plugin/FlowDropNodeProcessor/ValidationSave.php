<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Plugin\FlowDropNodeProcessor;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop_node_processor\Plugin\FlowDropNodeProcessor\EntitySave;

/**
 * Saves a validation item and supersedes prior pending items.
 *
 * Behaves exactly like the generic Entity Save node, but after a new
 * ai_content_validation_item is created it flags every other still
 * pending item for the same node revision and workflow as superseded,
 * so reviewers only ever see the latest proposal. Items already marked
 * done or ignored are never touched.
 *
 * One kind of item is exempt: the outcome the AI Improve non-regression
 * gate writes when it discards a proposal. Such an item carries an EMPTY
 * suggestions array, which makes it look exactly like a score report to
 * the rule above — and a rejected improvement must never retire the real
 * report (nor a still-applicable earlier proposal). It is neither a report
 * nor a proposal, so it supersedes nothing at all.
 */
#[FlowDropNodeProcessor(
  id: 'validation_save',
  label: new TranslatableMarkup('Save Validation'),
  description: 'Create a validation entry and supersede prior pending ones',
  version: '1.0.0',
)]
final class ValidationSave extends EntitySave {

  /**
   * The entity type this processor supersedes items for.
   */
  private const ENTITY_TYPE = 'ai_content_validation_item';

  /**
   * Outcome markers of items that are neither a report nor a proposal.
   *
   * @var list<string>
   */
  private const NON_SUPERSEDING_OUTCOMES = [
    ImproveGate::OUTCOME_REJECTED,
    ImproveGate::OUTCOME_NO_SUGGESTIONS,
  ];

  /**
   * Tells whether a result payload is a gate outcome that retires nothing.
   *
   * @param array<string, mixed> $result
   *   The decoded field_validation_result payload.
   *
   * @return bool
   *   TRUE for a discarded AI Improve outcome — an item that carries no
   *   score for the node's current content and no applicable suggestion,
   *   and therefore replaces neither a report nor a proposal.
   */
  public static function isGateOutcome(array $result): bool {
    $outcome = $result['outcome'] ?? NULL;
    return is_string($outcome) && in_array($outcome, self::NON_SUPERSEDING_OUTCOMES, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $output = parent::process($params);
    $output['superseded_count'] = $this->supersedePendingItems($output);
    return $output;
  }

  /**
   * Marks prior pending items for the same node and workflow superseded.
   *
   * @param array<string, mixed> $output
   *   The output of the parent Entity Save processor.
   *
   * @return int
   *   The number of items that were superseded.
   */
  private function supersedePendingItems(array $output): int {
    if (($output['entity_type'] ?? '') !== self::ENTITY_TYPE) {
      return 0;
    }
    if (empty($output['is_new']) || empty($output['entity_id'])) {
      return 0;
    }

    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE);
    $saved = $storage->load($output['entity_id']);
    if ($saved === NULL) {
      return 0;
    }

    // A discarded improvement is not a new verdict on the content: it is
    // a record that the model could not improve it. Letting it supersede
    // anything would silently retire the editor's real score report,
    // which an empty suggestions array otherwise looks identical to.
    $raw = (string) ($saved->get('field_validation_result')->value ?? '');
    $decoded = json_decode($raw, TRUE);
    if (is_string($decoded)) {
      $decoded = json_decode($decoded, TRUE);
    }
    if (is_array($decoded) && self::isGateOutcome($decoded)) {
      return 0;
    }

    $revisionId = $saved->get('field_content_revision')->target_id ?? NULL;
    $workflowId = $saved->get('field_flowdrop_workflow')->target_id ?? NULL;
    if ($revisionId === NULL || $workflowId === NULL) {
      return 0;
    }

    // A new item replaces prior still-undecided (pending) items of the
    // same workflow. Done items survive here: accepting a new score is a
    // UI decision that supersedes the previously accepted one in the form.
    // System-level state transition triggered by a workflow: the acting
    // account is irrelevant, so entity access is intentionally bypassed.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_content_revision.target_id', $revisionId)
      ->condition('field_flowdrop_workflow', $workflowId)
      ->condition('field_validation_status', 'pending')
      ->condition('id', $saved->id(), '<>')
      ->execute();

    if (empty($ids)) {
      return 0;
    }

    foreach ($storage->loadMultiple($ids) as $item) {
      $item->set('field_validation_status', 'superseded');
      $item->save();
    }

    return count($ids);
  }

}
