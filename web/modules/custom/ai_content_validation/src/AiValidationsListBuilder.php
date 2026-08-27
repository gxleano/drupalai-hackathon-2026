<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a list controller for the ai validations entity type.
 */
final class AiValidationsListBuilder extends EntityListBuilder {

  /**
   * Workflow labels keyed by workflow id, loaded once per request.
   *
   * @var array<string, string>|null
   */
  private ?array $workflowLabels = NULL;

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    private readonly EntityStorageInterface $workflowStorage,
    private readonly RequestStack $requestStack,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('entity_type.manager')->getStorage('flowdrop_workflow'),
      $container->get('request_stack'),
    );
  }

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

    $flowdrop_id = (string) ($entity->get('field_flowdrop_workflow')->target_id ?? '');
    $row['workflow'] = $this->workflowLabels()[$flowdrop_id] ?? '';

    // Get entity label with fallback to prevent null link text.
    $entityLabel = $entity->label();
    $linkText = is_string($entityLabel) && $entityLabel !== '' ? $entityLabel : $this->t('(No label) #@id', ['@id' => $entity->id()]);
    $row['label'] = $entity->toLink($linkText);

    $row['validation_status'] = $entity->get('field_validation_status')->value ?? '';

    // Check if the owner entity exists before accessing its methods.
    $ownerEntity = $entity->get('uid')->entity;
    $isAuthenticated = $ownerEntity instanceof UserInterface && $ownerEntity->isAuthenticated();
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
   * Loads all workflow labels once instead of one query per row.
   *
   * @return array<string, string>
   *   Labels keyed by workflow id. Workflows are config entities, so a
   *   single loadMultiple() is cheap.
   */
  private function workflowLabels(): array {
    if ($this->workflowLabels === NULL) {
      $this->workflowLabels = array_map(
        static fn ($workflow): string => (string) $workflow->label(),
        $this->workflowStorage->loadMultiple(),
      );
    }
    return $this->workflowLabels;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);

    $flowdrop_id = (string) ($entity->get('field_flowdrop_workflow')->target_id ?? '');

    $revision = $entity->get('field_content_revision')->getValue();
    $target_id = $revision[0]['target_id'] ?? NULL;
    $target_revision_id = $revision[0]['target_revision_id'] ?? NULL;

    // Only add Process operation if workflow ID and revision data are
    // available.
    if ($flowdrop_id !== '' && $target_id !== NULL) {
      $url = Url::fromRoute('flowdrop_node_session.playground.entity', [
        'workflow_id' => $flowdrop_id,
      ], [
        'query' => [
          'entity_type' => 'node',
          'entity_id' => $target_id,
          'revision_id' => $target_revision_id,
        ],
      ]);

      $operations['process'] = [
        'title' => $this->t('Process'),
        'weight' => 20,
        'url' => $url,
      ];
    }

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityListQuery(): QueryInterface {
    $query = parent::getEntityListQuery();
    $flow = $this->requestStack->getCurrentRequest()?->query->get('flow');
    if (!empty($flow)) {
      $query->condition('field_flowdrop_workflow.target_id', $flow);
    }
    return $query;
  }

}
