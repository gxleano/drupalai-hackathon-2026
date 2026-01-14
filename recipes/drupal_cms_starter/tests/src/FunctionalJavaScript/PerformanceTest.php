<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_cms_starter\FunctionalJavascript;

use Composer\InstalledVersions;
use Drupal\canvas\JsonSchemaDefinitionsStreamwrapper;
use Drupal\FunctionalJavascriptTests\PerformanceTestBase;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Tests the performance of the drupal_cms_starter recipe.
 *
 * Stark is used as the default theme so that this test is not Olivero specific.
 */
#[Group('OpenTelemetry')]
#[Group('#slow')]
#[RequiresPhpExtension('apcu')]
#[IgnoreDeprecations]
class PerformanceTest extends PerformanceTestBase {

  use RecipeTestTrait;

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

  /**
   * Tests performance of the starter recipe.
   */
  public function testPerformance(): void {
    $dir = InstalledVersions::getInstallPath('drupal/drupal_cms_starter');
    $this->applyRecipe($dir);

    // Applying the recipe installs automated cron, but we don't want cron to
    // run in the middle of a performance test, so uninstall it.
    \Drupal::service('module_installer')->uninstall(['automated_cron']);
    $this->doTestAnonymousFrontPage();
    $this->doTestEditorFrontPage();
  }

  /**
   * Check the anonymous front page with a hot cache.
   */
  protected function doTestAnonymousFrontPage(): void {
    $this->drupalGet('');
    // Allow time for asset and image derivative requests, and end of request
    // tasks to complete.
    sleep(2);
    $this->drupalGet('');
    sleep(2);

    // Test frontpage.
    $performance_data = $this->collectPerformanceData(function () {
      $this->drupalGet('');
    }, 'drupalCMSAnonymousFrontPage');

    $expected = [
      'QueryCount' => 0,
      'CacheGetCount' => 2,
      'CacheSetCount' => 0,
      'CacheTagLookupQueryCount' => 1,
      'StylesheetCount' => 5,
      'StylesheetBytes' => 61000,
      'ScriptCount' => 4,
      'ScriptBytes' => 14000,
    ];
    $this->assertMetrics($expected, $performance_data);
    $this->assertSession()->pageTextContains('Your content goes here');
  }

  /**
   * Log in with the editor role and visit the front page with a warm cache.
   */
  protected function doTestEditorFrontPage(): void {
    $editor = $this->drupalCreateUser();
    $editor->addRole('content_editor')->save();
    $this->drupalLogin($editor);
    // Warm various caches.
    $this->drupalGet('user/2');
    // Allow time for asset and image derivative requests, and end of request
    // tasks to complete.
    sleep(1);
    $this->drupalGet('user/2');
    sleep(1);

    // Test frontpage.
    $performance_data = $this->collectPerformanceData(function () {
      $this->drupalGet('user/2');
    }, 'drupalCMSEditorFrontPage');
    $this->assertSession()->elementAttributeContains('named', ['link', 'Dashboard'], 'class', 'toolbar-button--icon--navigation-dashboard');
    $this->assertSession()->pageTextContains('Member for');

    // The following queries are the only database queries executed for editors on the
    // front page.
    $queries = [
      'SELECT "session" FROM "sessions" WHERE "sid" = "SESSION_ID" LIMIT 0, 1',
      'SELECT * FROM "users_field_data" "u" WHERE "u"."uid" = "2" AND "u"."default_langcode" = 1',
      'SELECT "roles_target_id" FROM "user__roles" WHERE "entity_id" = "2"',
      'SELECT "base_table"."id" AS "id", "base_table"."path" AS "path", "base_table"."alias" AS "alias", "base_table"."langcode" AS "langcode" FROM "path_alias" "base_table" WHERE ("base_table"."status" = 1) AND ("base_table"."alias" LIKE "/user/2" ESCAPE \'\\\\\') AND ("base_table"."langcode" IN ("en", "und")) ORDER BY "base_table"."langcode" ASC, "base_table"."id" DESC',
      'SELECT rid FROM "redirect" WHERE hash IN ("BBl_LK6WJ9pHpq8pOZk0UPGAg_j8q2V5cMW90xdBwkA", "LqXKapiRXY4tnrX1snQ-dOWL3vPG5j41xz2aKX8HFRc") AND enabled = 1 ORDER BY LENGTH(redirect_source__query) DESC',
      'SELECT "name", "value" FROM "key_value" WHERE "name" IN ( "theme:mercury" ) AND "collection" = "config.entity.key_store.page_region"',
      'SELECT "name", "value" FROM "key_value" WHERE "name" IN ( "theme:mercury" ) AND "collection" = "config.entity.key_store.page_region"',
      'SELECT "menu_tree"."id" AS "id" FROM "menu_tree" "menu_tree" WHERE ("menu_name" = "footer") AND ("expanded" = 1) AND ("has_children" = 1) AND ("enabled" = 1) AND ("parent" IN ("")) AND ("id" NOT IN (""))',
      'SELECT "menu_tree"."id" AS "id" FROM "menu_tree" "menu_tree" WHERE ("menu_name" = "main") AND ("expanded" = 1) AND ("has_children" = 1) AND ("enabled" = 1) AND ("parent" IN ("")) AND ("id" NOT IN (""))',
      'SELECT "config"."name" AS "name" FROM "config" "config" WHERE ("collection" = "") AND ("name" LIKE "klaro.klaro_app.%" ESCAPE \'\\\\\') ORDER BY "collection" ASC, "name" ASC',
      'SELECT "base_table"."path" AS "path", "base_table"."alias" AS "alias" FROM "path_alias" "base_table" WHERE ("base_table"."status" = 1) AND ("base_table"."path" LIKE "/page/1" ESCAPE \'\\\\\') AND ("base_table"."langcode" IN ("en", "und")) ORDER BY "base_table"."langcode" ASC, "base_table"."id" DESC',
      'SELECT "base_table"."path" AS "path", "base_table"."alias" AS "alias" FROM "path_alias" "base_table" WHERE ("base_table"."status" = 1) AND ("base_table"."path" LIKE "/node/1" ESCAPE \'\\\\\') AND ("base_table"."langcode" IN ("en", "und")) ORDER BY "base_table"."langcode" ASC, "base_table"."id" DESC',
    ];

    // To avoid a test failure when a database query is removed, check only
    // that a new database query has not been added.
    $this->assertSame($queries, $performance_data->getQueries());
    $query_diff = array_diff($performance_data->getQueries(), $queries);
    $this->assertSame([], $query_diff);

    $expected = [
      'QueryCount' => 12,
      'CacheGetCount' => 81,
      'CacheSetCount' => 0,
      'CacheTagLookupQueryCount' => 8,
      // If there are small changes in the below limits, e.g. under 5kb, the
      // ceiling can be raised without any investigation. However large increases
      // indicate a large library is newly loaded for authenticated users.
      'StylesheetCount' => 7,
      'StylesheetBytes' => 224500,
      'ScriptCount' => 8,
      'ScriptBytes' => 238500,
    ];
    $this->assertMetrics($expected, $performance_data);
  }

}
