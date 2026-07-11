<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JDZ\MediaManager\Tree;

use Symfony\Component\Filesystem\Path;

/**
 * Tree
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
abstract class Tree
{
  protected string $basePath;
  protected string $baseUrl;
  protected string $filter;
  protected array $tree;
  protected array $ignoreFolders = [];
  protected array $ignoreFiles = [];

  public function setBasePath(string $basePath)
  {
    $this->basePath = $this->normalizePath($basePath);
    return $this;
  }

  public function setBaseUrl(string $baseUrl)
  {
    $this->baseUrl = $baseUrl;
    return $this;
  }

  public function setFilter(string $filter)
  {
    $this->filter = $filter;
    return $this;
  }

  public function setIgnoreFolders(array $ignoreFolders)
  {
    $this->ignoreFolders = $ignoreFolders;
    return $this;
  }

  public function setIgnoreFiles(array $ignoreFiles)
  {
    $this->ignoreFiles = $ignoreFiles;
    return $this;
  }

  protected function normalizePath(string $path): string
  {
    return Path::normalize($path);
  }

  protected function checkFileType($ext): bool
  {
    switch ($ext) {
      case 'png':
      case 'jpg':
      case 'jpeg':
      case 'gif':
      case 'bmp':
        $type = 'image';
        break;

      default:
        $type = 'document';
        break;
    }

    return $this->filter === $type;
  }

  abstract public function getTree(): array;

  abstract protected function tree(array &$tree, string $path);
}
