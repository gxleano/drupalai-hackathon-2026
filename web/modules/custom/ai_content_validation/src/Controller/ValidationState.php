<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Controller;

use Drupal\ai_content_validation\Form\AiReviewForm;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports whether a node's validation report matches its current content.
 *
 * The post-save validation runs after the response is flushed, so the page
 * the editor lands on still shows the previous score. The score widgets
 * poll this endpoint and reload once the fresh report has landed.
 */
final class ValidationState extends ControllerBase {

  public function __construct(
    private readonly ClassResolverInterface $classResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('class_resolver'));
  }

  /**
   * Returns the node's validation freshness as JSON.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being watched.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   `{"current": bool}` — TRUE once a report for this exact content
   *   exists, which is the signal for the poller to reload the page.
   */
  public function freshness(NodeInterface $node): CacheableJsonResponse {
    $review = $this->classResolver->getInstanceFromDefinition(AiReviewForm::class);
    $current = $review->reportIsCurrent($node, $review->latestReport($node));

    $response = new CacheableJsonResponse(['current' => $current]);
    // Polled state: never cached, or the poller would read its own first
    // answer until the page cache expires.
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));

    return $response;
  }

}
