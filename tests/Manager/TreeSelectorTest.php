<?php

namespace JDZ\MediaManager\Tests\Manager;

use PHPUnit\Framework\TestCase;

class TreeSelectorTest extends TestCase
{
  use ManagerFixture;

  // -- tree -----------------------------------------------------------------

  public function testTreeIsWrappedInARootNode(): void
  {
    $tree = $this->manager()->getTree('');

    $this->assertSame('', $tree->value);
    $this->assertSame('', $tree->readable);
    $this->assertSame('ROOT', $tree->text);
    $this->assertSame(1, $tree->level);
    $this->assertTrue($tree->active);
    $this->assertSame(['empty', 'pages', 'photos'], \array_column($tree->children, 'text'));
  }

  public function testTreeNodeShape(): void
  {
    $photos = $this->manager()->getTree('')->children[2];

    $this->assertSame('photos', $photos->value);
    $this->assertSame('photos', $photos->readable);
    $this->assertSame('photos', $photos->text);
    $this->assertSame(1, $photos->level);
    $this->assertFalse($photos->active);
    $this->assertFalse($photos->selected);
    $this->assertCount(1, $photos->children);
  }

  public function testNestedNodeValuesUseTheWireSeparator(): void
  {
    $sub = $this->manager()->getTree('')->children[2]->children[0];

    $this->assertSame('photos[-]sub', $sub->value);
    $this->assertSame('photos/sub', $sub->readable);
    $this->assertSame('sub', $sub->text);
    $this->assertSame(2, $sub->level);
  }

  public function testTheActiveFolderIsSelected(): void
  {
    $photos = $this->manager()->getTree('photos')->children[2];

    $this->assertTrue($photos->selected);
    $this->assertTrue($photos->active);
  }

  public function testTheBranchLeadingToTheActiveFolderIsOpen(): void
  {
    $tree = $this->manager()->getTree('photos/sub');
    $photos = $tree->children[2];
    $sub = $photos->children[0];

    // the parent is on the path : open but not selected
    $this->assertTrue($photos->active);
    $this->assertFalse($photos->selected);

    $this->assertTrue($sub->active);
    $this->assertTrue($sub->selected);

    // siblings stay shut
    $this->assertFalse($tree->children[0]->active);
    $this->assertFalse($tree->children[1]->active);
  }

  public function testAnUnknownActiveFolderOpensNothing(): void
  {
    $tree = $this->manager()->getTree('nope/gone');

    foreach ($tree->children as $node) {
      $this->assertFalse($node->active);
      $this->assertFalse($node->selected);
    }
  }

  // -- selector -------------------------------------------------------------

  public function testSelectorGroupsImagesPerFolder(): void
  {
    $list = $this->manager()->getSelectorList('', '../media/');

    $this->assertSame(['', 'pages', 'photos', 'photos/sub'], \array_column($list, 'name'));
    $this->assertSame([1, 2, 2, 3], \array_column($list, 'level'));
  }

  public function testSelectorFileShape(): void
  {
    $list = $this->manager()->getSelectorList('photos', '../media/');
    $file = $list[0]->files[0];

    $this->assertSame('photos/a.jpg', $file->value);
    $this->assertSame('a', $file->text);
    $this->assertTrue($file->valid);
    $this->assertSame('../media/photos/a.jpg', $file->url);
  }

  public function testSelectorKeepsImagesOnly(): void
  {
    $list = $this->manager()->getSelectorList('photos', '../media/');

    // notes.pdf is not offered
    $this->assertSame(['photos/a.jpg', 'photos/b.png'], \array_column($list[0]->files, 'value'));
  }

  public function testSelectorSkipsUnderscoredEntries(): void
  {
    $this->makePng($this->rootPath('_hidden/x.png'), 5, 5);

    $list = $this->manager()->getSelectorList('', '../media/');

    $this->assertNotContains('_hidden', \array_column($list, 'name'));
    $this->assertNotContains('_draft.png', \array_column($list[0]->files, 'text'));
  }

  public function testSelectorSkipsFoldersWithoutImages(): void
  {
    $list = $this->manager()->getSelectorList('', '../media/');

    // 'empty' holds nothing at all
    $this->assertNotContains('empty', \array_column($list, 'name'));
  }

  public function testSelectorResolverDecoratesEachFile(): void
  {
    $seen = [];

    $list = $this->manager()->getSelectorList('photos', '../media/', function (string $relPath) use (&$seen): array {
      $seen[] = $relPath;

      return ['thumb' => 'thumb:' . $relPath, 'orientation' => 'landscape'];
    });

    $this->assertSame(['photos/a.jpg', 'photos/b.png', 'photos/sub/deep.jpg'], $seen);
    $this->assertSame('thumb:photos/a.jpg', $list[0]->files[0]->thumb);
    $this->assertSame('landscape', $list[0]->files[0]->orientation);
  }

  public function testSelectorResolverReturningNullDropsTheFile(): void
  {
    $list = $this->manager()->getSelectorList('photos', '../media/', fn(string $relPath): ?array
      => \str_ends_with($relPath, '.png') ? null : ['thumb' => '', 'orientation' => '']);

    $this->assertSame(['photos/a.jpg'], \array_column($list[0]->files, 'value'));
  }

  public function testSelectorOfAMissingFolderIsEmpty(): void
  {
    $this->assertSame([], $this->manager()->getSelectorList('nope', '../media/'));
    $this->assertSame([], $this->manager()->getSelectorList('../protect', '../media/'));
  }

  // -- redactor trees -------------------------------------------------------

  public function testRedactorImageTree(): void
  {
    $tree = $this->manager()->getRedactorImageTree('../media/');

    $this->assertSame(['logo', 'photo'], \array_column($tree['files'], 'title'));
    $this->assertSame('../media/logo.png', $tree['files'][0]['url']);
    $this->assertSame(['pages', 'photos'], \array_column($tree['folders'], 'label'));
  }

  public function testRedactorImageTreeHonoursIgnoreLists(): void
  {
    $tree = $this->manager()->getRedactorImageTree('../media/', ['pages'], ['logo.png']);

    $this->assertSame(['photo'], \array_column($tree['files'], 'title'));
    $this->assertSame(['photos'], \array_column($tree['folders'], 'label'));
  }

  public function testRedactorDocumentTreeIsFlat(): void
  {
    // one flat list, subfolders first
    $tree = $this->manager()->getRedactorDocumentTree('../media/');

    $this->assertSame(['notes', 'manual'], \array_column($tree, 'title'));
    $this->assertSame('pdf', $tree[0]['icon']);
    $this->assertSame('../media/photos/notes.pdf', $tree[0]['url']);
    $this->assertSame('../media/manual.pdf', $tree[1]['url']);
  }

  public function testRedactorDocumentTreeHonoursIgnoreLists(): void
  {
    $tree = $this->manager()->getRedactorDocumentTree('../media/', ['photos']);

    $this->assertSame(['manual'], \array_column($tree, 'title'));
  }
}
