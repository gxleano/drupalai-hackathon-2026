<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Plugin\Block;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ai_content_validation\ValidationFreshness;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dashboard list of content whose AI validation needs editor attention.
 *
 * Two buckets, the same signals as the content-list warning icon: nodes
 * with a pending improve session (AI suggestions awaiting review) and
 * nodes whose latest current report carries an open non-pass field
 * verdict (not overridden by an editor).
 */
#[Block(
  id: 'ai_validation_attention',
  admin_label: new TranslatableMarkup('AI validation: needs attention'),
)]
final class AiValidationAttentionBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $storage = $this->entityTypeManager->getStorage('ai_content_validation_item');
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Nodes with a pending improve session: suggested changes to review.
    $suggested = [];
    $pending_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_validation_status', 'pending')
      ->sort('created', 'DESC')
      ->range(0, 50)
      ->execute();
    foreach ($storage->loadMultiple($pending_ids) as $item) {
      $nid = (int) ($item->get('field_content_revision')->target_id ?? 0);
      $suggested[$nid] = TRUE;
    }

    // Newest done report per node; an open (not overridden) non-pass
    // verdict on a still-current report means the node needs attention.
    // The report's score feeds the row's donut either way.
    $attention = [];
    $scores = [];
    $done_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_validation_status', 'done')
      ->sort('created', 'DESC')
      ->range(0, 200)
      ->execute();
    $items = $storage->loadMultiple($done_ids);
    foreach ($done_ids as $id) {
      $item = $items[$id] ?? NULL;
      if ($item === NULL) {
        continue;
      }
      $nid = (int) ($item->get('field_content_revision')->target_id ?? 0);
      if ($nid === 0 || isset($attention[$nid])) {
        continue;
      }
      $decoded = json_decode((string) ($item->get('field_validation_result')->value ?? ''), TRUE);
      if (!is_array($decoded)) {
        continue;
      }
      $node = $node_storage->load($nid);
      if (!$node instanceof NodeInterface || !$node->access('update')) {
        // Only the newest report speaks for a node — mark it seen either
        // way so an older report cannot resurrect it.
        $attention[$nid] = 0;
        continue;
      }
      $record = [
        'hash' => is_string($decoded['content_hash'] ?? NULL) ? $decoded['content_hash'] : NULL,
        'vid' => (int) ($item->get('field_content_revision')->target_revision_id ?? 0),
        'created' => (int) $item->get('created')->value,
      ];
      $verdicts = is_array($decoded['field_verdicts'] ?? NULL) ? $decoded['field_verdicts'] : [];
      $overrides = is_array($decoded['editor_overrides'] ?? NULL) ? $decoded['editor_overrides'] : [];
      $open = array_filter(
        array_diff_key($verdicts, $overrides),
        static fn ($v) => strtolower(trim((string) $v)) !== 'pass',
      );
      $attention[$nid] = ValidationFreshness::isCurrent($node, $record) ? count($open) : 0;
      if (is_numeric($decoded['score'] ?? NULL)) {
        $scores[$nid] = max(0, min(100, (int) $decoded['score']));
      }
    }
    $attention = array_filter($attention);

    $rows = [];
    foreach (array_keys($suggested) as $nid) {
      $rows[$nid] = $this->t('AI suggestions ready for your review');
    }
    foreach ($attention as $nid => $count) {
      $rows[$nid] ??= $this->formatPlural($count, '1 field needs attention', '@count fields need attention');
    }
    $list = [];
    foreach ($node_storage->loadMultiple(array_keys($rows)) as $nid => $node) {
      if (!$node instanceof NodeInterface || !$node->access('update')) {
        continue;
      }
      $list[] = [
        '#type' => 'inline_template',
        '#template' => '<div class="acv-dash-row">
            {{ donut }}
            <span class="acv-dash-row__body">
              <a class="acv-dash-row__title" href="{{ url }}">{{ title }}</a>
              <span class="acv-dash-row__status">{{ status }}</span>
            </span>
            <a class="acv-dash-row__action" href="{{ url }}">{{ action }}</a>
          </div>',
        '#context' => [
          'url' => Url::fromRoute('ai_content_validation.node_review', ['node' => $nid])->toString(),
          'donut' => $this->donut($scores[$nid] ?? NULL),
          'title' => $node->label(),
          'status' => $rows[$nid],
          'action' => $this->t('Review'),
        ],
      ];
    }

    $build = $list === []
      ? [
        '#type' => 'inline_template',
        '#template' => '<p class="acv-dash-empty"><span class="acv-dash-empty__icon" aria-hidden="true">✓</span> {{ text }}</p>',
        '#context' => [
          'text' => $this->t('All validated content looks good — nothing needs your attention.'),
        ],
      ]
      : [
        '#theme' => 'item_list',
        '#items' => $list,
      ];
    $build['#attached']['library'][] = 'ai_content_validation/dashboard';
    $build['#cache'] = [
      'tags' => ['ai_content_validation_item_list', 'node_list'],
      'contexts' => ['user.permissions'],
    ];
    return $build;
  }

  /**
   * Renders the quality-score donut ring, as on the content list.
   *
   * @param int|null $score
   *   The 0-100 score, or NULL when the node has no scored report.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The donut markup.
   */
  private function donut(?int $score): MarkupInterface {
    if ($score === NULL) {
      $fill = 'var(--gin-border-color, #d4d4d8)';
      $percent = 100;
      $text = '–';
    }
    else {
      $fill = $score >= 80
        ? 'var(--gin-color-green, #26a769)'
        : ($score >= 50 ? 'var(--gin-color-warning, #e29700)' : 'var(--gin-color-danger, #dc2323)');
      $percent = $score;
      $text = $score . '%';
    }
    $ring = 'conic-gradient(' . $fill . ' 0 ' . $percent . '%, var(--gin-border-color, #e5e5e5) ' . $percent . '% 100%)';
    return Markup::create(
      '<span class="acv-dash-row__donut" style="background: ' . $ring . ';">'
      . '<span class="acv-dash-row__donut-center">' . Html::escape($text) . '</span></span>'
    );
  }

}
