<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace JDZ\MediaManager\ValueObject;

/**
 * Geometry of one image — what a cropper needs before it opens.
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class ImageMetadata
{
  public const LANDSCAPE = 'landscape';
  public const PORTRAIT = 'portrait';
  public const SQUARE = 'square';

  public function __construct(
    public readonly string $path,
    public readonly string $fullPath,
    public readonly string $mime,
    public readonly bool $isImage,
    public readonly ?int $width = null,
    public readonly ?int $height = null,
    public readonly ?float $ratio = null,
    public readonly string $orientation = '',
  ) {}

  public static function forImage(string $path, string $fullPath, string $mime, int $width, int $height): static
  {
    if ($width > $height) {
      $orientation = self::LANDSCAPE;
    } elseif ($width < $height) {
      $orientation = self::PORTRAIT;
    } else {
      $orientation = self::SQUARE;
    }

    return new static(
      $path,
      $fullPath,
      $mime,
      true,
      $width,
      $height,
      $height > 0 ? \round($width / $height, 1) : null,
      $orientation,
    );
  }

  public static function forOther(string $path, string $fullPath, string $mime): static
  {
    return new static($path, $fullPath, $mime, false);
  }

  public function toArray(): array
  {
    $data = [
      'filepath' => $this->path,
      'fullpath' => $this->fullPath,
      'mime' => $this->mime,
    ];

    if (true === $this->isImage) {
      $data['width'] = $this->width;
      $data['height'] = $this->height;
      $data['ratio'] = $this->ratio;
      $data['orientation'] = $this->orientation;
    }

    return $data;
  }
}
