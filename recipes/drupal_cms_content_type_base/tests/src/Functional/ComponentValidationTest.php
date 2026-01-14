<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_cms_content_type_base\Functional;

use Composer\InstalledVersions;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\taxonomy\Traits\TaxonomyTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

#[Group('drupal_cms_content_type_base')]
#[IgnoreDeprecations]
class ComponentValidationTest extends BrowserTestBase {

  use RecipeTestTrait;
  use TaxonomyTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // ContentTypeCreationTrait expects the `body` field to exist.
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'olivero';

  public function test(): void {
    $dir = realpath(__DIR__ . '/../../..');
    // The recipe should apply cleanly.
    $this->applyRecipe($dir);
    // Apply it again to prove that it is idempotent.
    $this->applyRecipe($dir);

    // Ensure we have a content type to test with.
    $dir = InstalledVersions::getInstallPath('drupal/drupal_cms_page');
    $this->applyRecipe($dir);

    // Unpublished content should return a 404 instead of the default 403.
    $node = $this->drupalCreateNode(['type' => 'page'])->setUnpublished();
    $node->save();
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(404);

    // For performance, this rest of this test is divided into well-scoped
    // helper methods, rather than several test methods with full setup and
    // tear-down.
    $this->doTestComponentsAutomaticallyDisabled();
    $this->doTestTaxonomyTermView();
    $this->doTestContentEditorPermissions();
    // A content type's preview mode should be unaffected if a disabled Canvas
    // content template is created for it in the `full` view mode.
    $this->doTestPreviewModeForCanvasRenderedContentType(FALSE, 'full', NULL);
    // And it should be unaffected by an enabled template in a view mode other
    // than `full`.
    $this->doTestPreviewModeForCanvasRenderedContentType(TRUE, 'teaser', NULL);
    // But an enabled template in the `full` view mode should disable previews.
    $this->doTestPreviewModeForCanvasRenderedContentType(TRUE, 'full', DRUPAL_DISABLED);
  }

  private function doTestComponentsAutomaticallyDisabled(): void {
    // The drupal_cms_admin_ui recipe enables several blocks that we want to
    // disable in Canvas.
    $dir = InstalledVersions::getInstallPath('drupal/drupal_cms_admin_ui');
    $this->applyRecipe($dir);

    $project_browser_blocks = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage('component')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('id', 'block.project_browser_block.', 'STARTS_WITH')
      ->execute();
    $this->assertNotEmpty($project_browser_blocks);

    $reject_list = [
      'block.system_menu_block.navigation-user-links',
      'block.system_menu_block.top-tasks',
      'block.navigation_dashboard',
      'block.navigation_link',
      'block.navigation_shortcuts',
      'block.navigation_user',
      'sdc.navigation.title',
      'block.announce_block',
      'block.dashboard_site_status',
      'block.local_actions_block',
      'block.local_tasks_block',
      'block.system_menu_block.admin',
      'block.system_menu_block.tools',
      'block.system_messages_block',
      'block.views_block.publishing_content-block_drafts',
      'block.views_block.publishing_content-block_scheduled',
      'block.views_block.recent_pages-block_recent_pages',
      'block.dashboard_optional',
      ...$project_browser_blocks,
    ];
    $components = Component::loadMultiple($reject_list);
    // All of these components should exist.
    $this->assertSame(count($reject_list), count($components));
    foreach ($components as $id => $component) {
      $this->assertFalse($component->status(), "Component $id should be disabled.");
    }

    // If a component is re-enabled, that should be respected.
    $components['block.system_messages_block']->enable()->save();
    $this->assertTrue(Component::load('block.system_messages_block')?->status());
  }

  private function doTestTaxonomyTermView(): void {
    // The `tags` vocabulary should exist.
    $vocabulary = Vocabulary::load('tags');
    $this->assertInstanceOf(Vocabulary::class, $vocabulary);
    $tag = $this->createTerm($vocabulary)->id();

    // Create a published page with a tag so that we can test the
    // `taxonomy_term` view.
    $this->drupalCreateNode([
      'type' => 'page',
      'title' => "Card Me",
      'moderation_state' => 'published',
      'field_tags' => [$tag],
    ]);
    $this->drupalGet('/taxonomy/term/' . $tag);
    $assert_session = $this->assertSession();
    // We should be able to see the view as an anonymous user, and it should be
    // using the `card` view mode.
    $assert_session->statusCodeEquals(200);
    $card = $assert_session->elementExists('css', '.node--view-mode-card');
    $assert_session->elementExists('named', ['link', 'Card Me'], $card);
  }

  private function doTestContentEditorPermissions(): void {
    // Create an unpublished page.
    $node = $this->drupalCreateNode(['type' => 'page']);
    $this->assertFalse($node->isPublished());

    // Log in as a content editor and ensure we can see the front page,
    // regardless of its publication status.
    $account = $this->drupalCreateUser();
    $account->addRole('content_editor')->save();
    $this->drupalLogin($account);
    $this->drupalGet($node->toUrl());
    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains($node->getTitle());
    $node->set('moderation_state', 'published')->save();
    $this->assertTrue($node->isPublished());
    $this->getSession()->reload();
    $assert_session->statusCodeEquals(200);

    // Create another unpublished page and ensure we can see it in the
    // list of moderated content.
    $unpublished = $this->drupalCreateNode(['type' => 'page']);
    $this->assertFalse($unpublished->isPublished());
    $this->drupalGet("/admin/content/moderated");
    $assert_session->linkExists($unpublished->getTitle());

    // The trash should be accessible to content editors.
    $this->drupalGet('/admin/content/trash');
    $assert_session->statusCodeEquals(200);
    $this->drupalGet('/admin/content/trash/node');
    $assert_session->statusCodeEquals(200);
  }

  private function doTestPreviewModeForCanvasRenderedContentType(bool $template_status, string $view_mode, ?int $expected_preview_mode): void {
    $node_type = $this->drupalCreateContentType([
      // This is the default preview mode, but specify it here anyway to make
      // our starting state explicit.
      'preview_mode' => DRUPAL_OPTIONAL,
    ]);
    $expected_preview_mode ??= $node_type->getPreviewMode();
    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => $node_type->id(),
      'content_entity_type_view_mode' => $view_mode,
      'status' => $template_status,
      'component_tree' => [],
    ])->save();
    $this->assertSame(
      $expected_preview_mode,
      NodeType::load($node_type->id())?->getPreviewMode(),
    );
  }

}
