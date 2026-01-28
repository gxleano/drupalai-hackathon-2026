<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Provides a list controller for the ai validations entity type.
 */
final class AiValidationsListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['workflow'] = $this->t('Workflow');
    $header['label'] = $this->t('Validation');
    $header['validation_status'] = $this->t('Validation Status');
    $header['uid'] = $this->t('Author');
    $header['created'] = $this->t('Created');
    $header['changed'] = $this->t('Updated');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\ai_content_validation\AiValidationsInterface $entity */
    $row['id'] = $entity->id();
    $workflow = $entity->get('field_flowdrop_workflow')->getValue();
    $flowdrop_id = $workflow[0]['target_id'] ?? '';
    $workflow_label = "";
    if ($flowdrop_id) {
      $flowdrop = \Drupal::entityTypeManager()->getStorage('flowdrop_workflow')?->load($flowdrop_id);
      $workflow_label = $flowdrop->label();
    }
    $row['workflow'] = $workflow_label;
    $row['label'] = $entity->toLink();
    $row['validation_status'] = $entity->get('field_validation_status')->value;
    $username_options = [
      'label' => 'hidden',
      'settings' => ['link' => $entity->get('uid')->entity->isAuthenticated()],
    ];
    $row['uid']['data'] = $entity->get('uid')->view($username_options);
    $row['created']['data'] = $entity->get('created')->view(['label' => 'hidden']);
    $row['changed']['data'] = $entity->get('changed')->view(['label' => 'hidden']);
    return $row + parent::buildRow($entity);
  }

}
