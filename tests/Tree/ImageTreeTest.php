<?php

namespace JDZ\MediaManager\Tests\Tree;

use JDZ\MediaManager\Tree\ImageTree;
use PHPUnit\Framework\TestCase;

class ImageTreeTest extends TestCase
{
  use TreeFixture;

  public function testRootFilesAreListedWithBaseUrl(): void
  {
    $tree = (new ImageTree())
      ->setBasePath($this->fixturePath())
      ->setBaseUrl('../media/')
      ->getTree();

    $urls = array_column($tree['files'], 'url', 'id');

    $this->assertSame('../media/root.gif', $urls['root.gif']);
    $this->assertArrayNotHasKey('manual.pdf', $urls, 'documents must be filtered out');
    $this->assertArrayNotHasKey('_draft.jpg', $urls, 'underscore files must be skipped');
  }

  public function testFolderRecursionBuildsRelativeUrls(): void
  {
    $tree = (new ImageTree())
      ->setBasePath($this->fixturePath())
      ->setBaseUrl('../media/')
      ->getTree();

    $folders = array_column($tree['folders'], null, 'label');

    $this->assertArrayHasKey('photos', $folders);
    $urls = array_column($folders['photos']['files'], 'url', 'id');
    $this->assertSame('../media/photos/a.jpg', $urls['a.jpg']);
    $this->assertSame('../media/photos/b.png', $urls['b.png']);
    $this->assertArrayNotHasKey('notes.pdf', $urls);
  }

  public function testFoldersWithoutImagesAreDropped(): void
  {
    $tree = (new ImageTree())
      ->setBasePath($this->fixturePath())
      ->setBaseUrl('../media/')
      ->getTree();

    $labels = array_column($tree['folders'], 'label');

    $this->assertNotContains('docs', $labels, 'document-only folder must be dropped');
    $this->assertNotContains('empty', $labels, 'empty folder must be dropped');
    $this->assertNotContains('_private', $labels, 'underscore folder must be skipped');
  }

  public function testIgnoreListsAreHonored(): void
  {
    $tree = (new ImageTree())
      ->setBasePath($this->fixturePath())
      ->setBaseUrl('../media/')
      ->setIgnoreFolders(['photos'])
      ->setIgnoreFiles(['root.gif'])
      ->getTree();

    $this->assertNotContains('photos', array_column($tree['folders'] ?? [], 'label'));
    $this->assertNotContains('root.gif', array_column($tree['files'] ?? [], 'id'));
  }

  public function testFileEntryShape(): void
  {
    $tree = (new ImageTree())
      ->setBasePath($this->fixturePath())
      ->setBaseUrl('../media/')
      ->getTree();

    $files = array_column($tree['files'], null, 'id');

    $this->assertSame(['url', 'id', 'title', 'ext'], array_keys($files['root.gif']));
    $this->assertSame('root', $files['root.gif']['title']);
    $this->assertSame('gif', $files['root.gif']['ext']);
  }
}
