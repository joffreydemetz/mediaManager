<?php

namespace JDZ\MediaManager\Tests\Manager;

use PHPUnit\Framework\TestCase;

class FilesystemListingTest extends TestCase
{
  use ManagerFixture;

  public function testRootListing(): void
  {
    $fs = $this->manager()->getFilesystem('');

    $this->assertSame('', $fs['value']);
    $this->assertFalse($fs['previous']);
    $this->assertArrayNotHasKey('name', $fs);

    $this->assertSame(['empty', 'pages', 'photos'], \array_column($fs['folders'], 'name'));
    $this->assertSame(['_draft.png', 'logo.png', 'manual.pdf', 'photo.jpg'], \array_column($fs['files'], 'name'));
  }

  public function testFolderEntryShape(): void
  {
    $fs = $this->manager()->getFilesystem('');
    $photos = $fs['folders'][2];

    $this->assertSame([
      'value' => 'photos',
      'name' => 'photos',
      'previous' => '',
      'type' => 'folder',
      'icon' => 'folder-closed',
    ], $photos);
  }

  public function testSubfolderListingCarriesNameAndPrevious(): void
  {
    $fs = $this->manager()->getFilesystem('photos/sub');

    $this->assertSame('photos/sub', $fs['value']);
    $this->assertSame('sub', $fs['name']);
    $this->assertSame('photos', $fs['previous']);
    $this->assertSame(['deep.jpg'], \array_column($fs['files'], 'name'));
  }

  public function testNestedFolderValuesArePathsNotBasenames(): void
  {
    $fs = $this->manager()->getFilesystem('photos');

    $this->assertSame([
      'value' => 'photos/sub',
      'name' => 'sub',
      'previous' => 'photos',
      'type' => 'folder',
      'icon' => 'folder-closed',
    ], $fs['folders'][0]);
  }

  public function testListingIsShallow(): void
  {
    $fs = $this->manager()->getFilesystem('');

    // 'sub' lives under photos/ and must not surface at the root
    $this->assertNotContains('sub', \array_column($fs['folders'], 'name'));
    $this->assertNotContains('a.jpg', \array_column($fs['files'], 'name'));
  }

  public function testFileEntryShape(): void
  {
    $fs = $this->manager()->getFilesystem('photos');
    $file = $fs['files'][0];

    $this->assertSame('a.jpg', $file['value']);
    $this->assertSame('a.jpg', $file['name']);
    $this->assertSame('jpg', $file['ext']);
    $this->assertSame('', $file['thumb']);
  }

  public function testUnknownFolderFallsBackToTheRoot(): void
  {
    $fs = $this->manager()->getFilesystem('nope/gone');

    $this->assertSame('', $fs['value']);
    $this->assertFalse($fs['previous']);
    $this->assertSame(['empty', 'pages', 'photos'], \array_column($fs['folders'], 'name'));
  }

  public function testEscapingFolderFallsBackToTheRoot(): void
  {
    $fs = $this->manager()->getFilesystem('../protect');

    $this->assertSame('', $fs['value']);
    $this->assertSame(['empty', 'pages', 'photos'], \array_column($fs['folders'], 'name'));
  }

  public function testImagesFilter(): void
  {
    $fs = $this->manager()->getFilesystem('photos', 'images');

    $this->assertSame(['a.jpg', 'b.png'], \array_column($fs['files'], 'name'));
  }

  public function testDocumentsFilter(): void
  {
    $fs = $this->manager()->getFilesystem('photos', 'documents');

    $this->assertSame(['notes.pdf'], \array_column($fs['files'], 'name'));
  }

  public function testNoFilterKeepsEverything(): void
  {
    $fs = $this->manager()->getFilesystem('photos', '');

    $this->assertSame(['a.jpg', 'b.png', 'notes.pdf'], \array_column($fs['files'], 'name'));
  }

  public function testThumbResolverIsCalledForImagesOnlyWithTheRelativePath(): void
  {
    $seen = [];

    $fs = $this->manager()->getFilesystem('photos', '', function (string $relPath, string $fileName) use (&$seen): string {
      $seen[] = [$relPath, $fileName];

      return 'thumb-of-' . $fileName;
    });

    $this->assertSame([['photos/a.jpg', 'a.jpg'], ['photos/b.png', 'b.png']], $seen);
    $this->assertSame(['thumb-of-a.jpg', 'thumb-of-b.png', ''], \array_column($fs['files'], 'thumb'));
  }

  public function testThumbResolverReturningNullYieldsAnEmptyThumb(): void
  {
    $fs = $this->manager()->getFilesystem('photos', 'images', fn(): ?string => null);

    $this->assertSame(['', ''], \array_column($fs['files'], 'thumb'));
  }

  public function testBreadcrumbs(): void
  {
    $this->assertSame(
      [
        ['folder' => '', 'title' => 'Medias'],
        ['folder' => 'photos', 'title' => 'photos'],
        ['folder' => 'photos[-]sub', 'title' => 'sub'],
      ],
      $this->manager()->getBreadcrumbs('photos/sub'),
    );
  }

  public function testRootBreadcrumbsAreJustTheRoot(): void
  {
    $this->assertSame([['folder' => '', 'title' => 'Medias']], $this->manager()->getBreadcrumbs(''));
  }

  public function testFolderSelectListIsFlatAndLevelled(): void
  {
    $list = $this->manager()->getFolderSelectList();

    $this->assertSame([
      ['value' => '', 'name' => 'Racine', 'level' => 0],
      ['value' => 'empty', 'name' => 'empty', 'level' => 1],
      ['value' => 'pages', 'name' => 'pages', 'level' => 1],
      ['value' => 'photos', 'name' => 'photos', 'level' => 1],
      ['value' => 'photos/sub', 'name' => 'sub', 'level' => 2],
    ], $list);
  }
}
