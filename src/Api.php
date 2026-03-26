<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JDZ\MediaManager;

use Callisto\Utils\Media\DocumentTree;
use Callisto\Utils\Media\ImageTree;
use Callisto\Utils\Media\AdminUpload;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use JDZ\Image\Copyright;
use JDZ\Image\Thumb;
use JDZ\Image\Img;

/**
 * Admin media model
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 * @todo        set file type for js displaying (image,video,audio,document)
 *              when image : thumbnail
 *              when video or audio ! player
 *              show filetype icon.
 *              Check if moved or renamed files or folders are in use.
 *              Check upload size & type according to criterias.
 */
class Api
{
  public string $basePath;
  public array $systemFolders = [];

  public function __construct(string $basePath)
  {
    $this->basePath = $this->basePath;
  }

  public function upload()
  {
    $data = new \stdClass;
    $data->force = false;

    $this->doUpload($data);
  }

  public function mediaSelector(
    string $folder,
    bool $jail,
    bool $multiple
  ) {
    $path = $this->basePath . $folder . '/';

    $list = [];
    $this->mediaSelectorFolder($list, $folder);

    $cfg = $this->getFilesystemConfig();
    $stats = $this->getFilesystemStats();
  }

  public function documentTree(): array
  {
    $tree = (new DocumentTree())
      ->setBasePath(normalizePath($this->cfg->get('frmk.publicPath') . 'media/'))
      ->setBaseUrl('../media/')
      ->getTree();

    $items = [];

    foreach ($tree as $file) {
      $file = (object)$file;

      $items[$file->url] = [
        'url' => $file->url,
        'text' => $file->text,
        'title' => $file->title,
        'icon' => $file->icon,
        'target' => 'blank',
      ];
    }

    return $items;
  }

  public function dirCreate(
    string $folder,
    string $parentFolder = ''
  ) {
    if ('' === $folder) {
      throw new \Exception('New folder name cannot be empty');
    }

    $newFolderFullPath = $this->basePath . '/';
    if ('' !== $parentFolder) {
      $newFolderFullPath .= $parentFolder . '/';
    }
    $newFolderFullPath .= $folder . '/';

    if (\file_exists($newFolderFullPath)) {
      throw new \Exception('Folder ' . $folder . ' already exists in ' . $parentFolder . '/');
    }

    try {
      $fs = new Filesystem();
      $fs->mkdir($newFolderFullPath);
    } catch (\Throwable $e) {
      throw new \Exception('Error creating folder ' . $e->getMessage());
    }
  }

  protected function checkSystemFolder(string $folder)
  {
    if (\in_array($folder, $this->systemFolders)) {
      throw new \Exception('Cannot modify a system folder');
      return;
    }
  }
  public function dirMove(
    string $folder,
    array $data
  ) {
    if ('' === $folder) {
      throw new \Exception('New folder name cannot be empty');
    }

    $this->checkSystemFolder($folder);

    $parts = explode('/', $folder);
    $oldFolderName = array_pop($parts);

    $oldFolderPath = $folder;

    $newFolderPath = $this->request->query->get('foldername', '');
    $newFolderPath = $this->cleanJsFolderPath($newFolderPath);
    $parts = explode('/', $newFolderPath);
    $parts[] = $oldFolderName;
    $newFolderPath = implode('/', $parts);

    $oldFolderFullpath = normalizePath($this->basePath . $oldFolderPath);
    $newFolderFullpath = normalizePath($this->basePath . $newFolderPath);

    try {
      $folderExists = @file_exists($oldFolderFullpath);
    } catch (\Exception $e) {
      $folderExists = false;
    }

    if (false === $folderExists) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_SOURCE_FOLDER_NOT_FOUND'));
      return;
    }

    try {
      $folderExists = @file_exists($newFolderFullpath);
    } catch (\Exception $e) {
      $folderExists = false;
    }

    if ($folderExists === true) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_DESTINATION_FOLDER_ALREADY_EXISTS'));
      return;
    }

    try {
      $fs = new \Symfony\Component\Filesystem\Filesystem();
      $fs->rename($oldFolderFullpath, $newFolderFullpath);
    } catch (\Exception $e) {
      $this->response->data->set('error', $e->getMessage());
      return;
    }

    $this->state->set('mediamanager.folder', $newFolderPath);

    $this->response->data->set('message', $this->language->_('MEDIAMANAGER_SUCCESS_DIRMOVE'));

    $this->mediaResponse();
  }

  public function dirRenameForm()
  {
    $folder = $this->state->get('mediamanager.folder');

    if ('' === $folder) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_ROOT_CANNOT_BE_CHANGED'));
      return;
    }

    if (in_array($folder, $this->fetchSystemFolders())) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_ROOT_FOLDERS_CANNOT_BE_CHANGED'));
      return;
    }

    $parts = explode('/', $folder);
    $oldName = array_pop($parts);

    $this->view->viewData->set('title', $this->language->_('MEDIAMANAGER_DIALOG_RENAME_FOLDER'));
    $this->view->viewData->set('oldPath', $folder);
    $this->view->viewData->set('oldName', $oldName);

    $this->addViewLayout('medias.renamedir');

    $this->response->data->set('title', $this->language->_('MEDIAMANAGER_DIALOG_RENAME_FOLDER'));
    $this->response->data->set('noheader', false);
    $this->response->data->set('closeIcon', true);
    $this->response->data->set('size', 'sm');
  }

  public function dirRename(array $data)
  {
    $folder = $this->state->get('mediamanager.folder');

    if ($folder === '') {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_ROOT_CANNOT_BE_CHANGED'));
      return;
    }

    if (in_array($folder, ['pages', 'download', 'system'])) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_ROOT_FOLDERS_CANNOT_BE_CHANGED'));
      return;
    }

    $newFolderName = $this->request->query->get('foldername', '');

    if ($newFolderName === '') {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_ENTER_FOLDER_NEW_NAME'));
      return;
    }

    $newFolderName = $this->cleanJsFolderPath($newFolderName);

    $parts = explode('/', $folder);
    $oldFolderName = array_pop($parts);

    $oldFolderPath = $parts;
    $newFolderPath = $parts;

    $oldFolderPath[] = $oldFolderName;
    $newFolderPath[] = $newFolderName;

    $oldFolderPath = implode('/', $oldFolderPath);
    $newFolderPath = implode('/', $newFolderPath);

    $oldFolderFullpath = normalizePath($this->basePath . $oldFolderPath);
    $newFolderFullpath = normalizePath($this->basePath . $newFolderPath);

    try {
      $folderExists = @file_exists($oldFolderFullpath);
    } catch (\Exception $e) {
      $folderExists = false;
    }

    if ($folderExists === false) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_SOURCE_FOLDER_INVALID'));
      return;
    }

    try {
      $folderExists = @file_exists($newFolderFullpath);
    } catch (\Exception $e) {
      $folderExists = false;
    }

    if ($folderExists === true) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_DESTINATION_FOLDER_ALREADY_EXISTS'));
      return;
    }

    try {
      $fs = new \Symfony\Component\Filesystem\Filesystem();
      $fs->rename($oldFolderFullpath, $newFolderFullpath);
    } catch (\Exception $e) {
      $this->response->data->set('error', $e->getMessage());
      return;
    }

    $this->state->set('mediamanager.folder', $newFolderPath);
  }

  public function dirDelete()
  {
    $folder = $this->state->get('mediamanager.folder');

    if ('' === $folder) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_ROOT_CANNOT_BE_CHANGED'));
      return;
    }

    if (in_array($folder, $this->fetchSystemFolders())) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_ROOT_FOLDERS_CANNOT_BE_CHANGED'));
      return;
    }

    $path = normalizePath($this->basePath . $folder . '/');

    if ($this->isPathEmpty($path) === false) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_FOLDER_NOT_EMPTY'));
      return;
    }

    try {
      $fs = new \Symfony\Component\Filesystem\Filesystem();
      $fs->remove($path);
    } catch (\Exception $e) {
      $this->response->data->set('error', $e->getMessage());
      return;
    }

    $parts   = explode('/', $folder);
    $deleted = array_pop($parts);
    $parent  = implode('/', $parts);

    $this->state->set('mediamanager.folder', $parent);

    $this->response->data->set('parent', $parent);
    $this->response->data->set('message', $this->language->_('MEDIAMANAGER_SUCCESS_DIRDELETE'));

    $this->mediaResponse();
  }

  public function dirInfos()
  {
    $folder = $this->state->get('mediamanager.folder');

    $folder = trim($folder, '/');
    $parts = '' !== $folder ? explode('/', $folder) : [];
    $folderName = $parts ? $parts[count($parts) - 1] : '';
    $path = $parts ? implode('/', $parts) . '/' : '';

    $fullpath = normalizePath($this->basePath . $path);

    if (!is_dir($fullpath)) {
      throw new \Exception('Folder ' . $fullpath . ' not found');
    }

    $folder = [];
    $folder['name'] = $folderName;
    // $folder['download'] = $this->router->url('mediasFolderDownload', [ 'folderName' => $folderName ]);
    $folder['path'] = trim($path, '/');
    $folder['nbFolders'] = 0;
    $folder['nbFiles'] = 0;
    $folder['size'] = 0;

    $folder['infos'] = [];
    $folder['infos'][] = '<strong>' . $folderName . '</strong>';
    $folder['infos'][] = $path;
    $folder['infos'][] = $this->language->_('FOLDERS') . ' : ' . $folder['nbFolders'];
    $folder['infos'][] = $this->language->_('FILES') . ' : ' . $folder['nbFiles'];
    $folder['infos'][] = $this->language->_('SIZE') . ' : ' . $folder['size'] . 'Mo';

    $this->view->viewData->set('folderInfos', $folder['infos']);
    $this->view->viewData->set('folder', (object)$folder);
    $this->addViewLayout('medias.dinfos');

    $this->response->data->set('dinfos', $folder);
    $this->response->data->set('noheader', true);
    $this->response->data->set('closeIcon', true);
    $this->response->data->set('size', 'sm');
  }

  public function fileRenameForm()
  {
    $folder = $this->state->get('mediamanager.folder');
    $file = $this->state->get('mediamanager.file');
    $fullpath = normalizePath($this->basePath . $folder . '/' . $file);

    $fi = new \SplFileInfo($fullpath);
    $ext = $fi->getExtension();

    $this->view->viewData->set('title', $this->language->_('MEDIAMANAGER_DIALOG_RENAME_FILE'));
    $this->view->viewData->set('fileName', $fi->getBasename('.' . $ext));
    $this->view->viewData->set('fileExt', '.' . $ext);

    $this->addViewLayout('medias.rename');

    $this->response->data->set('title', $this->language->_('MEDIAMANAGER_DIALOG_RENAME_FILE'));
    $this->response->data->set('noheader', false);
    $this->response->data->set('closeIcon', true);
    $this->response->data->set('size', 'sm');
  }

  public function fileRename(array $formData)
  {
    $folder = $this->state->get('mediamanager.folder');
    $file = $this->state->get('mediamanager.file');
    $newName = $formData['newName'];

    if ('' === $folder) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_NO_FILE_SPECIFIED'));
      return;
    }

    if ('' === $file) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_NO_FILE_SPECIFIED'));
      return;
    }

    if ('' === $newName) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_ENTER_FILE_NEW_NAME'));
      return;
    }

    if ($file === $newName) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_FILE_NAME_UNCHANGED'));
      return;
    }

    $srcPath = normalizePath($this->basePath . $folder . '/' . $file);

    if (!@file_exists($srcPath)) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_SOURCE_FILE_NOT_FOUND'));
      return;
    }

    $fi = new \SplFileInfo($srcPath);
    $ext = $fi->getExtension();

    $destPath = normalizePath($this->basePath . $folder . '/' . $newName . '.' . $ext);

    if ($srcPath === $destPath) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_FILE_NAME_UNCHANGED'));
      return;
    }

    if (@file_exists($destPath)) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_DESTINATION_FILE_ALREADY_EXISTS'));
      return;
    }

    try {
      $fs = new \Symfony\Component\Filesystem\Filesystem();
      $fs->rename($srcPath, $destPath);
    } catch (\Exception $e) {
      $this->response->data->set('error', $e->getMessage());
      return;
    }

    $this->state->set('mediamanager.file', $newName . '.' . $ext);

    $this->response->data->set('message', $this->language->_('MEDIAMANAGER_SUCCESS_FILRENAME'));

    $this->mediaResponse();
  }

  public function fileMoveForm()
  {
    $folder = $this->state->get('mediamanager.folder');
    $file = $this->state->get('mediamanager.file');

    $this->view->viewData->set('fileName', $file);
    $this->view->viewData->set('oldFolder', $folder);
    $this->view->viewData->set('folderTree', $this->getFolders());

    $this->addViewLayout('medias.move');

    $this->view->viewData->set('title', $this->language->_('MEDIAMANAGER_DIALOG_MOVE_FILE'));

    $this->response->data->set('title', $this->language->_('MEDIAMANAGER_DIALOG_MOVE_FILE'));
    $this->response->data->set('noheader', false);
    $this->response->data->set('closeIcon', true);
    $this->response->data->set('size', 'sm');
  }

  public function fileMove(array $formData)
  {
    $oldFolder = $this->state->get('mediamanager.folder');
    $fileName = $formData['fileName'];
    // $oldFolder = $formData['oldFolder'];
    $newFolder = $formData['newFolder'];

    if ('' === $fileName) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_NO_FILE_SPECIFIED'));
      return;
    }

    /* if ( '' === $newFolder ){
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_SELECT_DESTINATION_FOLDER')); 
      return;
    } */

    if ($oldFolder === $newFolder) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_SAME_FILEPATH'));
      return;
    }

    $srcPath = normalizePath($this->basePath . $oldFolder . '/' . $fileName);
    $destPath = normalizePath($this->basePath . $newFolder . '/' . $fileName);

    if (!@file_exists($srcPath)) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_SOURCE_FILE_NOT_FOUND'));
      return;
    }

    if ($srcPath === $destPath) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_SAME_FILEPATH'));
      return;
    }

    if (@file_exists($destPath)) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_DESTINATION_FILE_ALREADY_EXISTS'));
      return;
    }

    try {
      $fs = new \Symfony\Component\Filesystem\Filesystem();
      $fs->rename($srcPath, $destPath);
    } catch (\Exception $e) {
      $this->response->data->set('error', $e->getMessage());
      return;
    }

    $this->state->set('mediamanager.folder', $newFolder);

    $this->response->data->set('message', $this->language->_('MEDIAMANAGER_SUCCESS_FILMOVE'));

    $this->mediaResponse();
  }

  public function fileDelete(string $fileName)
  {
    $folder = $this->state->get('mediamanager.folder');

    /* if ( '' === $folder ){
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_NO_FILE_SPECIFIED')); 
      return;
    } */

    if ('' === $fileName) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_NO_FILE_SPECIFIED'));
      return;
    }

    $filePath = normalizePath($this->basePath . $folder . '/' . $fileName);

    if (!@file_exists($filePath)) {
      $this->response->data->set('error', $this->language->_('MEDIAMANAGER_ERROR_SOURCE_FILE_NOT_FOUND'));
      return;
    }

    try {
      $fs = new \Symfony\Component\Filesystem\Filesystem();
      $fs->remove($filePath);
    } catch (\Exception $e) {
      $data['error'] = $e->getMessage();
      return false;
    }

    $this->state->get('mediamanager.file', '');

    $this->response->data->set('message', $this->language->_('MEDIAMANAGER_SUCCESS_FILDELETE', ['%filename%' => $fileName]));

    $this->mediaResponse();
  }

  public function fileDownload(string $fileName)
  {
    $folder = $this->state->get('mediamanager.folder');
    $folder = trim($folder, '/');
    $parts  = '' !== $folder ? explode('/', $folder) : [];
    $path   = $parts ? implode('/', $parts) : '';

    $fullpath = normalizePath($this->basePath . $path . '/' . $fileName, true);

    $this->response->file($fullpath, $fileName, true);
  }

  public function fileInfos(string $fileName)
  {
    $folder = $this->state->get('mediamanager.folder');

    $folder = trim($folder, '/');
    $parts = '' !== $folder ? explode('/', $folder) : [];
    $path = $parts ? implode('/', $parts) . '/' : '';

    $fullpath = normalizePath($this->basePath . $path . $fileName);

    $this->state->set('mediamanager.file', '');

    if (!@file_exists($fullpath)) {
      throw new \Exception('File ' . $fullpath . ' not found');
    }

    $fi = new \SplFileInfo($fullpath);
    $ext = $fi->getExtension();

    $file = [];
    $file['name']       = $fileName;
    $file['download']   = $this->router->url('mediasFileDownload', ['fileName' => $fileName]);
    $file['namenoext']  = $fi->getBasename('.' . $ext);
    $file['ext']        = $ext;
    $file['path']       = trim($path, '/');
    $file['mime']       = \mime_content_type($fullpath);
    $file['size']       = round($fi->getSize() / 1000, 2);
    $file['created']    = false !== ($created = $fi->getCTime()) ? $this->date->display($created) : '';
    $file['modified']   = false !== ($modified = $fi->getMTime()) ? $this->date->display($modified) : '';

    $file['infos'] = [];
    $file['infos'][] = '<strong>' . $fileName . '</strong>';
    $file['infos'][] = $this->language->_('TYPE') . ' : ' . $file['mime'];
    $file['infos'][] = $this->language->_('SIZE') . ' : ' . $file['size'] . 'Ko';
    $file['infos'][] = $this->language->_('CREATED') . ' : ' . $file['created'];
    $file['infos'][] = $this->language->_('MODIFIED') . ' : ' . $file['modified'];

    if (preg_match("/^image\/.+$/", $file['mime'])) {
      $file['thumb'] = $file['download'];

      list($width, $height) = \getimagesize($fullpath);

      $file['infos'][] = 'W: ' . $width . 'px / H: ' . $height . 'px';
      // $file['infos'][] = 'Res: '.$imageInfos->resX.' x '.$imageInfos->resY;
    } else {
      $file['thumb'] = '';
    }

    $this->state->set('mediamanager.file', $fileName);

    $this->view->viewData->set('file', $file);

    $this->addViewLayout('medias.finfos');

    $this->response->data->set('finfos', $file);
    $this->response->data->set('noheader', true);
    $this->response->data->set('closeIcon', true);
    $this->response->data->set('size', 'sm');
  }

  public function dropzoneUpload(bool $force, string $folder)
  {
    $this->response->data->set('force', $force);
    $this->response->data->set('folder', $folder);

    $data = new \stdClass;
    $data->force = $force;
    $data->folder = $folder;

    try {
      $this->doUpload($data);
      $this->response->data->set('status', 200);
    } catch (\Exception $e) {
      $this->response->data->set('error', $e->getMessage());
      $this->response->data->set('status', 403);
    }
  }

  public function redactorTree(string $filter)
  {
    $this->response->data->set('type', $filter);

    if ('document' === $filter) {
      $tree = (new DocumentTree())
        ->setBasePath(normalizePath($this->cfg->get('frmk.publicPath') . 'media/'))
        ->setBaseUrl('../media/')
        // ->setBaseUrl('https://'.$this->cfg->get('app.url').'/media/')
        ->setIgnoreFolders([
          'template',
        ])
        // ->setIgnoreFiles([
        // 'share-200-150.jpg',
        // 'share-400-300.jpg',
        // 'share-800-600.jpg',
        // 'share.jpg',
        // 'share.png',
        // ])
        ->getTree();
    } else {
      $tree = (new ImageTree())
        ->setBasePath(normalizePath($this->cfg->get('frmk.publicPath') . 'media/'))
        ->setBaseUrl('../media/')
        // ->setBaseUrl('https://'.$this->cfg->get('app.url').'/media/')
        ->setIgnoreFolders([
          'template',
        ])
        ->setIgnoreFiles([
          'share-200-150.jpg',
          'share-400-300.jpg',
          'share-800-600.jpg',
          'share.jpg',
          'share.png',
        ])
        ->getTree();
    }

    $this->response->data->set('tree', $tree);
  }

  public function dirTree(array &$data)
  {
    $data['tree'] = (object) [
      'value'    => '',
      'readable' => '',
      'text'     => 'ROOT',
      'level'    => 1,
      'active'   => true,
      'children' => $this->tree(),
    ];
  }

  public function removeThumbs(string $srcFile)
  {
    try {
      $thumb = new Thumb($this->cfg->get('frmk.publicPath'), 0, 'thumbs', 6000);
      if (true === $thumb->unthumbImage('media/' . $this->state->get('mediamanager.folder') . '/' . $srcFile)) {
        $this->response->data->set('message', 'Thumbs removed successfully');
      } else {
        $this->response->data->set('error', 'Not all thumbs could be removed');
      }
    } catch (\Throwable $e) {
      $this->response->data->set('error', 'Error removing thumbs ' . $e->getMessage());
    }
    return false;
  }

  public function protectImage(string $srcFile)
  {
    try {

      $copyright = new Copyright(
        $this->cfg->get('frmk.publicPath'),
        'protect',
        'media/nepascopier.png',
        'repeat'
      );

      $copyright->protectImage('media/' . $this->state->get('mediamanager.folder') . '/' . $srcFile);

      $this->response->data->set('message', 'Image protégée');
    } catch (\Throwable $e) {
      $this->response->data->set('error', $e->getMessage());
    }
  }

  public function unprotectImage(string $srcFile)
  {
    try {
      $copyright = new Copyright(
        $this->cfg->get('frmk.publicPath'),
        'protect',
        'media/nepascopier.png',
        'repeat'
      );
      $copyright->unprotectImage('media/' . $this->state->get('mediamanager.folder') . '/' . $srcFile);
      $this->response->data->set('message', 'Image déprotégée');
    } catch (\Throwable $e) {
      $this->response->data->set('error', $e->getMessage());
    }
  }

  /** 
   * Retrieve image infos (size, orientation, ..)
   * 
   * @json   true
   */
  public function filInfos(string $srcFile, string $targetFolder)
  {
    $this->response->data->set('filepath', $srcFile);

    $fullPath = normalizePath($this->cfg->get('frmk.publicPath') . 'media/' . $srcFile);
    $this->response->data->set('fullpath', $fullPath);

    if (@file_exists($fullPath)) {
      $mime = mime_content_type($fullPath);
      $this->response->data->set('mime', $mime);

      if (preg_match("/^image\/.+$/", $mime)) {
        list($width, $height) = \getimagesize($fullPath);
        $this->response->data->set('width', $width);
        $this->response->data->set('height', $height);
        $this->response->data->set('ratio', round($width / $height, 1));
        $this->response->data->set('resolution', \imageresolution($fullPath));

        if ($width > $height) {
          $this->response->data->set('orientation', 'landscape');
        } elseif ($width < $height) {
          $this->response->data->set('orientation', 'portrait');
        } else {
          $this->response->data->set('orientation', 'square');
        }
      } else {
        $this->response->data->set('error', 'Not an image');
      }
    } else {
      $this->response->data->set('error', 'File not found');
    }
  }

  public function redactorUpload()
  {
    $this->response->data->set('force', false);

    $data = new \stdClass;
    $data->force = false;

    try {
      $this->doUpload($data);
      $this->response->data->set('status', 200);
    } catch (\Exception $e) {
      $this->response->data->set('error', $e->getMessage());
      $this->response->data->set('status', 403);
    }
  }

  protected function onDisplayToolbar()
  {
    parent::onDisplayToolbar();

    $this->toolbar->addButton('refresh')
      ->setGlyphicon('refresh')
      ->setTip($this->language->_('REFRESH'))
      ->addStyle('btn-lg')
      ->addDataAttr('mm-task', 'dataRefresh');

    $this->toolbar->addButton('afterRefresh', 'divider');

    $this->toolbar->addButton('new-folder')
      ->setGlyphicon('folder-plus')
      ->setTip($this->language->_('TOOLBAR_MEDIA_NEW_FOLDER'))
      ->addStyle('btn-lg')
      ->addDataAttr('mm-task', 'dirCreate');

    $this->toolbar->addButton('move-folder')
      ->setGlyphicon('move')
      ->setTip($this->language->_('TOOLBAR_MEDIA_MOVE_FOLDER'))
      ->addStyle('btn-lg')
      ->addDataAttr('mm-task', 'dirMove');

    $this->toolbar->addButton('rename-folder')
      ->setGlyphicon('edit')
      ->setTip($this->language->_('TOOLBAR_MEDIA_RENAME_FOLDER'))
      ->addStyle('btn-lg')
      ->addDataAttr('mm-task', 'dirRename');

    $this->toolbar->addButton('afterFolder', 'divider');

    $this->toolbar->addButton('new-file')
      ->setGlyphicon('upload')
      ->setTip($this->language->_('TOOLBAR_MEDIA_NEW_FILE'))
      ->addStyle('btn-lg')
      ->addDataAttr('mm-task', 'filUpload');

    $this->toolbar->addButton('beforeDisplay', 'divider');

    $this->toolbar->addButton('display-thumbs')
      ->setGlyphicon('show-thumbnails')
      ->setTip($this->language->_('THUMBNAILS'))
      ->addStyle('btn-lg')
      ->addDataAttr('mm-task', 'displayThumbs');

    $this->toolbar->addButton('display-list')
      ->setGlyphicon('show-lines')
      ->setTip($this->language->_('LIST'))
      ->addStyle('btn-lg')
      ->addDataAttr('mm-task', 'displaySimple');
  }

  protected function mediaResponse(array $data = [])
  {
    // if ( 'undefined' === $this->state->get('mediamanager.folder') ){
    // $this->state->set('mediamanager.folder', '');
    // }

    $this->response->data->sets(array_merge($data, [
      'dz' => $this->getDzConfig(),
      'stats' => $this->getFilesystemStats(),
      'breadcrumbs' => $this->getBreadcrumbs(),
      'filesystem' => $this->getFilesystem(),
      'folders' => $this->getFolders(),
      'mm' => [
        'folder' => $this->state->get('mediamanager.folder'),
        'display' => $this->state->get('mediamanager.display'),
        'only' => $this->state->get('mediamanager.only'),
        'file' => $this->state->get('mediamanager.file'),
      ],
    ]));
  }

  protected function getFilesystemStats(): \stdClass
  {
    static $stats;

    if (!isset($stats)) {
      $fs = new \Callisto\Kernel\Filesystem();
      $files = $fs->files($this->basePath, ['full_path' => true]);

      $stats = new \stdClass;
      $stats->maxWeightFiles = $this->cfg->get('components.medias.maxWeightFiles');
      $stats->maxNumFiles = $this->cfg->get('components.medias.maxNumFiles');
      $stats->numFiles = count($files);
      $stats->weightFiles = 0;
      $stats->uploadable = true;
      $stats->errors = [];

      if ($stats->numFiles >= $stats->maxNumFiles) {
        $stats->errors[] = $this->language->_('MEDIAMANAGER_ERROR_UPLOAD_TOO_MANY_FILES', ['%maxFileCount%' => $stats->maxNumFiles]);
        $stats->uploadable = false;
      }

      foreach ($files as $file) {
        $stats->weightFiles += filesize($file);
      }

      $stats->weightFiles /= 1000000;
      $stats->weightFiles = ceil($stats->weightFiles);

      if ($stats->weightFiles >= $stats->maxWeightFiles) {
        $stats->errors[] = $this->language->_('MEDIAMANAGER_ERROR_UPLOAD_FILESYSTEM_FULL', ['%maxWeightFiles%' => $stats->maxWeightFiles]);
        $stats->uploadable = false;
      }
    }

    return $stats;
  }

  protected function checkClientAuthToUpload()
  {
    $maxNumFiles = (int)$this->cfg->get('components.medias.maxNumFiles');
    $maxWeightFiles = (int)$this->cfg->get('components.medias.maxWeightFiles');

    $fs = new \Callisto\Kernel\Filesystem();
    $files = $fs->files($this->basePath, ['fullpath' => true]);
    if (count($files) >= $maxNumFiles) {
      throw new \Exception($this->language->_('MEDIAMANAGER_ERROR_UPLOAD_TOO_MANY_FILES', ['%maxFileCount%' => $maxNumFiles]));
    }

    $fsWeight = 0;
    foreach ($files as $file) {
      $fsWeight += @filesize($file);
    }

    if ($fsWeight > $maxWeightFiles * 1000000) {
      throw new \Exception($this->language->_('MEDIAMANAGER_ERROR_UPLOAD_FILE_TOO_BIG', ['%maxFileSize%' => $maxWeightFiles]));
    }
  }

  protected function doUpload(\stdClass $data)
  {
    $stats = $this->getFilesystemStats();
    $adminUpload = null;

    try {
      if ($stats->errors) {
        throw new \Exception(implode(' - ', $stats->errors));
      }

      $this->checkClientAuthToUpload();

      $file = $this->request->files->get('fileUploadName');

      // @chmod($this->path, 0755);
      // @chmod($this->path, 0755);

      $adminUpload = $this->getAdminUploadObject($data->folder, $data->force);
      $adminUpload->upload($file);

      $data->originalName = $adminUpload->originalName;
      $data->id = $adminUpload->cleanFilename;
      $data->filename = $adminUpload->filename;
      $data->value = $data->folder . '/' . $adminUpload->filename;
      $data->url = '../media/' . $data->folder . '/' . $adminUpload->filename;
    } catch (\Exception $e) {
      // if ( $adminUpload && $adminUpload->path ){
      // @chmod($adminUpload->path, 0644);
      // }

      // throw new \Exception((string)$e);
      throw $e;
    }
  }

  protected function getAdminUploadObject(string $folder, bool $force): AdminUpload
  {
    $mimesDocument = $this->cfg->get('components.medias.mimesDocument');
    $mimesImage = $this->cfg->get('components.medias.mimesImage');
    $authMimes = array_merge($mimesDocument, $mimesImage);

    $extsDocument = $this->cfg->get('components.medias.extsDocument');
    $extsImage = $this->cfg->get('components.medias.extsImage');
    $authExts = array_merge($extsDocument, $extsImage);

    $maxWeightDocument = $this->cfg->get('components.medias.maxWeightDocument');
    $maxWeightImage = $this->cfg->get('components.medias.maxWeightImage');
    $maxWeight = max(1, $maxWeightDocument, $maxWeightImage);

    return (new AdminUpload())
      ->setLanguage($this->language)
      ->setPath(normalizePath($this->cfg->get('frmk.publicPath') . 'media/' . $folder . '/'))
      ->setOverwrite($force)
      ->setAuthMimes($authMimes)
      ->setAuthExts($authExts)
      ->setMaxWeight($maxWeight)
      ->setDocumentAuthMimes($mimesDocument)
      ->setDocumentAuthExts($extsDocument)
      ->setDocumentMaxWeight($maxWeightDocument)
      ->setImageAuthMimes($mimesImage)
      ->setImageAuthExts($extsImage)
      ->setImageMaxWeight($maxWeightImage)
      ->setMaxPictureLongSide($this->cfg->get('components.medias.maxPictureLongSide'));
  }

  protected function getFilesystem(): array
  {
    $folder = $this->state->get('mediamanager.folder');
    $folder = trim($folder, '/');
    $parts  = $folder !== '' ? explode('/', $folder) : [];

    $filesystem = [
      'value'    => '',
      'previous' => false,
      'folders'  => [],
      'files'    => [],
    ];

    if (count($parts) > 0) {
      $path = normalizePath($this->basePath . implode('/', $parts));
      if (!@file_exists($path)) {
        // @todo : check for each parent folder until Root
        $folder = '';
        $parts = [];
        $path = $this->basePath;
      } else {
        $filesystem['value']    = implode('/', $parts);
        $filesystem['name']     = array_pop($parts);
        $filesystem['previous'] = implode('/', $parts);
      }
    } else {
      $path = $this->basePath;
    }

    // FOLDERS
    $fs = new \Callisto\Kernel\Filesystem();
    $folders = $fs->folders($path);

    foreach ($folders as $_folder) {
      $fullpath = normalizePath($path . '/' . $_folder);
      $value    = trim(str_replace([$this->basePath, DIRECTORY_SEPARATOR], ['', '/'], $fullpath), '/');
      $value    = trim($value, '/');
      $parts    = explode('/', $value);

      $filesystem['folders'][] = [
        'value'     => implode('/', $parts),
        'name'      => array_pop($parts),
        'previous'  => implode('/', $parts),
        'type'      => 'folder',
        'icon'      => 'folder-closed',
      ];
    }

    // FILES
    $mimes = [];
    switch ($this->state->get('mediamanager.only')) {
      case 'images':
        $mimes[] = "image\/.+";
        break;

      case 'videos':
        $mimes[] = "video\/.+";
        break;

      case 'audios':
        $mimes[] = "audio\/.+";
        break;

      case 'documents':
        $mimes[] = "application\/pdf";
        $mimes[] = "text\/plain";
        $mimes[] = "application\/vnd\.(oasis|ms|openxmlformats).+";
        $mimes[] = "application\/msword";
        break;

      default:
        // $mimes[] = "image\/.+";
        // $mimes[] = "video\/.+";
        // $mimes[] = "audio\/.+";
        // $mimes[] = "application\/pdf";
        // $mimes[] = "text\/plain";
        // $mimes[] = "application\/vnd\.(oasis|ms|openxmlformats).+)";
        // $mimes[] = "application\/msword";
        break;
    }

    $_files = $fs->files($path, ['max_depth' => '== 0']);
    foreach ($_files as $_file) {
      $fullpath = normalizePath($path . '/' . $_file);
      $value = trim(str_replace($this->basePath, '', $fullpath), '/');
      $mime = \mime_content_type($fullpath);

      if (!empty($mimes) && !preg_match("/^(" . implode(')|(', $mimes) . ")$/", $mime)) {
        continue;
      }

      $fi = new \SplFileInfo($_file);

      $file = [
        'value' => $_file,
        'name' => $_file,
        'ext' => $fi->getExtension(),
      ];

      if (preg_match("/^image\/.+$/", $mime)) {
        $value = str_replace(\DIRECTORY_SEPARATOR, '/', $value);

        $image = new Image($this->cfg->get('frmk.publicPath'), '../');
        $image->lazy = true;
        $image->cacheLife = $this->cfg->getInt('cache.thumbs');
        $image->targetWidth = 150;
        $image->load('media/' . $value);

        if (true === $image->valid) {
          $file['ext'] = $image->source->ext;
          $file['thumb'] = '../' . ($image->lazy ? $image->thumb : $image->source->srcFile) . '?v=' . \uniqid();
        } else {
          $file['thumb'] = '';
        }
      } else {
        $file['thumb'] = '';
      }

      $filesystem['files'][] = $file;
    }

    return $filesystem;
  }

  protected function getBreadcrumbs(): array
  {
    $folder = $this->state->get('mediamanager.folder');
    $folder = trim($folder, '/');
    $parts  = $folder !== '' ? explode('/', $folder) : [];

    $breadcrumbs = [];

    $breadcrumbs[] = [
      'folder' => '',
      'title'  => 'Medias',
    ];

    if (count($parts) > 0) {
      $breadcrumbPath = [];
      foreach ($parts as $part) {
        $breadcrumbPath[] = $part;

        $breadcrumbs[] = [
          'folder' => implode('[-]', $breadcrumbPath),
          'title'  => $part,
        ];
      }
    }

    return $breadcrumbs;
  }

  protected function getFolders(string $folder = ''): array
  {
    $folders = $this->getFoldersChildren();
    array_unshift($folders, ['value' => '', 'name' => 'Racine', 'level' => 0]);
    return $folders;
  }

  protected function getFoldersChildren(string $folder = ''): array
  {
    $folders = [];

    $folder = trim($folder, '/');
    $parts  = $folder !== '' ? explode('/', $folder) : [];

    if (count($parts) > 0) {
      $path = $this->basePath . implode('/', $parts);
    } else {
      $path = $this->basePath;
    }

    $path = normalizePath($path);

    $fs = new \Callisto\Kernel\Filesystem();
    if ($_folders = $fs->folders($path)) {
      foreach ($_folders as $_folder) {
        $fullpath = normalizePath($path . '/' . $_folder);
        $value    = trim(str_replace([$this->basePath, DIRECTORY_SEPARATOR], ['', '/'], $fullpath), '/');
        $value    = trim($value, '/');
        $parts    = explode('/', $value);

        $level = count($parts);
        $value = implode('/', $parts);

        $folders[] = [
          'value' => $value,
          'name' => array_pop($parts),
          'level' => $level,
        ];

        $folders = array_merge($folders, $this->getFoldersChildren($value));
      }
    }

    return $folders;
  }

  protected function isPathEmpty(string $path): bool
  {
    $fs = new \Callisto\Kernel\Filesystem();
    $path    = normalizePath($path);
    $folders = $fs->folders($path);
    $files   = $fs->files($path);
    return (count($folders) === 0 && count($files) === 0);
  }

  protected function tree(?string $path = null, int $level = 1): array
  {
    static $folder, $folderParts;

    if (!isset($folder)) {
      $folder = $this->state->get('mediamanager.folder');
      $folderParts = explode('/', $folder);
    }

    $tree = [];

    if ($path === null) {
      $path = $this->basePath;
    }

    $folders = new Finder();
    $folders->directories()->in($path)->depth(0)->sortByName();

    foreach ($folders as $_folder) {
      $fullpath = $_folder->getRealPath();
      $value    = trim(str_replace([$this->basePath, DIRECTORY_SEPARATOR], ['', '/'], $fullpath), '/');
      $parts    = explode('/', $value);
      $selected = implode('/', $parts) === implode('/', $folderParts);
      $active   = $selected;

      if ($active === false) {
        $p1 = [];
        $p2 = [];
        $ignore = false;
        for ($i = 0; $i < $level; $i++) {
          if (!isset($folderParts[$i])) {
            $ignore = true;
            break;
          }
          $p1[] = $folderParts[$i];
          $p2[] = $parts[$i];
        }

        if ($ignore === false) {
          $p1 = implode('/', $p1);
          $p2 = implode('/', $p2);

          $active = $p1 === $p2;
        }
      }

      $tree[] = (object) [
        'value'     => implode('[-]', $parts),
        'readable'  => implode('/', $parts),
        'text'      => array_pop($parts),
        'level'     => $level,
        'active'    => $active,
        'selected'  => $selected,
        'children'  => $this->tree($fullpath, $level + 1),
      ];
    }

    return $tree;
  }

  protected function cleanJsFolderPath(string $path): string
  {
    $path = urldecode($path);
    $path = str_replace('[-]', '/', $path);
    return $path;
  }

  /** 
   * List images in specified folder and subfolders for Js media selector
   * 
   * @json   true
   */
  protected function mediaSelectorFolder(array &$list, string $folder, int $level = 1)
  {
    $folder = trim($folder, '/');

    if (false === @file_exists($this->basePath . $folder)) {
      throw new \Exception('Folder ' . $folder . ' not found');
    }

    $fs = new \Callisto\Kernel\Filesystem();
    if ($images = $fs->files($this->basePath . $folder, ['max_depth' => '== 0', 'no_underscore' => true, 'extensions' => ['png', 'jpg', 'jpeg', 'gif']])) {
      $group = new \stdClass;
      $group->name = $folder;
      $group->level = $level;

      foreach ($images as $image) {
        $imagePath = ($folder ? $folder . '/' : '') . $image;

        $image = new Image($this->cfg->get('frmk.publicPath'), '../');
        $image->lazy = true;
        $image->cacheLife = $this->cfg->getInt('cache.thumbs');
        $image->targetWidth = 200;
        $image->load('media/' . $imagePath);

        if (false === $image->valid) {
          continue;
        }

        $file = new \stdClass;
        $file->value = $imagePath;
        $file->text = $image->source->name;
        $file->valid = true;
        $file->thumb = $image->thumb;
        $file->orientation = $image->orientation;
        $file->url = '../media/' . $imagePath;
        $group->files[] = $file;
      }

      $list[] = $group;
    }

    if ($subfolders = $fs->folders($this->basePath . $folder, ['max_depth' => '== 0', 'no_underscore' => true])) {
      foreach ($subfolders as $subfolder) {
        $this->mediaSelectorFolder($list, $folder . '/' . $subfolder, $level + 1);
      }
    }
  }

  /** 
   * Dropzone config
   * 
   * @json   true
   */
  protected function getDzConfig(string $uploadType = ''): array
  {
    $cfg = $this->getFilesystemConfig();

    $dz = [];
    $dz['url'] = $this->router->url('mediaDropzone');
    // $dz['maxFiles'] = 3;

    if ('image' === $uploadType) {
      $dz['acceptedFiles'] = implode(',', $cfg->imageAcceptedFiles);
      $dz['maxFilesize'] = $cfg->maxWeightImage;
    } elseif ('document' === $uploadType) {
      $dz['acceptedFiles'] = implode(',', $cfg->documentAcceptedFiles);
      $dz['maxFilesize'] = $cfg->maxWeightDocument;
    } else {
      $dz['acceptedFiles'] = implode(',', $cfg->acceptedFiles);
      $dz['maxFilesize'] = $cfg->maxWeight;
    }

    return $dz;
  }

  protected function getFilesystemConfig(): \stdClass
  {
    static $config;

    if (!isset($config)) {
      $config = new \stdClass;

      $mimesDocument = $this->cfg->get('components.medias.mimesDocument');
      $mimesImage = $this->cfg->get('components.medias.mimesImage');
      $authMimes = array_merge($mimesDocument, $mimesImage);

      $extsDocument = $this->cfg->get('components.medias.extsDocument');
      $extsImage = $this->cfg->get('components.medias.extsImage');
      $authExts = array_merge($extsDocument, $extsImage);

      $acceptedFiles = [];
      foreach ($authMimes as $mime) {
        $acceptedFiles[] = $mime;
      }
      foreach ($authExts as $ext) {
        $acceptedFiles[] = '.' . $ext;
      }

      $imageAcceptedFiles = [];
      foreach ($mimesImage as $mime) {
        $imageAcceptedFiles[] = $mime;
      }
      foreach ($extsImage as $ext) {
        $imageAcceptedFiles[] = '.' . $ext;
      }

      $documentAcceptedFiles = [];
      foreach ($mimesDocument as $mime) {
        $documentAcceptedFiles[] = $mime;
      }
      foreach ($extsDocument as $ext) {
        $documentAcceptedFiles[] = '.' . $ext;
      }

      $maxWeightDocument = $this->cfg->get('components.medias.maxWeightDocument');
      $maxWeightImage = $this->cfg->get('components.medias.maxWeightImage');
      $maxWeight = max(1, $maxWeightDocument, $maxWeightImage);

      $config->acceptedFiles = $acceptedFiles;
      $config->authMimes = $authMimes;
      $config->authExts = $authExts;
      $config->maxWeight = $maxWeight;

      $config->documentAcceptedFiles = $documentAcceptedFiles;
      $config->mimesDocument = $mimesDocument;
      $config->extsDocument       = $extsDocument;
      $config->maxWeightDocument  = $maxWeightDocument;

      $config->imageAcceptedFiles = $imageAcceptedFiles;
      $config->mimesImage         = $mimesImage;
      $config->extsImage          = $extsImage;
      $config->maxWeightImage     = $maxWeightImage;
    }

    return $config;
  }

  protected function fetchSystemFolders(): array
  {
    $folders = [];

    $configFile = $this->cfg->get('frmk.rootPath') . '/config/mediacleaner.yml';

    if (file_exists($configFile) && ($data = Yaml::parseFile($configFile))) {
      $data = (object)$data;

      foreach ($data->folders as $folder) {
        $folder = (object)$folder;

        if (true === $folder->system) {
          $folders[] = $folder->name;
        }
      }
    }

    return $folders;
  }
}
