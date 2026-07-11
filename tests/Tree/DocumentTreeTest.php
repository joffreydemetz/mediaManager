<?php

namespace JDZ\MediaManager\Tests\Tree;

use JDZ\MediaManager\Tree\DocumentTree;
use PHPUnit\Framework\TestCase;

class DocumentTreeTest extends TestCase
{
  use TreeFixture;

  public function testFlatListCoversAllFoldersAndRoot(): void
  {
    $tree = (new DocumentTree())
      ->setBasePath($this->fixturePath())
      ->setBaseUrl('../media/')
      ->getTree();

    $byId = array_column($tree, null, 'id');

    $this->assertArrayHasKey('manual.pdf', $byId);
    $this->assertArrayHasKey('report.pdf', $byId);
    $this->assertArrayHasKey('data.xlsx', $byId);
    $this->assertArrayHasKey('notes.pdf', $byId);
    $this->assertArrayNotHasKey('root.gif', $byId, 'images must be filtered out');
    $this->assertArrayNotHasKey('_draft.jpg', $byId);
    $this->assertArrayNotHasKey('secret.pdf', $byId, 'underscore folder must be skipped');
  }

  public function testTextIncludesFolderPrefix(): void
  {
    $tree = (new DocumentTree())
      ->setBasePath($this->fixturePath())
      ->setBaseUrl('../media/')
      ->getTree();

    $byId = array_column($tree, null, 'id');

    $this->assertSame('docs / report (pdf)', $byId['report.pdf']['text']);
    $this->assertSame('manual (pdf)', $byId['manual.pdf']['text'], 'root files have no folder prefix');
    $this->assertSame('../media/docs/report.pdf', $byId['report.pdf']['url']);
    $this->assertSame('../media/manual.pdf', $byId['manual.pdf']['url']);
  }

  public function testIconMapping(): void
  {
    $tree = (new DocumentTree())
      ->setBasePath($this->fixturePath())
      ->setBaseUrl('../media/')
      ->getTree();

    $byId = array_column($tree, null, 'id');

    $this->assertSame('pdf', $byId['report.pdf']['icon']);
    $this->assertSame('xls', $byId['data.xlsx']['icon']);
    $this->assertSame('xls', $byId['slides.pptx']['icon']);
  }

  public function testIgnoreListsAreHonored(): void
  {
    $tree = (new DocumentTree())
      ->setBasePath($this->fixturePath())
      ->setBaseUrl('../media/')
      ->setIgnoreFolders(['docs'])
      ->setIgnoreFiles(['manual.pdf'])
      ->getTree();

    $byId = array_column($tree, null, 'id');

    $this->assertArrayNotHasKey('report.pdf', $byId);
    $this->assertArrayNotHasKey('manual.pdf', $byId);
    $this->assertArrayHasKey('notes.pdf', $byId);
  }
}
