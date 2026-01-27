<?php

declare(strict_types=1);

namespace Drupal\echack_flowdrop_node_session\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\echack_flowdrop_node_session\Service\NodeSessionService;
use Drupal\flowdrop_playground\Entity\FlowDropPlaygroundMessageInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Event subscriber to inject entity context into playground messages.
 *
 * This subscriber hooks into the entity lifecycle to inject entity context
 * data into playground messages when they are created for sessions that have
 * entity context configured.
 *
 * The injection happens via the message's metadata['inputs'] field, which
 * is merged into initialData by PlaygroundService::buildInitialData().
 */
class EntityContextInjectorSubscriber implements EventSubscriberInterface {

  /**
   * Constructs an EntityContextInjectorSubscriber object.
   *
   * @param \Drupal\echack_flowdrop_node_session\Service\NodeSessionService $nodeSessionService
   *   The node session service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected readonly NodeSessionService $nodeSessionService,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // We'll use hook_entity_presave instead since Drupal's core entity
    // events are more reliable. This class is kept for potential future use
    // with custom FlowDrop events.
    return [];
  }

  /**
   * Injects entity context into a playground message.
   *
   * This method is called from hook_entity_presave() in the .module file.
   *
   * @param \Drupal\flowdrop_playground\Entity\FlowDropPlaygroundMessageInterface $message
   *   The message entity being saved.
   */
  public function injectEntityContext(FlowDropPlaygroundMessageInterface $message): void {
    // Only process user messages (these trigger workflow execution).
    if ($message->getRole() !== "user") {
      return;
    }

    // Only process new messages (not updates).
    if (!$message->isNew()) {
      return;
    }

    // Get the session.
    $session = $message->getSession();
    if ($session === NULL) {
      return;
    }

    // Check if session has entity context.
    if (!$this->nodeSessionService->hasEntityContext($session)) {
      return;
    }

    // Get the workflow to find EntityContext nodes.
    $workflow = $session->getWorkflow();
    if ($workflow === NULL) {
      return;
    }

    // Build entity context initial data for EntityContext nodes.
    $workflowData = [
      "nodes" => $workflow->getNodes(),
    ];

    $entityContextData = $this->nodeSessionService->buildEntityContextInitialData(
      $session,
      $workflowData
    );

    if (empty($entityContextData)) {
      return;
    }

    // Merge entity context into message metadata inputs.
    // This gets merged into initialData by PlaygroundService::buildInitialData().
    $metadata = $message->getMessageMetadata();
    $existingInputs = $metadata["inputs"] ?? [];

    // Merge our entity context data with any existing inputs.
    $metadata["inputs"] = array_merge($existingInputs, $entityContextData);

    $message->setMessageMetadata($metadata);
  }

}
