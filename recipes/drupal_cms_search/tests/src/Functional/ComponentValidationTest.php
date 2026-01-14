<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_cms_search\Functional;

use Drupal\Core\State\StateInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\drupal_cms_content_type_base\Traits\ContentModelTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

#[Group('drupal_cms_search')]
#[IgnoreDeprecations]
class ComponentValidationTest extends BrowserTestBase {

  use ContentModelTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  public function testContentIsIndexed(): void {
    // For performance, test all these content types in one test, rather than
    // a data provider or #[TestWith] attributes.
    $content_types = $this->applyAllContentTypeRecipes();

    // Apply the search recipe twice to prove that it applies cleanly and is
    // idempotent.
    $dir = realpath(__DIR__ . '/../../..');
    $this->applyRecipe($dir);
    $this->applyRecipe($dir);

    foreach ($content_types as $node_type) {
      $this->drupalCreateNode([
        'type' => $node_type,
        'title' => "published and included $node_type",
        'moderation_state' => 'published',
        'sae_exclude' => FALSE,
        // Make the node owned by user 1 so we can prove that it is visible to
        // anonymous users when published and searched for.
        'uid' => 1,
      ]);
      $this->drupalCreateNode([
        'type' => $node_type,
        'title' => "unpublished but included $node_type",
        'moderation_state' => 'draft',
        'sae_exclude' => FALSE,
        'uid' => 1,
      ]);
      $this->drupalCreateNode([
        'type' => $node_type,
        'title' => "published and excluded $node_type",
        'moderation_state' => 'published',
        'sae_exclude' => TRUE,
        'uid' => 1,
      ]);
      $this->drupalCreateNode([
        'type' => $node_type,
        'title' => "unpublished and excluded $node_type",
        'moderation_state' => 'draft',
        'sae_exclude' => TRUE,
        'uid' => 1,
      ]);
    }

    // Reset the last cron run time, so we can prove that the next request
    // triggers a cron run.
    $last_run = 0;
    $state = $this->container->get(StateInterface::class);
    $state->set('system.cron_last', $last_run);
    $this->drupalGet('/search');
    $seconds_waited = 0;
    // Give cron up to a minute.
    while (empty($last_run) && $seconds_waited < 60) {
      sleep(1);
      $seconds_waited++;
      $last_run = $state->get('system.cron_last');
      $state->resetCache();
    }
    $this->assertGreaterThan(0, $last_run);

    // Ensure that we can search for the content we just created.
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();
    foreach ($content_types as $node_type) {
      $page->fillField('Search keywords', $node_type);
      $page->pressButton('Find');
      $assert_session->linkExists("published and included $node_type");
      $assert_session->linkNotExists("unpublished but included $node_type");
      $assert_session->linkNotExists("published and excluded $node_type");
      $assert_session->linkNotExists("unpublished and excluded $node_type");
    }
  }

}
