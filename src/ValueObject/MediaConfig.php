<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace JDZ\MediaManager\ValueObject;

use Symfony\Component\Filesystem\Path;

/**
 * Media library configuration.
 *
 * Everything the manager needs to know about the library it drives : where it
 * lives, which folders are off limits, what may be uploaded and how big.
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class MediaConfig
{
  public function __construct(
    public readonly string $rootPath,
    public readonly array $systemFolders = [],
    public readonly array $extsImage = [],
    public readonly array $extsDocument = [],
    public readonly array $mimesImage = [],
    public readonly array $mimesDocument = [],
    public readonly int $maxWeightImage = 2,
    public readonly int $maxWeightDocument = 5,
    public readonly int $maxWeightFiles = 0,
    public readonly int $maxNumFiles = 0,
    public readonly int $maxPictureLongSide = 1800,
    public readonly string $protectPath = '',
    public readonly string $watermarkPath = '',
  ) {}

  /**
   * Build from a configuration bag.
   *
   * Accepts the key names as they appear in a Callisto `components.medias.*`
   * config block, so a consumer can hand its raw config over untouched.
   */
  public static function fromArray(array $data): static
  {
    return new static(
      rootPath: self::cleanPath((string)($data['rootPath'] ?? '')),
      systemFolders: \array_values((array)($data['systemFolders'] ?? [])),
      extsImage: (array)($data['extsImage'] ?? []),
      extsDocument: (array)($data['extsDocument'] ?? []),
      mimesImage: (array)($data['mimesImage'] ?? []),
      mimesDocument: (array)($data['mimesDocument'] ?? []),
      maxWeightImage: (int)($data['maxWeightImage'] ?? 2),
      maxWeightDocument: (int)($data['maxWeightDocument'] ?? 5),
      maxWeightFiles: (int)($data['maxWeightFiles'] ?? 0),
      maxNumFiles: (int)($data['maxNumFiles'] ?? 0),
      maxPictureLongSide: (int)($data['maxPictureLongSide'] ?? 1800),
      protectPath: self::cleanPath((string)($data['protectPath'] ?? '')),
      watermarkPath: self::cleanPath((string)($data['watermarkPath'] ?? '')),
    );
  }

  /**
   * Every authorized mime type, documents first.
   */
  public function authMimes(): array
  {
    return \array_merge($this->mimesDocument, $this->mimesImage);
  }

  /**
   * Every authorized extension, documents first.
   */
  public function authExts(): array
  {
    return \array_merge($this->extsDocument, $this->extsImage);
  }

  /**
   * The effective upload ceiling, in Mo.
   */
  public function maxWeight(): int
  {
    return \max(1, $this->maxWeightDocument, $this->maxWeightImage);
  }

  /**
   * Dropzone `acceptedFiles` list : mimes then dotted extensions.
   */
  public function acceptedFiles(): array
  {
    return self::accepted($this->authMimes(), $this->authExts());
  }

  public function imageAcceptedFiles(): array
  {
    return self::accepted($this->mimesImage, $this->extsImage);
  }

  public function documentAcceptedFiles(): array
  {
    return self::accepted($this->mimesDocument, $this->extsDocument);
  }

  public function isSystemFolder(string $folder): bool
  {
    return \in_array($folder, $this->systemFolders, true);
  }

  public function toArray(): array
  {
    return [
      'rootPath' => $this->rootPath,
      'systemFolders' => $this->systemFolders,
      'extsImage' => $this->extsImage,
      'extsDocument' => $this->extsDocument,
      'mimesImage' => $this->mimesImage,
      'mimesDocument' => $this->mimesDocument,
      'maxWeightImage' => $this->maxWeightImage,
      'maxWeightDocument' => $this->maxWeightDocument,
      'maxWeightFiles' => $this->maxWeightFiles,
      'maxNumFiles' => $this->maxNumFiles,
      'maxPictureLongSide' => $this->maxPictureLongSide,
      'protectPath' => $this->protectPath,
      'watermarkPath' => $this->watermarkPath,
    ];
  }

  private static function accepted(array $mimes, array $exts): array
  {
    $accepted = [];

    foreach ($mimes as $mime) {
      $accepted[] = $mime;
    }

    foreach ($exts as $ext) {
      $accepted[] = '.' . $ext;
    }

    return $accepted;
  }

  private static function cleanPath(string $path): string
  {
    if ('' === $path) {
      return '';
    }

    return \rtrim(Path::canonicalize($path), '/');
  }
}
