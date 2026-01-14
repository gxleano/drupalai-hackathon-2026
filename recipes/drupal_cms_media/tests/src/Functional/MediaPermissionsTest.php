<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_cms_media\Functional;

use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

#[Group('drupal_cms_media')]
#[IgnoreDeprecations]
final class MediaPermissionsTest extends BrowserTestBase {

  use RecipeTestTrait;
  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['media', 'media_test_source'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  public function testMediaPermissionsAutomaticallyGrantedToContentEditors(): void {
    $content_editor = Role::load('content_editor');
    $this->assertEmpty($content_editor);

    $dir = realpath(__DIR__ . '/../../..');
    $this->applyRecipe($dir);

    $type = $this->createMediaType('test')->id();
    $content_editor = Role::load('content_editor');
    $this->assertInstanceOf(Role::class, $content_editor);
    // Permissions should have been granted for the media type we created after
    // applying the recipe.
    $this->assertTrue($content_editor->hasPermission("create $type media"));
    $this->assertTrue($content_editor->hasPermission("delete any $type media"));
    $this->assertTrue($content_editor->hasPermission("edit any $type media"));
    $this->assertTrue($content_editor->hasPermission("use media $type bulk upload form"));

    // If a permission is revoked, it should not be restored if another media
    // type is created.
    $content_editor->revokePermission("delete any $type media")->save();
    $this->createMediaType('test');
    $this->assertFalse(
      Role::load('content_editor')?->hasPermission("delete any $type media"),
    );
  }

}
