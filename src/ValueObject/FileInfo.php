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
 * Raw facts about one file.
 *
 * Deliberately unformatted : date display, size rounding and download URLs
 * belong to the consumer.
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class FileInfo
{
  public function __construct(
    public readonly string $name,
    public readonly string $namenoext,
    public readonly string $ext,
    public readonly string $folder,
    public readonly string $mime,
    public readonly int $sizeBytes,
    public readonly ?int $createdAt = null,
    public readonly ?int $modifiedAt = null,
    public readonly bool $isImage = false,
    public readonly ?int $width = null,
    public readonly ?int $height = null,
  ) {}

  /**
   * Library-relative path of the file.
   */
  public function value(): string
  {
    return ('' === $this->folder ? '' : $this->folder . '/') . $this->name;
  }

  public function toArray(): array
  {
    return [
      'name' => $this->name,
      'namenoext' => $this->namenoext,
      'ext' => $this->ext,
      'path' => $this->folder,
      'mime' => $this->mime,
      'sizeBytes' => $this->sizeBytes,
      'createdAt' => $this->createdAt,
      'modifiedAt' => $this->modifiedAt,
      'isImage' => $this->isImage,
      'width' => $this->width,
      'height' => $this->height,
    ];
  }
}
