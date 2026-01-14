<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_cms_starter\Functional;

use Composer\InstalledVersions;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\JsonSchemaDefinitionsStreamwrapper;
use Drupal\Core\DefaultContent\Exporter;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\drupal_cms_content_type_base\Traits\ContentModelTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

#[Group('drupal_cms_starter')]
#[IgnoreDeprecations]
class SiteTemplateTest extends BrowserTestBase {

  use ContentModelTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function rebuildAll(): void {
    // The rebuild won't succeed without the `json-schema-definitions` stream
    // wrapper. This would normally happen automatically whenever a module is
    // installed, but in this case, all of that has taken place in a separate
    // process, so we need to refresh this process manually.
    // @see canvas_module_preinstall()
    $this->container->get('stream_wrapper_manager')
      ->registerWrapper(
        'json-schema-definitions',
        JsonSchemaDefinitionsStreamwrapper::class,
        JsonSchemaDefinitionsStreamwrapper::getType(),
      );

    parent::rebuildAll();
  }

  public function test(): void {
    // Apply this recipe once. It is a site template and therefore unlikely to
    // be applied again in the real world.
    $dir = InstalledVersions::getInstallPath('drupal/drupal_cms_starter');
    $this->applyRecipe($dir);

    // Confirm that we are shipping config entities for every Canvas component
    // the site is using.
    foreach ($this->getAllComponentsInUse() as $component_id) {
      $this->assertFileExists("$dir/config/canvas.component.$component_id.yml");
    }

    $assert_session = $this->assertSession();
    // A non-existent page should respond with a 404.
    $this->drupalGet('/node/999999');
    $assert_session->statusCodeEquals(404);
    // A forbidden page should redirect us to the login page.
    $this->drupalGet('/admin');
    $this->assertStringContainsString('/user/login?destination=', $this->getUrl());

    // Assign additional permissions so we can ensure that the top tasks are
    // available.
    $account = $this->drupalCreateUser([
      'administer content types',
      'administer modules',
      'administer themes',
      'administer users',
    ]);
    $account->addRole('content_editor')->save();
    // Don't use one-time login links, because they will bypass the dashboard
    // upon login.
    $this->useOneTimeLoginLinks = FALSE;
    $this->drupalLogin($account);
    // We should be on the welcome dashboard, and we should see the lists of
    // recent content and Canvas pages.
    $assert_session->addressEquals('/admin/dashboard');
    $recent_content_list = $assert_session->elementExists('css', 'h2:contains("Recent content")')
      ->getParent();
    $this->assertTrue($recent_content_list->hasLink('Privacy policy'));
    $assert_session->elementExists('css', '.block-views > h2:contains("Recent pages")');

    // The first item in the navigation footer should be the Help link, and it
    // should only appear once.
    $first_footer_item = $assert_session->elementExists('css', '#menu-footer .toolbar-block');
    $assert_session->elementExists('named', ['link', 'Help'], $first_footer_item);
    $assert_session->elementsCount('named', ['link', 'Help'], 1, $first_footer_item->getParent());

    // All top tasks should be available.
    $top_tasks = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage('menu_link_content')
      ->loadByProperties(['menu_name' => 'top-tasks']);
    $this->assertNotEmpty($top_tasks);
    /** @var \Drupal\menu_link_content\MenuLinkContentInterface $link */
    foreach ($top_tasks as $link) {
      $this->drupalGet($link->getUrlObject());
      $this->assertSame(200, $this->getSession()->getStatusCode(), 'The ' . $link->getTitle() . ' top task is unavailable.');
    }

    // If we apply the search recipe, a Canvas component should be created for
    // the search block and it should be available for use.
    $dir = InstalledVersions::getInstallPath('drupal/drupal_cms_search');
    $this->applyRecipe($dir);
    $this->assertTrue(Component::load('block.simple_search_form_block')?->status());

    // Confirm that the `target_uuid` property (implemented by Canvas) is
    // stripped out of exported entity reference fields.
    // @see \Drupal\drupal_cms_helper\EventSubscriber\DefaultContentSubscriber::preExport()
    $node = Node::load(1);
    $this->assertInstanceOf(Node::class, $node);
    $exported = $this->container->get(Exporter::class)->export($node)->data;
    $this->assertStringNotContainsString('target_uuid', serialize($exported));

    // Previews should be disabled for the Page content type, since it is using
    // a Canvas content template. This is done automatically by ECA.
    // @see eca.eca.content_template_disable_preview.yml
    $this->assertSame(DRUPAL_DISABLED, NodeType::load('page')?->getPreviewMode());
  }

  /**
   * Returns IDs of all Canvas components in use on the site.
   *
   * @return iterable<string>
   */
  private function getAllComponentsInUse(): iterable {
    foreach ($this->getAllComponentTrees() as $entity) {
      /** @var \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem $item */
      foreach ($entity->getComponentTree() as $item) {
        yield $item->getComponentId();
      }
    }
  }

  /**
   * Returns all entities that have a Canvas component tree.
   *
   * @return iterable<\Drupal\canvas\Entity\ComponentTreeEntityInterface>
   */
  private function getAllComponentTrees(): iterable {
    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);
    foreach ($entity_type_manager->getDefinitions() as $id => $definition) {
      if ($definition->entityClassImplements(ComponentTreeEntityInterface::class)) {
        yield from $entity_type_manager->getStorage($id)->loadMultiple();
      }
    }
  }

}
