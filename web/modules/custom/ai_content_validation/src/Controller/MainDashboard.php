<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Url;

/**
 * Returns responses for ai_content_validation routes.
 */
final class MainDashboard extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(): array {
     $build = [
      "#type" => "container",
      "#cache" => [
        "contexts" => ["user.permissions"],
        "tags" => [
          "ai_content_validation_item_list",
          "flowdrop_workflow_list",
          "config:ai_content_validation.settings",
        ],
        "max-age" => Cache::PERMANENT,
      ],
      "#attributes" => [
        "class" => ["fd-fullscreen-layout"],
      ],
      "#attached" => [
        "library" => [
          "flowdrop_ui_components/fullscreen-layout",
          "flowdrop_ui_components/base",
        ],
      ],
    ];

    // Main content area.
    $build["main"] = [
      "#type" => "container",
      "#attributes" => [
        "class" => ["fd-fullscreen-main", "fd-fullscreen-main--constrained"],
      ],
    ];
    // Stats row.
    $build["main"]["stats"] = $this->buildStatsSection();

    return $build;
  }

  /**
   * Builds the statistics section.
   *
   * @return array<string, mixed>
   *   A render array for the stats section.
   */
  protected function buildStatsSection(): array {
    $section = [
      "#type" => "container",
      "#attributes" => [
        "class" => ["fd-section", "fd-animate-in"],
      ],
    ];

    $flows_storage = $this->entityTypeManager()->getStorage('flowdrop_workflow');
    $enabled_flows = $this->config('ai_content_validation.settings')->get('flows') ?? [];
    $flows = $flows_storage->loadMultiple($enabled_flows);

    // Collect stat card items for the grid.
    $statItems = [];

    foreach ($flows as $flow) {
      $flow_id = $flow->id();
      $count = $this->getValidationCount($flow_id);
      $url = Url::fromRoute('entity.ai_content_validation_item.collection', [], [
        'query' => ['flow' => $flow_id],
      ])->toString();
      $statItems[$flow_id] = [
        "#type" => "component",
        "#component" => "flowdrop_ui_components:stat-card",
        "#props" => [
          "value" => (string) $count,
          "label" => (string) $flow->label(),
          "variant" => "default",
          "url" => $url,
          "icon" => $this->getAccessibilityIcon(),
        ],
      ];
    }

    // Use the grid component for consistent responsive behavior.
    $section["stats"] = [
      "#type" => "component",
      "#component" => "flowdrop_ui_components:grid",
      "#props" => [
        "variant" => "stats",
        "stagger" => TRUE,
      ],
      "#slots" => [
        "default" => $statItems ?? [],
      ],
    ];

    return $section;
  }

  /**
   * Gets the count of entities of a given type.
   *
   * @param string $validation_id
   *   The validation ID.
   *
   * @return int
   *   The entity count, or 0 if the entity type doesn't exist or table is
   *   missing.
   */
  protected function getValidationCount(string $validation_id): int {
    try {
      // Check if the entity type exists first.
      $validation_storage = $this->entityTypeManager()->getStorage('ai_content_validation_item');

      // @todo Filter by not successful validations.
      return (int) $validation_storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('field_flowdrop_workflow.target_id', $validation_id)
        ->count()
        ->execute();
    }
    catch (\Exception $e) {
      // Handle cases where the database table doesn't exist yet.
      return 0;
    }
  }

  /**
   * Returns workflow icon SVG.
   *
   * @return string
   *   The SVG markup.
   */
  protected function getAccessibilityIcon(): string {
    return '<svg xmlns="http://www.w3.org/2000/svg" style="transform: rotate(90deg);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
  <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z" />
</svg>
';
  }
}
