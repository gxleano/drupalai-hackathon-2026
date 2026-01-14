<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_cms_installer\Unit;

use Composer\InstalledVersions;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('drupal_cms_installer')]
final class ScaffoldTest extends UnitTestCase {

  /**
   * Tests the project template's scaffold configuration.
   */
  public function testScaffoldFiles(): void {
    ['install_path' => $project_root] = InstalledVersions::getRootPackage();

    // Composer's binary should be preserved (see the vendor hardening
    // configuration and `post-update-cmd script` in `composer.json`).
    $this->assertFileDoesNotExist($project_root . '/vendor/bin/composer');
    $this->assertFileExists($project_root . '/vendor/composer/composer/bin/composer');

    // Our scaffold configuration disables core's example.gitignore.
    $this->assertFileDoesNotExist($project_root . '/example.gitignore');

    // Ensure that robots.txt was amended by drupal_cms_seo_tools.
    $robots_txt = array_map('file_get_contents', [
      InstalledVersions::getInstallPath('drupal/core') . '/assets/scaffold/files/robots.txt',
      InstalledVersions::getInstallPath('drupal/drupal_cms_seo_tools') . '/robots.append.txt',
    ]);
    $this->assertStringContainsString(
      file_get_contents($project_root . '/web/robots.txt'),
      implode("\n", $robots_txt),
    );
  }

}
