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
 * Raw facts about one folder, counted recursively.
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class FolderInfo
{
  public function __construct(
    public readonly string $name,
    public readonly string $path,
    public readonly int $nbFolders,
    public readonly int $nbFiles,
    public readonly int $sizeBytes,
  ) {}

  /**
   * Size in Mo, rounded up — 0 stays 0.
   */
  public function sizeMo(): int
  {
    return (int)\ceil($this->sizeBytes / 1000000);
  }

  public function toArray(): array
  {
    return [
      'name' => $this->name,
      'path' => $this->path,
      'nbFolders' => $this->nbFolders,
      'nbFiles' => $this->nbFiles,
      'sizeBytes' => $this->sizeBytes,
    ];
  }
}
