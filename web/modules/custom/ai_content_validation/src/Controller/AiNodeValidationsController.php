<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;

/**
 * Returns responses for ai_content_validation routes.
 */
final class AiNodeValidationsController extends ControllerBase {

  /**
   * All revision validations.
   */
  public function revisionValidations(NodeInterface $node, string $node_revision): array {
    $ids = [];
    $validations_storage = \Drupal::entityTypeManager()->getStorage('ai_content_validation_item');
    $ids['pending'] = $validations_storage->getQuery()
      ->condition('field_content_revision', $node_revision)
      ->condition('field_validation_status', 'pending')
      ->accessCheck(TRUE)
      ->execute();
    $ids['done'] = $validations_storage->getQuery()
      ->condition('field_content_revision', $node_revision)
      ->condition('field_validation_status', 'done')
      ->accessCheck(TRUE)
      ->execute();
    $ids['ignored'] = $validations_storage->getQuery()
      ->condition('field_content_revision', $node_revision)
      ->condition('field_validation_status', 'ignored')
      ->accessCheck(TRUE)
      ->execute();
    $build = [];
    $rows = [];
    foreach ($ids as $type => $validations_ids) {
      $validations = $validations_storage->loadMultiple($validations_ids);
      foreach ($validations as $validation)  {
        $workflow = $validation->get('field_flowdrop_workflow')->getValue();
        $flowdrop_id = $workflow[0]['target_id'] ?? '';
        $rows[] = [
          'status' => $type,
          'worflow' => $flowdrop_id,
          'actions' => \Drupal\Core\Url::fromRoute('<front>'),
        ];
      }
    }
    $build['content'] = [
      '#type' => 'table',
      '#header' => [$this->t('Status'), $this->t('Workflow'), $this->t('Actions')],
      '#rows' => $rows,
      '#empty' => $this->t('No menus available.'),
    ];

    return $build;
  }

}
