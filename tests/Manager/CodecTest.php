<?php

namespace JDZ\MediaManager\Tests\Manager;

use JDZ\MediaManager\Manager\MediaManager;
use JDZ\MediaManager\ValueObject\MediaConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The manager's own wire codec and system-folder lookup, as the controllers
 * call them. Nothing here touches the disk.
 */
class CodecTest extends TestCase
{
  private function manager(): MediaManager
  {
    return new MediaManager(MediaConfig::fromArray([
      'rootPath' => '/srv/site/media',
      'systemFolders' => ['pages', 'photos/locked'],
    ]));
  }

  public static function wireProvider(): array
  {
    return [
      'root' => ['', ''],
      'one level' => ['photos', 'photos'],
      'nested' => ['photos[-]sub[-]deep', 'photos/sub/deep'],
      'accents and spaces' => ['mes photos[-]été', 'mes photos/été'],
    ];
  }

  #[DataProvider('wireProvider')]
  public function testDecodePathAndEncodePathAreInverse(string $wire, string $path): void
  {
    $manager = $this->manager();

    $this->assertSame($path, $manager->decodePath($wire));
    $this->assertSame($wire, $manager->encodePath($path));
  }

  public static function looseWireProvider(): array
  {
    return [
      'url-encoded' => ['mes%20photos%5B-%5D%C3%A9t%C3%A9', 'mes photos/été'],
      'backslashes' => ['photos\\sub', 'photos/sub'],
      'outer slashes' => ['/photos[-]sub/', 'photos/sub'],
      'a plus sign is a plus sign' => ['mes+photos[-]2024+2025', 'mes+photos/2024+2025'],
    ];
  }

  #[DataProvider('looseWireProvider')]
  public function testDecodePathNormalisesLooseInput(string $wire, string $path): void
  {
    $this->assertSame($path, $this->manager()->decodePath($wire));
  }

  public function testEncodePathTrimsOuterSlashes(): void
  {
    $this->assertSame('photos[-]sub', $this->manager()->encodePath('/photos/sub/'));
  }

  public static function systemFolderProvider(): array
  {
    return [
      'listed' => ['pages', true],
      'listed nested' => ['photos/locked', true],
      'not listed' => ['photos', false],
      'below a listed one' => ['pages/sub', false],
      'the root' => ['', false],
    ];
  }

  #[DataProvider('systemFolderProvider')]
  public function testIsSystemFolderMatchesTheListExactly(string $folder, bool $expected): void
  {
    $this->assertSame($expected, $this->manager()->isSystemFolder($folder));
  }
}
