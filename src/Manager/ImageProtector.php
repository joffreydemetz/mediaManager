<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace JDZ\MediaManager\Manager;

use JDZ\MediaManager\ValueObject\MediaConfig;
use JDZ\MediaManager\ValueObject\OperationResult;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Stamps a repeating watermark over a picture, keeping the pristine original
 * in a backup folder so the operation can be undone.
 *
 * A backup is filed under the file's library-relative path, folders kept :
 * `photos/2024/a.jpg` backs up to `<protectPath>/photos/2024/a.jpg`. Backups
 * follow their file when it is renamed, moved or deleted.
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class ImageProtector
{
  private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif'];

  public function __construct(private readonly MediaConfig $config) {}

  /**
   * @param string $relPath  library-relative path of the file, its backup slot
   */
  public function protect(string $absMediaFile, string $relPath): OperationResult
  {
    if (false === @\file_exists($absMediaFile)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_SOURCE_FILE_NOT_FOUND');
    }

    if (false === $this->isImage($absMediaFile)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_NOT_AN_IMAGE');
    }

    if ('' === $this->config->watermarkPath || false === @\file_exists($this->config->watermarkPath)) {
      return OperationResult::fail(
        'MEDIAMANAGER_ERROR_OPERATION_FAILED',
        ['%error%' => 'Watermark file not found'],
      );
    }

    if (null === ($backupPath = $this->backupPath($relPath))) {
      return OperationResult::fail(
        'MEDIAMANAGER_ERROR_OPERATION_FAILED',
        ['%error%' => 'Protect folder not configured'],
      );
    }

    $fs = new Filesystem();
    $updated = false;

    try {
      if (true === @\file_exists($backupPath)) {
        // already protected once : re-stamp from the pristine copy, never from
        // the watermarked file, or the marks would pile up
        $updated = true;
      } else {
        $fs->copy($absMediaFile, $backupPath, true);
      }

      $this->stamp($absMediaFile, $backupPath, $this->config->watermarkPath);
    } catch (\Throwable $e) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_OPERATION_FAILED', ['%error%' => $e->getMessage()]);
    }

    return OperationResult::ok('MEDIAMANAGER_SUCCESS_PROTECT', [], ['updated' => $updated]);
  }

  public function unprotect(string $absMediaFile, string $relPath): OperationResult
  {
    if (false === @\file_exists($absMediaFile)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_SOURCE_FILE_NOT_FOUND');
    }

    if (false === $this->isImage($absMediaFile)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_NOT_AN_IMAGE');
    }

    $backupPath = $this->backupPath($relPath);

    if (null === $backupPath || false === @\file_exists($backupPath)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_PROTECT_ORIGINAL_NOT_FOUND');
    }

    try {
      $fs = new Filesystem();
      $fs->copy($backupPath, $absMediaFile, true);
      $fs->remove($backupPath);
      $this->pruneEmptyParents($backupPath);
    } catch (\Throwable $e) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_OPERATION_FAILED', ['%error%' => $e->getMessage()]);
    }

    return OperationResult::ok('MEDIAMANAGER_SUCCESS_UNPROTECT');
  }

  /**
   * Does a pristine copy exist for this file ?
   */
  public function isProtected(string $relPath): bool
  {
    $backupPath = $this->backupPath($relPath);

    return null !== $backupPath && @\is_file($backupPath);
  }

  /**
   * Move the backup of a file or of a whole folder along with it. Nothing to
   * do when there is none.
   *
   * @throws \Throwable  when the backup exists but cannot be moved
   */
  public function follow(string $fromRelPath, string $toRelPath): void
  {
    $from = $this->backupPath($fromRelPath);
    $to = $this->backupPath($toRelPath);

    if (null === $from || null === $to || false === @\file_exists($from)) {
      return;
    }

    // whatever sits at the target slot belongs to a file that no longer exists
    $fs = new Filesystem();
    $fs->mkdir(\dirname($to));
    $fs->rename($from, $to, true);
    $this->pruneEmptyParents($from);
  }

  /**
   * Drop the backup of a file or of a whole folder, when there is one.
   *
   * @throws \Throwable
   */
  public function drop(string $relPath): void
  {
    $path = $this->backupPath($relPath);

    if (null === $path || false === @\file_exists($path)) {
      return;
    }

    (new Filesystem())->remove($path);
    $this->pruneEmptyParents($path);
  }

  /**
   * Where the backup of a library path lives.
   *
   * @return string|null  null without a protect folder, or when the path would land outside it
   */
  private function backupPath(string $relPath): ?string
  {
    if ('' === $this->config->protectPath) {
      return null;
    }

    $relPath = \trim(\str_replace('\\', '/', $relPath), '/');

    if ('' === $relPath) {
      return null;
    }

    $absolute = Path::canonicalize($this->config->protectPath . '/' . $relPath);

    if (false === Path::isBasePath($this->config->protectPath, $absolute) || $absolute === $this->config->protectPath) {
      return null;
    }

    return $absolute;
  }

  /**
   * Backups keep the library's folder layout ; a folder emptied by a move or
   * a drop is not worth keeping.
   */
  private function pruneEmptyParents(string $backupPath): void
  {
    $dir = \dirname($backupPath);

    while ($dir !== $this->config->protectPath && Path::isBasePath($this->config->protectPath, $dir)) {
      if (false === @\is_dir($dir) || [] !== \array_diff((array)@\scandir($dir), ['.', '..'])) {
        return;
      }

      @\rmdir($dir);
      $dir = \dirname($dir);
    }
  }

  private function isImage(string $path): bool
  {
    $ext = \strtolower((new \SplFileInfo($path))->getExtension());

    return \in_array($ext, self::IMAGE_EXTENSIONS, true);
  }

  /**
   * Tile the watermark across the source and write the result over the target.
   */
  private function stamp(string $targetPath, string $sourcePath, string $watermarkPath): void
  {
    $imagine = new \Imagine\Gd\Imagine();

    $watermark = $imagine->open($watermarkPath);
    $image = $imagine->open($sourcePath);
    $image = $image->applyMask($image->mask(), 100);

    $size = $image->getSize();
    $wSize = $watermark->getSize();

    $x = 0;
    while ($x < $size->getWidth()) {
      $y = 0;

      while ($y < $size->getHeight()) {
        $image->paste($watermark, new \Imagine\Image\Point($x, $y));
        $y += $wSize->getHeight() + 10;
      }

      $x += $wSize->getWidth() + 10;
    }

    $image->save($targetPath);
  }
}
