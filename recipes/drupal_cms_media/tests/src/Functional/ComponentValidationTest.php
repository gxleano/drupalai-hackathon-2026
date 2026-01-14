<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_cms_media\Functional;

use Drupal\breakpoint\BreakpointManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\eca\Entity\Eca;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\Entity\Media;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

#[Group('drupal_cms_media')]
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

    // Ensure all image styles convert to the expected format.
    foreach (ImageStyle::loadMultiple() as $id => $image_style) {
      $expected_extension = match ($id) {
        'large', 'media_library', 'medium', 'thumbnail' => 'avif',
        'linkit_result_thumbnail' => 'png',
        default => 'webp',
      };

      $this->assertSame(
        $expected_extension,
        $image_style->getDerivativeExtension('png'),
        "The '$id' image style does not convert to '$expected_extension'.",
      );
    }

    // Confirm that our configured breakpoints are exposed as plugins.
    // @see \Drupal\drupal_cms_helper\Hook\PluginHooks::breakpointsAlter()
    $manager = $this->container->get(BreakpointManagerInterface::class);
    assert($manager instanceof BreakpointManagerInterface);
    foreach (['sm', 'md', 'lg', 'xl'] as $key) {
      $definition = $manager->createInstance("custom.$key")
        ->getPluginDefinition();
      $this->assertIsString($definition['label']);
      $this->assertIsString($definition['mediaQuery']);
      $this->assertIsInt($definition['weight']);
      $this->assertSame(['1x'], $definition['multipliers']);
      $this->assertSame('custom', $definition['group']);
    }

    // Deleting the ECA model should also delete the responsive image styles,
    // because the config dependency is enforced.
    $query = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage('responsive_image_style')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count();
    $this->assertSame(29, (int) $query->execute());
    Eca::load('define_breakpoints')?->delete();
    $this->assertSame(0, $query->execute());

    // The footer menu should not be visible to anonymous users yet, because
    // the privacy settings have not been enabled.
    $this->drupalPlaceBlock('system_menu_block:footer', ['label' => 'Footer']);
    $this->drupalGet('<front>');
    $footer_menu_selector = 'nav > h2:contains("Footer") + ul';
    $assert_session = $this->assertSession();
    $assert_session->elementNotExists('css', $footer_menu_selector);

    // Create a remote video media entity and make sure that the footer menu
    // will then appear, containing the privacy settings link but not the
    // privacy policy.
    Media::create([
      'bundle' => 'remote_video',
      'status' => 1,
      'name' => 'Driesnote',
      'field_media_oembed_video' => 'https://www.youtube.com/watch?v=U6o8ou71oyE',
    ])->save();
    $this->getSession()->reload();
    $footer_menu = $assert_session->elementExists('css', $footer_menu_selector);
    $this->assertTrue($footer_menu->hasLink('My privacy settings'));
    $this->assertFalse($footer_menu->hasLink('Privacy policy'));
  }

}
