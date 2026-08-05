<?php

namespace JDZ\MediaManager\Tests\Manager;

use JDZ\MediaManager\Manager\PathResolver;
use PHPUnit\Framework\TestCase;

class PathResolverTest extends TestCase
{
  use ManagerFixture;

  private function resolver(): PathResolver
  {
    return new PathResolver($this->rootPath());
  }

  public function testDecodeSwapsTheWireSeparator(): void
  {
    $this->assertSame('photos/sub', $this->resolver()->decode('photos[-]sub'));
  }

  public function testDecodeUrldecodesFirst(): void
  {
    $this->assertSame('mes photos/été', $this->resolver()->decode('mes%20photos%5B-%5D%C3%A9t%C3%A9'));
  }

  public function testDecodeTrimsAndNormalisesSeparators(): void
  {
    $this->assertSame('photos/sub', $this->resolver()->decode('/photos\\sub/'));
    $this->assertSame('', $this->resolver()->decode(''));
  }

  public function testEncodeIsTheInverse(): void
  {
    $resolver = $this->resolver();

    $this->assertSame('photos[-]sub', $resolver->encode('photos/sub'));
    $this->assertSame('photos/sub', $resolver->decode($resolver->encode('photos/sub')));
    $this->assertSame('', $resolver->encode(''));
  }

  public function testFolderPathResolvesInsideTheRoot(): void
  {
    $resolver = $this->resolver();

    $this->assertSame($this->rootPath(), $resolver->folderPath(''));
    $this->assertSame($this->rootPath('photos/sub'), $resolver->folderPath('photos/sub'));
  }

  public function testFolderPathRefusesTraversal(): void
  {
    $resolver = $this->resolver();

    $this->assertNull($resolver->folderPath('..'));
    $this->assertNull($resolver->folderPath('../protect'));
    $this->assertNull($resolver->folderPath('photos/../../protect'));
    $this->assertNull($resolver->folderPath('photos/../..'));
  }

  public function testFolderPathCollapsesHarmlessTraversal(): void
  {
    $this->assertSame($this->rootPath('photos'), $this->resolver()->folderPath('photos/sub/..'));
  }

  public function testFilePathRefusesAnythingButABareName(): void
  {
    $resolver = $this->resolver();

    $this->assertNull($resolver->filePath('photos', '../logo.png'));
    $this->assertNull($resolver->filePath('photos', 'sub/deep.jpg'));
    $this->assertNull($resolver->filePath('photos', 'sub\\deep.jpg'));
    $this->assertNull($resolver->filePath('photos', '..'));
    $this->assertNull($resolver->filePath('photos', ''));
  }

  public function testFilePathRefusesWhenTheFolderEscapes(): void
  {
    $this->assertNull($this->resolver()->filePath('../protect', 'anything.png'));
  }

  public function testFilePathResolves(): void
  {
    $this->assertSame($this->rootPath('photos/a.jpg'), $this->resolver()->filePath('photos', 'a.jpg'));
    $this->assertSame($this->rootPath('logo.png'), $this->resolver()->filePath('', 'logo.png'));
  }

  public function testRelativeRoundTrips(): void
  {
    $resolver = $this->resolver();

    $this->assertSame('', $resolver->relative($this->rootPath()));
    $this->assertSame('photos/sub', $resolver->relative($this->rootPath('photos/sub')));
    $this->assertSame('photos/sub', $resolver->relative($this->rootPath() . '\\photos\\sub'));
  }

  public function testRelativeOfAnOutsidePathIsEmpty(): void
  {
    $this->assertSame('', $this->resolver()->relative($this->protectPath('x.png')));
  }

  public function testIsSelfOrDescendant(): void
  {
    $resolver = $this->resolver();

    $this->assertTrue($resolver->isSelfOrDescendant('photos', 'photos'));
    $this->assertTrue($resolver->isSelfOrDescendant('photos', 'photos/sub'));
    $this->assertTrue($resolver->isSelfOrDescendant('photos', 'photos/sub/deeper'));
    $this->assertFalse($resolver->isSelfOrDescendant('photos', 'photographs'));
    $this->assertFalse($resolver->isSelfOrDescendant('photos', 'pages'));
    $this->assertFalse($resolver->isSelfOrDescendant('photos', ''));
  }

  public function testMovingAnythingIntoTheRootIsNotSelfContainment(): void
  {
    // '' as a parent means "the root" — only a root source is caught
    $this->assertTrue($this->resolver()->isSelfOrDescendant('', 'photos'));
  }
}
