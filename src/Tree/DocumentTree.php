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
 * Document Tree
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
class DocumentTree extends Tree
{
  protected string $filter = 'document';

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
      foreach ($_folders as $_folder) {
        if (in_array($_folder, $this->ignoreFolders)) {
          continue;
        }

        $this->tree($tree, $path . $_folder . '/');
      }
    }

    $_files = [];
    foreach (Finder::create()->files()->depth(0)->filter(fn(\SplFileInfo $entry) => $entry->getFilename()[0] !== '_')->in($path) as $entry) {
      $_files[] = $entry->getFilename();
    }
    if ($_files) {
      foreach ($_files as $_file) {
        $fi = new \SplFileInfo($_file);
        $ext = $fi->getExtension();

        if (!$this->checkFileType($ext)) {
          continue;
        }

        if (in_array($_file, $this->ignoreFiles)) {
          continue;
        }

        switch ($ext) {
          case 'doc':
          case 'docx':
          case 'odt':
            $icon = 'doc';
            break;

          case 'xls':
          case 'xlsx':
          case 'ods':
            $icon = 'xls';
            break;

          case 'ppt':
          case 'pptx':
          case 'odp':
            $icon = 'xls';
            break;

          case 'pdf':
            $icon = 'pdf';
            break;

          default:
            $icon = '';
            break;
        }

        $tree[] = [
          'id'    => $_file,
          'title' => $fi->getBasename('.' . $ext),
          'text' => ($f ? $f . ' / ' : '') . $fi->getBasename('.' . $ext) . ' (' . $ext . ')',
          'icon'  => $icon,
          'url' => $this->baseUrl . ($f ? $f . '/' : '') . $_file,
          'ext'   => $ext,
        ];
      }
    }

    return !empty($tree['folders']);
  }
}
