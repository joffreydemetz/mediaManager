<?php

namespace JDZ\MediaManager\Tests\Manager;

use JDZ\MediaManager\Manager\MediaManager;
use JDZ\MediaManager\ValueObject\MediaConfig;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Builds a throwaway media library on disk, with real images so that
 * mime detection and getimagesize() have something to chew on:
 *
 *   logo.png (40x30)  photo.jpg (60x40)  manual.pdf  _draft.png
 *   pages/            home.png                        <- system folder
 *   photos/           a.jpg  b.png  notes.pdf
 *   photos/sub/       deep.jpg
 *   empty/
 *
 * Outside the library : a watermark, a protect/ backup folder and a scratch
 * folder for upload sources.
 */
trait ManagerFixture
{
  private string $baseDir;
  private string $rootDir;
  private string $protectDir;
  private string $watermarkPath;
  private string $scratchDir;

  protected function setUp(): void
  {
    $this->baseDir = \str_replace('\\', '/', \sys_get_temp_dir()) . '/jdz-mm-' . \bin2hex(\random_bytes(4));
    $this->rootDir = $this->baseDir . '/media';
    $this->protectDir = $this->baseDir . '/protect';
    $this->watermarkPath = $this->baseDir . '/watermark.png';
    $this->scratchDir = $this->baseDir . '/scratch';

    $fs = new Filesystem();
    $fs->mkdir([
      $this->rootDir . '/pages',
      $this->rootDir . '/photos/sub',
      $this->rootDir . '/empty',
      $this->protectDir,
      $this->scratchDir,
    ]);

    $this->makePng($this->rootDir . '/logo.png', 40, 30);
    $this->makeJpg($this->rootDir . '/photo.jpg', 60, 40);
    $this->makePdf($this->rootDir . '/manual.pdf');
    $this->makePng($this->rootDir . '/_draft.png', 10, 10);

    $this->makePng($this->rootDir . '/pages/home.png', 20, 20);

    $this->makeJpg($this->rootDir . '/photos/a.jpg', 30, 20);
    $this->makePng($this->rootDir . '/photos/b.png', 20, 30);
    $this->makePdf($this->rootDir . '/photos/notes.pdf');

    $this->makeJpg($this->rootDir . '/photos/sub/deep.jpg', 10, 10);

    $this->makePng($this->watermarkPath, 8, 8);
  }

  protected function tearDown(): void
  {
    (new Filesystem())->remove($this->baseDir);
  }

  protected function manager(array $overrides = []): MediaManager
  {
    return new MediaManager(MediaConfig::fromArray(\array_merge([
      'rootPath' => $this->rootDir,
      'systemFolders' => ['pages'],
      'extsImage' => ['png', 'gif', 'jpg', 'jpeg'],
      'extsDocument' => ['pdf'],
      'mimesImage' => ['image/png', 'image/gif', 'image/jpeg'],
      'mimesDocument' => ['application/pdf'],
      'maxWeightImage' => 2,
      'maxWeightDocument' => 10,
      'maxWeightFiles' => 3000,
      'maxNumFiles' => 6000,
      'maxPictureLongSide' => 1200,
      'protectPath' => $this->protectDir,
      'watermarkPath' => $this->watermarkPath,
    ], $overrides)));
  }

  protected function rootPath(string $relative = ''): string
  {
    return '' === $relative ? $this->rootDir : $this->rootDir . '/' . \ltrim($relative, '/');
  }

  protected function protectPath(string $fileName): string
  {
    return $this->protectDir . '/' . $fileName;
  }

  /**
   * A test-mode UploadedFile over a freshly written scratch file.
   */
  protected function uploadedPng(string $originalName, int $width = 20, int $height = 20): UploadedFile
  {
    $path = $this->scratchFile($originalName);
    $this->makePng($path, $width, $height);

    return new UploadedFile($path, $originalName, 'image/png', null, true);
  }

  protected function uploadedJpg(string $originalName, int $width = 20, int $height = 20): UploadedFile
  {
    $path = $this->scratchFile($originalName);
    $this->makeJpg($path, $width, $height);

    return new UploadedFile($path, $originalName, 'image/jpeg', null, true);
  }

  protected function uploadedRaw(string $originalName, string $mime, int $bytes = 32): UploadedFile
  {
    $path = $this->scratchFile($originalName);
    (new Filesystem())->dumpFile($path, \str_repeat('x', $bytes));

    return new UploadedFile($path, $originalName, $mime, null, true);
  }

  protected function makePng(string $path, int $width, int $height): void
  {
    (new Filesystem())->mkdir(\dirname($path));

    $img = \imagecreatetruecolor($width, $height);
    \imagefill($img, 0, 0, \imagecolorallocate($img, 120, 180, 240));
    \imagepng($img, $path);
  }

  protected function makeJpg(string $path, int $width, int $height): void
  {
    (new Filesystem())->mkdir(\dirname($path));

    $img = \imagecreatetruecolor($width, $height);
    \imagefill($img, 0, 0, \imagecolorallocate($img, 240, 180, 120));
    \imagejpeg($img, $path, 90);
  }

  protected function makePdf(string $path): void
  {
    (new Filesystem())->dumpFile($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n%%EOF\n");
  }

  /**
   * The physical upload source. Deliberately NOT named after the client file :
   * the whole point is to feed names Windows would refuse on disk.
   */
  private function scratchFile(string $originalName): string
  {
    return $this->scratchDir . '/' . \bin2hex(\random_bytes(4)) . '.upload';
  }
}
