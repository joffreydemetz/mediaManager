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
use JDZ\MediaManager\ValueObject\UploadResult;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function Symfony\Component\String\u;

/**
 * Validates one uploaded file, gives it a library-friendly name, moves it in
 * and downsizes it when it is a needlessly large picture.
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class Uploader
{
  /** How many `-1`, `-2`, .. suffixes to try before giving up on a collision */
  private const MAX_COLLISION_TRIES = 4;

  public function __construct(private readonly MediaConfig $config) {}

  /**
   * @param string $absDir     absolute target directory, already jailed
   * @param string $relFolder  library-relative form of the same directory
   */
  public function upload(UploadedFile $file, string $absDir, string $relFolder, bool $overwrite = false): UploadResult
  {
    $originalName = $file->getClientOriginalName();
    $size = (int)$file->getSize();
    $mime = $file->getClientMimeType();

    if ($this->config->maxWeight() * 1000000 < $size) {
      return UploadResult::fail(
        'MEDIAMANAGER_ERROR_UPLOAD_FILE_TOO_BIG',
        ['%maxFileSize%' => $this->config->maxWeight()],
        $originalName,
      );
    }

    if (false === $this->isAuthorizedMime($mime)) {
      return UploadResult::fail(
        'MEDIAMANAGER_ERROR_UPLOAD_FILE_UNAUTH_EXT',
        ['%authExts%' => \implode(', ', $this->config->authExts())],
        $originalName,
      );
    }

    if (\in_array($mime, $this->config->mimesDocument, true)) {
      if ($this->config->maxWeightDocument * 1000000 < $size) {
        return UploadResult::fail(
          'MEDIAMANAGER_ERROR_UPLOAD_FILE_TOO_BIG',
          ['%maxFileSize%' => $this->config->maxWeightDocument],
          $originalName,
        );
      }
    }

    $fi = new \SplFileInfo($originalName);
    $ext = \strtolower($fi->getExtension());

    if ('jpeg' === $ext) {
      $ext = 'jpg';
    }

    $cleanFilename = $this->cleanFilename($fi->getBasename('.' . $fi->getExtension()));

    if ('' === $cleanFilename) {
      $cleanFilename = 'fichier';
    }

    $dir = \rtrim(Path::canonicalize($absDir), '/') . '/';
    $filename = $cleanFilename . '.' . $ext;

    if (true === @\file_exists($dir . $filename) && false === $overwrite) {
      if (null === ($available = $this->guessAvailableFilename($dir, $cleanFilename, $ext))) {
        return UploadResult::fail(
          'MEDIAMANAGER_ERROR_DESTINATION_FILE_ALREADY_EXISTS',
          [],
          $originalName,
        );
      }

      $cleanFilename = $available;
      $filename = $cleanFilename . '.' . $ext;
    }

    try {
      $file->move($dir, $filename);
    } catch (\Throwable $e) {
      return UploadResult::fail(
        'MEDIAMANAGER_ERROR_OPERATION_FAILED',
        ['%error%' => $e->getMessage()],
        $originalName,
      );
    }

    if (false === @\file_exists($dir . $filename)) {
      return UploadResult::fail(
        'MEDIAMANAGER_ERROR_OPERATION_FAILED',
        ['%error%' => 'Uploaded file was not found where it should have been moved'],
        $originalName,
      );
    }

    $this->downsizeIfNeeded($dir . $filename, $mime, $size);

    return UploadResult::ok($originalName, $cleanFilename, $filename, \trim($relFolder, '/'));
  }

  private function isAuthorizedMime(string $mime): bool
  {
    $validMimes = [];

    foreach ($this->config->authMimes() as $authMime) {
      $validMimes[] = \str_replace('/', '\/', $authMime);
    }

    if ([] === $validMimes) {
      return false;
    }

    return 1 === \preg_match('/^(' . \implode(')|(', $validMimes) . ')$/i', $mime);
  }

  /**
   * @return string|null  the free base name, or null when they are all taken
   */
  private function guessAvailableFilename(string $dir, string $baseFilename, string $ext): ?string
  {
    for ($i = 1; $i < self::MAX_COLLISION_TRIES; $i++) {
      $candidate = $baseFilename . '-' . $i;

      if (false === @\file_exists($dir . $candidate . '.' . $ext)) {
        return $candidate;
      }
    }

    return null;
  }

  /**
   * Library naming scheme : camelCase split, transliterated, lowercased,
   * dash-separated. Kept faithful to the legacy `StringHelper::toFilename()`
   * so freshly uploaded files sit alongside the existing ones.
   */
  private function cleanFilename(string $filename): string
  {
    $words = \preg_split('/(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', $filename);
    $clean = \implode(' ', false === $words ? [$filename] : $words);

    $clean = \str_replace(['-', '’'], [' ', "'"], $clean);
    $clean = u($clean)->ascii()->toString();
    $clean = \preg_replace('/\s+/', ' ', $clean);
    $clean = \trim(\mb_strtolower((string)$clean));
    $clean = \preg_replace('/(\s|[^A-Za-z0-9\-])+/', '-', $clean);
    $clean = \str_replace([' ', '_'], '-', (string)$clean);
    $clean = \preg_replace('#-+#', '-', $clean);

    return \trim((string)$clean, '-');
  }

  /**
   * Shrink oversized pictures in place. Never fatal : a file that made it to
   * disk stays uploaded even if the resize goes wrong.
   */
  private function downsizeIfNeeded(string $imagePath, string $mime, int $originalSize): void
  {
    if (1 !== \preg_match('/^image\/(png|gif|jpeg)$/', $mime)) {
      return;
    }

    $size = @\getimagesize($imagePath);

    if (false === $size) {
      return;
    }

    [$width, $height] = $size;

    if (
      $this->config->maxWeightImage * 1000000 >= $originalSize
      && $width <= $this->config->maxPictureLongSide
      && $height <= $this->config->maxPictureLongSide
    ) {
      return;
    }

    [$newWidth, $newHeight] = $this->scaledImageSizes(
      $width,
      $height,
      $this->config->maxPictureLongSide,
      $this->config->maxPictureLongSide,
    );

    try {
      $imagine = new \Imagine\Gd\Imagine();
      $imagine->setMetadataReader(new \Imagine\Image\Metadata\ExifMetadataReader());

      $img = $imagine->open($imagePath);
      $img->resize(new \Imagine\Image\Box($newWidth, $newHeight));

      (new \Imagine\Filter\Basic\Autorotate())->apply($img);

      $img->save($imagePath, [
        'jpeg_quality' => 100,
        'png_compression_level' => 9,
        'resolution-units' => \Imagine\Image\ImageInterface::RESOLUTION_PIXELSPERINCH,
        'resolution-x' => 96,
        'resolution-y' => 96,
      ]);
    } catch (\Throwable $e) {
      // the file is uploaded ; a failed resize is not worth losing it over
    }
  }

  /**
   * Largest $x/$y box that fits within $cx/$cy, ratio preserved.
   */
  private function scaledImageSizes(int|float $x, int|float $y, int|float $cx, int|float $cy): array
  {
    if ($x < $cx && $y < $cx) {
      return [(int)$x, (int)$y];
    }

    $rx = $x > 0 ? $cx / $x : 0;
    $ry = $y > 0 ? $cy / $y : 0;
    $r = $rx > $ry ? $ry : $rx;

    return [(int)($x * $r), (int)($y * $r)];
  }
}
