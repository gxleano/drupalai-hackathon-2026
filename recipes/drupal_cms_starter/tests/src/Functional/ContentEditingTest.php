<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_cms_starter\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\drupal_cms_content_type_base\Traits\ContentModelTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

#[Group('drupal_cms_starter')]
#[IgnoreDeprecations]
class ContentEditingTest extends BrowserTestBase {

  use ContentModelTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  public function testMenuSettingsVisibility(): void {
    $content_types = $this->applyAllContentTypeRecipes();

    $account = $this->drupalCreateUser();
    $account->addRole('content_editor')->save();
    $this->drupalLogin($account);

    // For performance, test all these content types in one test, rather than
    // a data provider or #[TestWith] attributes.
    foreach ($content_types as $content_type) {
      $this->drupalGet("/node/add/$content_type");

      // Only pages should have menu settings.
      $this->assertSame(
        $content_type === 'page',
        str_contains($this->getSession()->getPage()->getText(), 'Menu settings'),
      );
      // Verify the form loaded without errors.
      $this->assertSession()->statusCodeEquals(200);
    }
  }

}
