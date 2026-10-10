<?php

namespace JDZ\MediaManager\Tests\Manager;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

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

  public static function filterProvider(): array
  {
    return [
      'images' => ['images', ['a.jpg', 'b.png']],
      'documents' => ['documents', ['notes.pdf']],
      'videos' => ['videos', ['clip.mp4']],
      'audios' => ['audios', ['sound.wav']],
      'no filter keeps everything' => ['', ['a.jpg', 'b.png', 'clip.mp4', 'notes.pdf', 'sound.wav']],
      'an unknown filter keeps everything' => ['spreadsheets', ['a.jpg', 'b.png', 'clip.mp4', 'notes.pdf', 'sound.wav']],
    ];
  }

  #[DataProvider('filterProvider')]
  public function testFilterKeepsTheMatchingMimes(string $only, array $expected): void
  {
    $fs = new Filesystem();
    $fs->dumpFile($this->rootPath('photos/clip.mp4'), "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . \str_repeat("\x00", 32));
    $fs->dumpFile($this->rootPath('photos/sound.wav'), "RIFF\x24\x00\x00\x00WAVEfmt \x10\x00\x00\x00\x01\x00\x01\x00\x44\xAC\x00\x00\x88\x58\x01\x00\x02\x00\x10\x00data\x00\x00\x00\x00");

    $listing = $this->manager()->getFilesystem('photos', $only);

    $this->assertSame($expected, \array_column($listing['files'], 'name'));
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
