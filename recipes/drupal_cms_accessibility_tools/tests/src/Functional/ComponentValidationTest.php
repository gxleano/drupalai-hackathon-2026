<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_cms_accessibility_tools\Functional;

use Composer\InstalledVersions;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

#[Group('drupal_cms_accessibility_tools')]
#[IgnoreDeprecations]
class ComponentValidationTest extends BrowserTestBase {

  use RecipeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  public function test(): void {
    $dir = realpath(__DIR__ . '/../../..');
    // The recipe should apply cleanly.
    $this->applyRecipe($dir);
    // Apply it again to prove that it is idempotent.
    $this->applyRecipe($dir);

    // Apply the Admin UI recipe so we can test dashboard integration.
    $recipe = InstalledVersions::getInstallPath('drupal/drupal_cms_admin_ui');
    $this->applyRecipe($recipe);

    $account = $this->drupalCreateUser([
      'view welcome dashboard',
      'manage editoria11y results',
    ]);
    // Don't use one-time login links, because they will bypass the dashboard
    // upon login.
    $this->useOneTimeLoginLinks = FALSE;
    $this->drupalLogin($account);

    // We should be on the welcome dashboard, and it should have the Editoria11y
    // block visible.
    $assert_session = $this->assertSession();
    $assert_session->addressEquals('/admin/dashboard');
    $assert_session->elementExists('css', 'h2:contains("Pages with accessibility alerts")');
  }

}
