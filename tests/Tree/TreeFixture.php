<?php

namespace JDZ\MediaManager\Tests\Tree;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Builds a throwaway media tree on disk:
 *
 *   root.gif  manual.pdf  _draft.jpg
 *   photos/   a.jpg  b.png  notes.pdf
 *   docs/     report.pdf  data.xlsx  slides.pptx
 *   empty/
 *   _private/ secret.pdf
 */
trait TreeFixture
{
  private string $fixtureDir;

  protected function setUp(): void
  {
    $this->fixtureDir = sys_get_temp_dir() . '/jdz-mm-tree-' . bin2hex(random_bytes(4));

    $files = [
      'root.gif',
      'manual.pdf',
      '_draft.jpg',
      'photos/a.jpg',
      'photos/b.png',
      'photos/notes.pdf',
      'docs/report.pdf',
      'docs/data.xlsx',
      'docs/slides.pptx',
      '_private/secret.pdf',
    ];

    $fs = new Filesystem();
    $fs->mkdir($this->fixtureDir . '/empty');
    foreach ($files as $file) {
      $fs->dumpFile($this->fixtureDir . '/' . $file, $file);
    }
  }

  protected function tearDown(): void
  {
    (new Filesystem())->remove($this->fixtureDir);
  }

  protected function fixturePath(): string
  {
    return $this->fixtureDir . '/';
  }
}
