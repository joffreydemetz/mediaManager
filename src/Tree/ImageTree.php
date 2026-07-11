<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JDZ\MediaManager\Tree;

use Symfony\Component\Finder\Finder;

/**
 * Image Tree
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
class ImageTree extends Tree
{
  protected string $filter = 'image';

  public function getTree(): array
  {
    $tree = [];

    $this->tree($tree, $this->basePath);

    return $tree;
  }

  protected function tree(array &$tree, string $path)
  {
    $path = $this->normalizePath($path);

    $f = str_replace('\\', '/', $path);
    $f = str_replace($this->basePath, '', $f);
    $f = rtrim($f, '/');

    $_folders = [];
    foreach (Finder::create()->directories()->depth(0)->filter(fn(\SplFileInfo $entry) => $entry->getFilename()[0] !== '_')->in($path) as $entry) {
      $_folders[] = $entry->getFilename();
    }
    if ($_folders) {
      $tree['folders'] = [];

      foreach ($_folders as $_folder) {
        if (in_array($_folder, $this->ignoreFolders)) {
          continue;
        }

        $folder = [
          'label' => $_folder,
          'path' => $path . $_folder . '/',
          'files' => [],
        ];

        if ($this->tree($folder, $path . $_folder . '/')) {
          $tree['folders'][] = $folder;
        }
      }
    }

    $_files = [];
    foreach (Finder::create()->files()->depth(0)->filter(fn(\SplFileInfo $entry) => $entry->getFilename()[0] !== '_')->in($path) as $entry) {
      $_files[] = $entry->getFilename();
    }
    if ($_files) {
      $tree['files'] = [];

      foreach ($_files as $_file) {
        $fi = new \SplFileInfo($_file);
        $ext = $fi->getExtension();

        if (!$this->checkFileType($ext)) {
          continue;
        }

        if (in_array($_file, $this->ignoreFiles)) {
          continue;
        }

        $tree['files'][] = [
          'url' => $this->baseUrl . ($f ? $f . '/' : '') . $_file,
          'id' => $_file,
          'title' => $fi->getBasename('.' . $ext),
          'ext' => $ext,
        ];
      }
    }

    return (!empty($tree['folders']) || !empty($tree['files']));
  }
}
