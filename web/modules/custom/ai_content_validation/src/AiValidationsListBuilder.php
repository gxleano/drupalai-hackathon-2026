<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Url;
use Drupal\Core\Entity\Query\QueryInterface;

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

    // Get the workflow label with null safety.
    $workflow = $entity->get('field_flowdrop_workflow')->getValue();
    $flowdrop_id = $workflow[0]['target_id'] ?? '';
    $workflow_label = '';
    if ($flowdrop_id) {
      $flowdrop = \Drupal::entityTypeManager()->getStorage('flowdrop_workflow')?->load($flowdrop_id);
      // Only get label if the workflow entity exists.
      $workflow_label = $flowdrop?->label() ?? '';
    }
    $row['workflow'] = $workflow_label;

    // Get entity label with fallback to prevent null link text.
    $entityLabel = $entity->label();
    $linkText = is_string($entityLabel) && $entityLabel !== '' ? $entityLabel : $this->t('(No label) #@id', ['@id' => $entity->id()]);
    $row['label'] = $entity->toLink($linkText);

    $row['validation_status'] = $entity->get('field_validation_status')->value ?? '';

    // Check if the owner entity exists before accessing its methods.
    $ownerEntity = $entity->get('uid')->entity;
    $isAuthenticated = $ownerEntity instanceof \Drupal\user\UserInterface && $ownerEntity->isAuthenticated();
    $username_options = [
      'label' => 'hidden',
      'settings' => ['link' => $isAuthenticated],
    ];
    $row['uid']['data'] = $entity->get('uid')->view($username_options);
    $row['created']['data'] = $entity->get('created')->view(['label' => 'hidden']);
    $row['changed']['data'] = $entity->get('changed')->view(['label' => 'hidden']);
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity): array {

    $workflow = $entity->get('field_flowdrop_workflow')->getValue();
    $flowdrop_id = $workflow[0]['target_id'] ?? '';

    $revision = $entity->get('field_content_revision')->getValue();


    $url = Url::fromRoute('echack_flowdrop_node_session.playground.entity', [
      'workflow_id' => $flowdrop_id,
    ], [
      'query' => [
        'entity_type' => 'node',
        'entity_id' => $revision[0]['target_id'],
        'revision_id' =>  $revision[0]['target_revision_id'],
      ],
    ]);

    $operations = parent::getDefaultOperations($entity);
    $operations['process'] = [
      'title' => $this->t('Process'),
      'weight' => 20,
      'url' => $url,
    ];
    return $operations;



  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityListQuery(): QueryInterface {
    $query = parent::getEntityListQuery();
    $flow = \Drupal::request()->query->get('flow');
    if (!empty($flow)) {
      $query->condition('field_flowdrop_workflow.target_id', $flow);
    }
    return $query;
  }

}
