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
 * Backups are stored flat, under the file's own name : two files sharing a
 * basename in different folders share one backup slot.
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class ImageProtector
{
  private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif'];

  public function __construct(private readonly MediaConfig $config) {}

  /**
   * @param string $backupName  file name the pristine copy is filed under
   */
  public function protect(string $absMediaFile, string $backupName): OperationResult
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

    $backupPath = $this->backupPath($backupName);
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

  public function unprotect(string $absMediaFile, string $backupName): OperationResult
  {
    if (false === @\file_exists($absMediaFile)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_SOURCE_FILE_NOT_FOUND');
    }

    if (false === $this->isImage($absMediaFile)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_NOT_AN_IMAGE');
    }

    $backupPath = $this->backupPath($backupName);

    if (false === @\file_exists($backupPath)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_PROTECT_ORIGINAL_NOT_FOUND');
    }

    try {
      $fs = new Filesystem();
      $fs->copy($backupPath, $absMediaFile, true);
      $fs->remove($backupPath);
    } catch (\Throwable $e) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_OPERATION_FAILED', ['%error%' => $e->getMessage()]);
    }

    return OperationResult::ok('MEDIAMANAGER_SUCCESS_UNPROTECT');
  }

  /**
   * Does a pristine copy exist for this file ?
   */
  public function isProtected(string $backupName): bool
  {
    return '' !== $this->config->protectPath && @\file_exists($this->backupPath($backupName));
  }

  private function backupPath(string $backupName): string
  {
    return Path::canonicalize($this->config->protectPath . '/' . \basename($backupName));
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
