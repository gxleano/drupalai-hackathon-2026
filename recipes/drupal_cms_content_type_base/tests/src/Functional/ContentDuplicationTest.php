<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_cms_content_type_base\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\drupal_cms_content_type_base\Traits\ContentModelTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

#[Group('drupal_cms_content_type_base')]
#[IgnoreDeprecations]
class ContentDuplicationTest extends BrowserTestBase {

  use ContentModelTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block'];

  public function testContentDuplication(): void {
    $this->drupalPlaceBlock('page_title_block');
    $this->drupalPlaceBlock('local_tasks_block');

    // For performance, test all these content types in one test, rather than
    // a data provider or #[TestWith] attributes.
    $content_types = $this->applyAllContentTypeRecipes();

    $account = $this->drupalCreateUser();
    $account->addRole('content_editor')->save();
    $this->drupalLogin($account);

    array_walk($content_types, $this->doTestContentDuplication(...));
  }

  private function doTestContentDuplication(string $content_type): void {
    $original_title = 'Fun Times';
    $interstitial_message = sprintf('You are duplicating "%s"', $original_title);

    $original = $this->drupalCreateNode([
      'type' => $content_type,
      'title' => $original_title,
      'field_description' => $this->getRandomGenerator()->sentences(4),
    ]);
    $this->drupalGet($original->toUrl());
    // Duplicate tje node from its canonical route.
    $page = $this->getSession()->getPage();
    $page->clickLink('Duplicate');
    $assert_session = $this->assertSession();
    $assert_session->statusMessageContains($interstitial_message);
    $assert_session->fieldValueEquals('title[0][value]', $original_title);
    $page->fillField('title[0][value]', "$original_title, cloned by tab");
    $page->pressButton('Save');
    $assert_session->elementTextEquals('css', 'h1', "$original_title, cloned by tab");

    // Duplicate it again from the operation dropdown in the administrative
    // content list.
    $this->drupalGet('/admin/content');
    $assert_session->elementExists('named', ['link', 'Fun Times'])
      // Traverse upwards to the containing table row.
      ->getParent()
      ->getParent()
      ->clickLink('Duplicate');
    $assert_session->statusMessageContains($interstitial_message);
    $assert_session->fieldValueEquals('title[0][value]', $original_title);
    $page->fillField('title[0][value]', "$original_title, cloned by admin operation");
    $page->pressButton('Save');
    // We should be back on the administrative content list, and the duplicated
    // node should exist.
    $assert_session->addressEquals('/admin/content');
    $assert_session->linkExists("$original_title, cloned by admin operation");
  }

}
