<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace JDZ\MediaManager\Manager;

use Symfony\Component\Filesystem\Path;

/**
 * Turns untrusted folder/file input into absolute paths that are provably
 * inside the media root, or into nothing at all.
 *
 * Two jobs :
 *
 * - the `[-]` wire codec, because folder paths travel through the browser with
 *   their slashes swapped out;
 * - containment, because `Path::normalize()` does NOT resolve `..` and every
 *   folder name here originates in a request.
 *
 * Paths are kept forward-slashed throughout, on every platform.
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class PathResolver
{
  private string $rootPath;

  public function __construct(string $rootPath)
  {
    $this->rootPath = \rtrim(Path::canonicalize($rootPath), '/');
  }

  public function getRootPath(): string
  {
    return $this->rootPath;
  }

  /**
   * Wire form to library form : `photos[-]2024` -> `photos/2024`.
   */
  public function decode(string $wire): string
  {
    $path = \urldecode($wire);
    $path = \str_replace('[-]', '/', $path);
    $path = \str_replace('\\', '/', $path);

    return \trim($path, '/');
  }

  /**
   * Library form to wire form : `photos/2024` -> `photos[-]2024`.
   */
  public function encode(string $path): string
  {
    return \str_replace('/', '[-]', \trim($path, '/'));
  }

  /**
   * Absolute path of a folder inside the library.
   *
   * @return string|null  null when the input escapes the root
   */
  public function folderPath(string $folder): ?string
  {
    $folder = \trim(\str_replace('\\', '/', $folder), '/');

    if ('' === $folder) {
      return $this->rootPath;
    }

    $absolute = Path::canonicalize($this->rootPath . '/' . $folder);

    if (false === $this->contains($absolute)) {
      return null;
    }

    return $absolute;
  }

  /**
   * Absolute path of a file inside the library.
   *
   * The file name must be a bare name : no directory part, no traversal.
   *
   * @return string|null  null when either segment escapes the root
   */
  public function filePath(string $folder, string $fileName): ?string
  {
    if ('' === $fileName || false === $this->isBareFilename($fileName)) {
      return null;
    }

    if (null === ($folderPath = $this->folderPath($folder))) {
      return null;
    }

    $absolute = Path::canonicalize($folderPath . '/' . $fileName);

    if (false === $this->contains($absolute)) {
      return null;
    }

    return $absolute;
  }

  /**
   * Library-relative path of an absolute path, forward-slashed.
   */
  public function relative(string $absolutePath): string
  {
    $absolutePath = Path::canonicalize($absolutePath);

    if ($absolutePath === $this->rootPath) {
      return '';
    }

    if (false === $this->contains($absolutePath)) {
      return '';
    }

    return \trim(\substr($absolutePath, \strlen($this->rootPath)), '/');
  }

  /**
   * Is `$path` the root itself, or below it ?
   */
  public function contains(string $absolutePath): bool
  {
    $absolutePath = Path::canonicalize($absolutePath);

    return $absolutePath === $this->rootPath || Path::isBasePath($this->rootPath, $absolutePath);
  }

  /**
   * Is `$child` strictly below `$parent` (or the very same folder) ?
   *
   * Used to refuse moving a folder into its own subtree.
   */
  public function isSelfOrDescendant(string $parent, string $child): bool
  {
    $parent = \trim(\str_replace('\\', '/', $parent), '/');
    $child = \trim(\str_replace('\\', '/', $child), '/');

    if ($parent === $child) {
      return true;
    }

    if ('' === $parent) {
      return true;
    }

    return \str_starts_with($child . '/', $parent . '/');
  }

  private function isBareFilename(string $fileName): bool
  {
    if (\str_contains($fileName, '/') || \str_contains($fileName, '\\')) {
      return false;
    }

    return '.' !== $fileName && '..' !== $fileName;
  }
}
