<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Hook;

use Drupal\ai_content_validation\Form\AiReviewForm;
use Drupal\ai_content_validation\ValidatedFields;
use Drupal\ai_content_validation\ValidationFreshness;
use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Entity, user, views and theme hooks for AI content validation.
 */
final class AiContentValidationHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClassResolverInterface $classResolver,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    #[Autowire(service: 'extension.list.theme')]
    private readonly ThemeExtensionList $themeList,
    private readonly AccountProxyInterface $currentUser,
    #[Autowire(service: 'tempstore.private')]
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * Implements hook_page_attachments().
   *
   * Attaches the poller to the first page the editor sees after a save
   * that queued a validation, and consumes the flag so only that page
   * polls. The poller reloads once the fresh report has landed.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $store = $this->tempStoreFactory->get('ai_content_validation');
    $nid = $store->get('pending_nid');
    if ($nid === NULL) {
      return;
    }
    $store->delete('pending_nid');
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface) {
      return;
    }
    $attachments['#attached']['library'][] = 'ai_content_validation/validation_poll';
    $attachments['#attached']['drupalSettings']['aiContentValidation']['pending'] = [
      'url' => Url::fromRoute('ai_content_validation.node_validation_state', ['node' => $nid])->toString(),
    ];
    // The page cache must not serve this page (with its one-shot poller)
    // to anyone else.
    $attachments['#cache']['max-age'] = 0;
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'ai_content_validation_item' => ['render element' => 'elements'],
    ];
  }

  /**
   * Implements hook_entity_operation_alter().
   *
   * Replaces the per-workflow playground operations added by
   * flowdrop_node_session with a single "AI Validation" link to the review
   * page, where workflows are run and their suggestions can be applied.
   */
  #[Hook('entity_operation_alter')]
  public function entityOperationAlter(array &$operations, EntityInterface $entity): void {
    if ($entity->getEntityTypeId() !== 'node') {
      return;
    }
    $replaced = FALSE;
    foreach (array_keys($operations) as $key) {
      if (str_starts_with((string) $key, 'flowdrop_')) {
        unset($operations[$key]);
        $replaced = TRUE;
      }
    }
    if ($replaced) {
      $operations['ai_review'] = [
        'title' => $this->t('AI Validation'),
        'url' => Url::fromRoute('ai_content_validation.node_review', ['node' => $entity->id()]),
        'weight' => 50,
      ];
    }
  }

  /**
   * Implements hook_user_cancel().
   */
  #[Hook('user_cancel')]
  public function userCancel(array $edit, UserInterface $account, string $method): void {
    $storage = $this->entityTypeManager->getStorage('ai_content_validation_item');
    switch ($method) {
      case 'user_cancel_block_unpublish':
        // Unpublish ai validations.
        $ids = $storage->getQuery()
          ->condition('uid', $account->id())
          ->condition('status', 1)
          ->accessCheck(FALSE)
          ->execute();
        foreach ($storage->loadMultiple($ids) as $item) {
          $item->set('status', FALSE)->save();
        }
        break;

      case 'user_cancel_reassign':
        // Anonymize ai validations.
        $ids = $storage->getQuery()
          ->condition('uid', $account->id())
          ->accessCheck(FALSE)
          ->execute();
        foreach ($storage->loadMultiple($ids) as $item) {
          $item->setOwnerId(0)->save();
        }
        break;
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_predelete() for user entities.
   */
  #[Hook('user_predelete')]
  public function userPredelete(UserInterface $account): void {
    // Delete ai validations that belong to this account.
    $storage = $this->entityTypeManager->getStorage('ai_content_validation_item');
    $ids = $storage->getQuery()
      ->condition('uid', $account->id())
      ->accessCheck(FALSE)
      ->execute();
    $storage->delete($storage->loadMultiple($ids));
  }

  /**
   * Implements hook_views_pre_render().
   *
   * Looks up pending AI validation counts for all nodes in the content
   * overview in one aggregated query, and attaches the validation list cache
   * tag so the view refreshes when validations change.
   */
  #[Hook('views_pre_render')]
  public function viewsPreRender(ViewExecutable $view): void {
    if ($view->id() !== 'content') {
      return;
    }
    $nids = [];
    foreach ($view->result as $row) {
      $entity = $row->_entity ?? NULL;
      if ($entity instanceof NodeInterface) {
        $nids[] = (int) $entity->id();
      }
    }
    $counts = &drupal_static('ai_content_validation_pending_counts', []);
    $scores = &drupal_static('ai_content_validation_latest_scores', []);
    if ($nids !== []) {
      $storage = $this->entityTypeManager->getStorage('ai_content_validation_item');
      $results = $storage->getAggregateQuery()
        // System-wide indicator, not user-scoped data.
        ->accessCheck(FALSE)
        ->condition('field_content_revision.target_id', $nids, 'IN')
        ->condition('field_validation_status', 'pending')
        ->groupBy('field_content_revision.target_id')
        ->aggregate('id', 'COUNT')
        ->execute();
      foreach ($results as $result) {
        $nid = (int) ($result['field_content_revision_target_id'] ?? 0);
        $counts[$nid] = (int) ($result['id_count'] ?? 0);
      }

      // Newest accepted quality score per node: only done items count — a
      // pending score is provisional until the editor accepts it, ignored
      // and superseded ones never apply. The score lives inside the JSON
      // result field, so items are loaded and parsed; newest-first order
      // means the first parsable score per node wins.
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_content_revision.target_id', $nids, 'IN')
        ->condition('field_validation_status', 'done')
        ->sort('created', 'DESC')
        ->range(0, 200)
        ->execute();
      $items = $storage->loadMultiple($ids);
      foreach ($ids as $id) {
        $item = $items[$id] ?? NULL;
        if ($item === NULL) {
          continue;
        }
        $nid = (int) ($item->get('field_content_revision')->target_id ?? 0);
        if ($nid === 0 || isset($scores[$nid])) {
          continue;
        }
        $decoded = json_decode((string) ($item->get('field_validation_result')->value ?? ''), TRUE);
        if (is_string($decoded)) {
          $decoded = json_decode($decoded, TRUE);
        }
        $score = is_array($decoded) ? ($decoded['score'] ?? NULL) : NULL;
        if (is_numeric($score)) {
          // The content hash travels along so the donut can flag a score
          // whose content has changed since it was validated.
          $verdicts = is_array($decoded['scores'] ?? NULL) ? $decoded['scores'] : [];
          $scores[$nid] = [
            'score' => (int) $score,
            'vid' => (int) ($item->get('field_content_revision')->target_revision_id ?? 0),
            'created' => (int) $item->get('created')->value,
            // The hash the score was computed from: a save that changed
            // no validated value keeps the score current.
            'hash' => is_string($decoded['content_hash'] ?? NULL) ? $decoded['content_hash'] : NULL,
            // Any verdict below a full pass means the report suggests
            // improvements — the content list flags it.
            'weak' => (bool) array_filter(
              $verdicts,
              fn ($v) => is_numeric($v) ? (int) $v < 10 : strtolower(trim((string) $v)) !== 'pass',
            ),
          ];
        }
      }
    }
    $view->element['#cache']['tags'][] = 'ai_content_validation_item_list';
  }

  /**
   * Implements hook_views_data_alter().
   *
   * Exposes the AI quality score donut as a views field on nodes, used by
   * the Quality Score column in the content overview.
   */
  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data): void {
    $data['node_field_data']['ai_quality_score'] = [
      'title' => $this->t('AI Quality Score'),
      'help' => $this->t('Latest AI validation quality score as a colored donut ring.'),
      'field' => [
        'id' => 'ai_quality_score',
      ],
    ];
  }

  /**
   * Implements hook_preprocess_HOOK() for views-view-field.html.twig.
   *
   * Appends a warning icon after the title in the content overview when the
   * node has pending AI validations. Hovering shows the count, clicking goes
   * to the node's AI Validation page.
   */
  #[Hook('preprocess_views_view_field')]
  public function preprocessViewsViewField(array &$variables): void {
    if ($variables['view']->id() !== 'content' || $variables['field']->field !== 'title') {
      return;
    }
    $entity = $variables['row']->_entity ?? NULL;
    if (!$entity instanceof NodeInterface) {
      return;
    }
    $counts = drupal_static('ai_content_validation_pending_counts', []);
    $count = $counts[(int) $entity->id()] ?? 0;
    // Manual validations auto-accept, so pending items alone no longer cover
    // the "needs attention" cases: a current report whose verdicts are not
    // all pass ("Improvements suggested") warrants the icon too.
    $record = drupal_static('ai_content_validation_latest_scores', [])[(int) $entity->id()] ?? NULL;
    $current = $record !== NULL && ValidationFreshness::isCurrent($entity, $record);
    $weak = $current && !empty($record['weak']);
    if ($count === 0 && !$weak) {
      return;
    }

    $info = $count > 0
      ? $this->formatPlural(
        $count,
        '1 pending AI validation needs your attention — click to review.',
        '@count pending AI validations need your attention — click to review.'
      )
      : $this->t('The AI validation suggested improvements for this content — click to review.');
    $url = Url::fromRoute('ai_content_validation.node_review', ['node' => $entity->id()])->toString();
    // The Gin status-report warning octagon: its sprite masked over the theme
    // warning color, mirroring .system-status-report__status-icon--warning.
    $sprite = '/' . $this->themeList->getPath('gin') . '/dist/media/sprite.svg#warning-view';
    $mask = 'mask-image: url(' . $sprite . '); mask-repeat: no-repeat; mask-position: center; mask-size: 16px;';
    $icon = '<span style="display: inline-block; width: 16px; height: 16px; vertical-align: text-bottom;'
      . ' background-color: var(--gin-color-warning, #e29700);'
      . ' -webkit-' . $mask . ' ' . $mask . '"></span>';
    $variables['output'] = Markup::create(
      (string) $variables['output']
      . ' <a href="' . Html::escape($url) . '" title="' . Html::escape((string) $info) . '"'
      . ' aria-label="' . Html::escape((string) $info) . '" style="text-decoration: none;">' . $icon . '</a>'
    );
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for node entities.
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    $this->validateOnSave($node);
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for node entities.
   */
  #[Hook('node_update')]
  public function nodeUpdate(NodeInterface $node): void {
    $this->validateOnSave($node);
  }

  /**
   * Runs the AI validation for a just-saved node, after the response.
   *
   * The editor never has to click a button: every create or save of
   * validated content produces a fresh report. The model call takes
   * 30-60 seconds, so it runs in a shutdown function — under FPM that is
   * after the response has been flushed, so saving stays instant. Runs
   * whose content hash matches a current report are skipped (memoized),
   * which also stops the double run after an applied suggestion (the
   * review flow already re-validates synchronously).
   */
  private function validateOnSave(NodeInterface $node): void {
    // Only content the validation workflow actually covers: a bundle with
    // nothing but the title has no field for the validator to assess.
    if (count(ValidatedFields::labels($node)) < 2) {
      return;
    }
    // Validation writes entities and spends money at a model endpoint, so
    // it is a granted capability, not a side effect of saving a node. A
    // CLI save (drush, cron, a migration) has no account to grant it to
    // and is trusted by definition — a web request never is, whoever it
    // claims to be.
    $system_context = PHP_SAPI === 'cli';
    if (!$system_context && !$this->currentUser->hasPermission('run ai content validation')) {
      return;
    }
    $registered = &drupal_static(__METHOD__, []);
    if (isset($registered[(int) $node->id()])) {
      return;
    }
    $registered[(int) $node->id()] = TRUE;
    // The run happens after the response: tell the editor, and flag the
    // next page so it polls and reloads when the report lands.
    $this->tempStoreFactory->get('ai_content_validation')->set('pending_nid', (int) $node->id());
    $operations = $this->configFactory->get('flowdrop_node_session.settings')->get('entity_operations') ?: [];
    if ($operations === []) {
      return;
    }
    $workflow_id = (string) $operations[0]['workflow_id'];
    $review = $this->classResolver->getInstanceFromDefinition(AiReviewForm::class);
    $switcher = $this->accountSwitcher;
    $logger = $this->loggerFactory->get('ai_content_validation');
    // A user-less save has no account to run as, so it borrows the site
    // account — a LOADED user entity, never a fabricated session with
    // asserted roles, so a blocked or deleted account cannot be
    // impersonated and the run answers to real access checks. A save made
    // by a real user simply runs as that user.
    $system = NULL;
    if ($system_context || $this->currentUser->isAnonymous()) {
      $account = $this->entityTypeManager->getStorage('user')->load(1);
      $system = $account instanceof AccountInterface ? $account : NULL;
      if ($system === NULL) {
        return;
      }
    }
    drupal_register_shutdown_function(static function () use ($node, $workflow_id, $review, $switcher, $logger, $system): void {
      if ($system !== NULL) {
        $switcher->switchTo($system);
      }
      try {
        if ($review->cachedReport($node, $workflow_id) === NULL) {
          $review->runAndAccept($node, $workflow_id, TRUE);
        }
      }
      catch (\Throwable $e) {
        $logger->error('Post-save validation failed for node @nid: @message', [
          '@nid' => $node->id(),
          '@message' => $e->getMessage(),
        ]);
      }
      finally {
        if ($system !== NULL) {
          $switcher->switchBack();
        }
      }
    });
  }

}
