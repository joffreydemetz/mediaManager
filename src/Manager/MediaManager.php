<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace JDZ\MediaManager\Manager;

use JDZ\MediaManager\Tree\DocumentTree;
use JDZ\MediaManager\Tree\ImageTree;
use JDZ\MediaManager\ValueObject\FileInfo;
use JDZ\MediaManager\ValueObject\FilesystemStats;
use JDZ\MediaManager\ValueObject\FolderInfo;
use JDZ\MediaManager\ValueObject\ImageMetadata;
use JDZ\MediaManager\ValueObject\MediaConfig;
use JDZ\MediaManager\ValueObject\OperationResult;
use JDZ\MediaManager\ValueObject\UploadResult;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A media library : browse it, reshape it, fill it.
 *
 * Every path that comes in is treated as hostile and resolved through
 * {@see PathResolver}; every operation answers with an {@see OperationResult}
 * carrying a translation key rather than a message, so the package stays free
 * of any language layer.
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class MediaManager
{
  /** Never listed, whatever the folder */
  private const NOISE_FILES = ['.DS_Store', 'Thumbs.db'];

  private PathResolver $paths;
  private ?Uploader $uploader = null;
  private ?ImageProtector $protector = null;
  private ?FilesystemStats $stats = null;

  public function __construct(private readonly MediaConfig $config)
  {
    $this->paths = new PathResolver($config->rootPath);
  }

  public function config(): MediaConfig
  {
    return $this->config;
  }

  public function paths(): PathResolver
  {
    return $this->paths;
  }

  // ---------------------------------------------------------------- codec --

  public function decodePath(string $wire): string
  {
    return $this->paths->decode($wire);
  }

  public function encodePath(string $path): string
  {
    return $this->paths->encode($path);
  }

  public function isSystemFolder(string $folder): bool
  {
    return $this->config->isSystemFolder($folder);
  }

  // -------------------------------------------------------------- listing --

  /**
   * The contents of one folder, as the browser expects them.
   *
   * An unknown folder falls back to the root rather than erroring — the
   * session may well point at something that has since been deleted.
   *
   * @param string        $only           '', images, videos, audios or documents
   * @param callable|null $thumbResolver  fn(string $relPath, string $fileName): ?string
   */
  public function getFilesystem(string $folder, string $only = '', ?callable $thumbResolver = null): array
  {
    $folder = \trim($folder, '/');
    $path = $this->paths->folderPath($folder);

    if (null === $path || false === @\is_dir($path)) {
      $folder = '';
      $path = $this->paths->getRootPath();
    }

    $filesystem = [
      'value' => '',
      'previous' => false,
      'folders' => [],
      'files' => [],
    ];

    if ('' !== $folder) {
      $parts = \explode('/', $folder);

      $filesystem['value'] = $folder;
      $filesystem['name'] = \array_pop($parts);
      $filesystem['previous'] = \implode('/', $parts);
    }

    foreach ($this->childFolderNames($path) as $name) {
      $value = ('' === $folder ? '' : $folder . '/') . $name;
      $parts = \explode('/', $value);

      $filesystem['folders'][] = [
        'value' => $value,
        'name' => \array_pop($parts),
        'previous' => \implode('/', $parts),
        'type' => 'folder',
        'icon' => 'folder-closed',
      ];
    }

    $mimeFilter = $this->mimeFilter($only);

    foreach ($this->childFileNames($path) as $name) {
      $fullpath = $path . '/' . $name;
      $mime = $this->mimeOf($fullpath);

      if ('' !== $mimeFilter && 1 !== \preg_match($mimeFilter, $mime)) {
        continue;
      }

      $file = [
        'value' => $name,
        'name' => $name,
        'ext' => (new \SplFileInfo($name))->getExtension(),
        'thumb' => '',
      ];

      if (null !== $thumbResolver && 1 === \preg_match('/^image\/.+$/', $mime)) {
        $relPath = ('' === $folder ? '' : $folder . '/') . $name;
        $file['thumb'] = (string)($thumbResolver($relPath, $name) ?? '');
      }

      $filesystem['files'][] = $file;
    }

    return $filesystem;
  }

  /**
   * Trail from the library root down to `$folder`, folder values wire-encoded.
   */
  public function getBreadcrumbs(string $folder): array
  {
    $breadcrumbs = [[
      'folder' => '',
      'title' => 'Medias',
    ]];

    $folder = \trim($folder, '/');

    if ('' === $folder) {
      return $breadcrumbs;
    }

    $trail = [];

    foreach (\explode('/', $folder) as $part) {
      $trail[] = $part;

      $breadcrumbs[] = [
        'folder' => \implode('[-]', $trail),
        'title' => $part,
      ];
    }

    return $breadcrumbs;
  }

  /**
   * Every folder in the library, flattened, for a `<select>`.
   */
  public function getFolderSelectList(): array
  {
    $folders = $this->folderChildren('');
    \array_unshift($folders, ['value' => '', 'name' => 'Racine', 'level' => 0]);

    return $folders;
  }

  /**
   * The folder tree, wrapped in its ROOT node, with the branch leading to
   * `$activeFolder` flagged active.
   */
  public function getTree(string $activeFolder): object
  {
    return (object)[
      'value' => '',
      'readable' => '',
      'text' => 'ROOT',
      'level' => 1,
      'active' => true,
      'children' => $this->treeChildren('', \trim($activeFolder, '/'), 1),
    ];
  }

  /**
   * Images of `$folder` and every folder below it, grouped per folder, for the
   * picker. Folders and files whose name starts with `_` are skipped.
   *
   * @param callable|null $imageResolver  fn(string $relPath): ?array{thumb: string, orientation: string}
   *                                      returning null drops the file
   */
  public function getSelectorList(string $folder, string $urlBase = '', ?callable $imageResolver = null): array
  {
    $list = [];
    $this->selectorFolder($list, \trim($folder, '/'), $urlBase, $imageResolver, 1);

    return $list;
  }

  public function getRedactorImageTree(string $baseUrl, array $ignoreFolders = [], array $ignoreFiles = []): array
  {
    return (new ImageTree())
      ->setBasePath($this->treeBasePath())
      ->setBaseUrl($baseUrl)
      ->setIgnoreFolders($ignoreFolders)
      ->setIgnoreFiles($ignoreFiles)
      ->getTree();
  }

  public function getRedactorDocumentTree(string $baseUrl, array $ignoreFolders = [], array $ignoreFiles = []): array
  {
    return (new DocumentTree())
      ->setBasePath($this->treeBasePath())
      ->setBaseUrl($baseUrl)
      ->setIgnoreFolders($ignoreFolders)
      ->setIgnoreFiles($ignoreFiles)
      ->getTree();
  }

  // ------------------------------------------------------- infos & counts --

  public function getFolderInfos(string $folder): ?FolderInfo
  {
    $folder = \trim($folder, '/');
    $path = $this->paths->folderPath($folder);

    if (null === $path || false === @\is_dir($path)) {
      return null;
    }

    $parts = '' === $folder ? [] : \explode('/', $folder);

    $nbFolders = \iterator_count(Finder::create()->directories()->in($path)->ignoreUnreadableDirs());

    $nbFiles = 0;
    $sizeBytes = 0;

    foreach ($this->allFiles($path) as $file) {
      $nbFiles++;
      $sizeBytes += (int)$file->getSize();
    }

    return new FolderInfo(
      name: $parts ? (string)\end($parts) : '',
      path: $folder,
      nbFolders: $nbFolders,
      nbFiles: $nbFiles,
      sizeBytes: $sizeBytes,
    );
  }

  public function getFileInfos(string $folder, string $fileName): ?FileInfo
  {
    $path = $this->paths->filePath($folder, $fileName);

    if (null === $path || false === @\is_file($path)) {
      return null;
    }

    $fi = new \SplFileInfo($path);
    $ext = $fi->getExtension();
    $mime = $this->mimeOf($path);
    $isImage = 1 === \preg_match('/^image\/.+$/', $mime);

    $width = null;
    $height = null;

    if (true === $isImage && false !== ($size = @\getimagesize($path))) {
      [$width, $height] = $size;
    }

    return new FileInfo(
      name: $fileName,
      namenoext: $fi->getBasename('.' . $ext),
      ext: $ext,
      folder: \trim($folder, '/'),
      mime: $mime,
      sizeBytes: (int)$fi->getSize(),
      createdAt: false !== ($ctime = $fi->getCTime()) ? $ctime : null,
      modifiedAt: false !== ($mtime = $fi->getMTime()) ? $mtime : null,
      isImage: $isImage,
      width: $width,
      height: $height,
    );
  }

  /**
   * Geometry of an image addressed by its library-relative path.
   */
  public function getImageMetadata(string $relPath): ?ImageMetadata
  {
    $relPath = \trim(\str_replace('\\', '/', $relPath), '/');
    $parts = '' === $relPath ? [] : \explode('/', $relPath);

    if ([] === $parts) {
      return null;
    }

    $fileName = \array_pop($parts);
    $path = $this->paths->filePath(\implode('/', $parts), $fileName);

    if (null === $path || false === @\is_file($path)) {
      return null;
    }

    $mime = $this->mimeOf($path);

    if (1 !== \preg_match('/^image\/.+$/', $mime) || false === ($size = @\getimagesize($path))) {
      return ImageMetadata::forOther($relPath, $path, $mime);
    }

    return ImageMetadata::forImage($relPath, $path, $mime, (int)$size[0], (int)$size[1]);
  }

  /**
   * Library counters. Computed once, then held until something changes them.
   */
  public function getStats(): FilesystemStats
  {
    if (null === $this->stats) {
      $numFiles = 0;
      $totalBytes = 0;

      foreach ($this->allFiles($this->paths->getRootPath()) as $file) {
        $numFiles++;
        $totalBytes += (int)$file->getSize();
      }

      $this->stats = FilesystemStats::measure(
        $numFiles,
        $totalBytes,
        $this->config->maxNumFiles,
        $this->config->maxWeightFiles,
      );
    }

    return $this->stats;
  }

  public function refreshStats(): void
  {
    $this->stats = null;
  }

  /**
   * Dropzone limits for a kind of upload : '', 'image' or 'document'.
   */
  public function getDropzoneParams(string $uploadType = ''): array
  {
    if ('image' === $uploadType) {
      return [
        'acceptedFiles' => \implode(',', $this->config->imageAcceptedFiles()),
        'maxFilesize' => $this->config->maxWeightImage,
      ];
    }

    if ('document' === $uploadType) {
      return [
        'acceptedFiles' => \implode(',', $this->config->documentAcceptedFiles()),
        'maxFilesize' => $this->config->maxWeightDocument,
      ];
    }

    return [
      'acceptedFiles' => \implode(',', $this->config->acceptedFiles()),
      'maxFilesize' => $this->config->maxWeight(),
    ];
  }

  // ------------------------------------------------------- folder actions --

  public function createFolder(string $parentFolder, string $name): OperationResult
  {
    $name = \trim($name);

    if ('' === $name) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_ENTER_FOLDER_NAME');
    }

    $parentFolder = \trim($parentFolder, '/');
    $folder = ('' === $parentFolder ? '' : $parentFolder . '/') . $name;
    $path = $this->paths->folderPath($folder);

    if (null === $path) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_INVALID_PATH');
    }

    if (true === @\file_exists($path)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_DESTINATION_FOLDER_ALREADY_EXISTS');
    }

    try {
      (new Filesystem())->mkdir($path);
    } catch (\Throwable $e) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_OPERATION_FAILED', ['%error%' => $e->getMessage()]);
    }

    return OperationResult::ok('MEDIAMANAGER_SUCCESS_DIRCREATE', [], ['folder' => $this->paths->relative($path)]);
  }

  public function renameFolder(string $folder, string $newName): OperationResult
  {
    $folder = \trim($folder, '/');

    if (null !== ($guard = $this->guardFolderIsEditable($folder))) {
      return $guard;
    }

    $newName = \trim($this->paths->decode($newName), '/');

    if ('' === $newName) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_ENTER_FOLDER_NEW_NAME');
    }

    $parts = \explode('/', $folder);
    $oldName = \array_pop($parts);

    if ($oldName === $newName) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_FOLDER_NAME_UNCHANGED');
    }

    $parts[] = $newName;
    $newFolder = \implode('/', $parts);

    $oldPath = $this->paths->folderPath($folder);
    $newPath = $this->paths->folderPath($newFolder);

    if (null === $oldPath || null === $newPath) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_INVALID_PATH');
    }

    if (false === @\file_exists($oldPath)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_SOURCE_FOLDER_INVALID');
    }

    if (true === @\file_exists($newPath)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_DESTINATION_FOLDER_ALREADY_EXISTS');
    }

    if (null !== ($failure = $this->rename($oldPath, $newPath))) {
      return $failure;
    }

    return OperationResult::ok('MEDIAMANAGER_SUCCESS_DIRRENAME', [], ['folder' => $newFolder]);
  }

  public function moveFolder(string $folder, string $newParentFolder): OperationResult
  {
    $folder = \trim($folder, '/');

    if (null !== ($guard = $this->guardFolderIsEditable($folder))) {
      return $guard;
    }

    $newParentFolder = \trim($this->paths->decode($newParentFolder), '/');

    if (true === $this->paths->isSelfOrDescendant($folder, $newParentFolder)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_CANNOT_MOVE_INTO_ITSELF');
    }

    $parts = \explode('/', $folder);
    $name = \array_pop($parts);

    if (\implode('/', $parts) === $newParentFolder) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_SAME_FILEPATH');
    }

    $newFolder = ('' === $newParentFolder ? '' : $newParentFolder . '/') . $name;

    $oldPath = $this->paths->folderPath($folder);
    $parentPath = $this->paths->folderPath($newParentFolder);
    $newPath = $this->paths->folderPath($newFolder);

    if (null === $oldPath || null === $parentPath || null === $newPath) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_INVALID_PATH');
    }

    if (false === @\file_exists($oldPath)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_SOURCE_FOLDER_NOT_FOUND');
    }

    if (false === @\is_dir($parentPath)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_DESTINATION_FOLDER_NOT_FOUND');
    }

    if (true === @\file_exists($newPath)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_DESTINATION_FOLDER_ALREADY_EXISTS');
    }

    if (null !== ($failure = $this->rename($oldPath, $newPath))) {
      return $failure;
    }

    return OperationResult::ok('MEDIAMANAGER_SUCCESS_DIRMOVE', [], ['folder' => $newFolder]);
  }

  public function deleteFolder(string $folder): OperationResult
  {
    $folder = \trim($folder, '/');

    if (null !== ($guard = $this->guardFolderIsEditable($folder))) {
      return $guard;
    }

    $path = $this->paths->folderPath($folder);

    if (null === $path) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_INVALID_PATH');
    }

    if (false === @\is_dir($path)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_SOURCE_FOLDER_NOT_FOUND');
    }

    if (false === $this->isPathEmpty($path)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_FOLDER_NOT_EMPTY');
    }

    try {
      (new Filesystem())->remove($path);
    } catch (\Throwable $e) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_OPERATION_FAILED', ['%error%' => $e->getMessage()]);
    }

    $this->refreshStats();

    $parts = \explode('/', $folder);
    \array_pop($parts);

    return OperationResult::ok('MEDIAMANAGER_SUCCESS_DIRDELETE', [], ['parent' => \implode('/', $parts)]);
  }

  // --------------------------------------------------------- file actions --

  public function renameFile(string $folder, string $fileName, string $newBaseName): OperationResult
  {
    if ('' === $fileName) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_NO_FILE_SPECIFIED');
    }

    $newBaseName = \trim($newBaseName);

    if ('' === $newBaseName) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_ENTER_FILE_NEW_NAME');
    }

    $srcPath = $this->paths->filePath($folder, $fileName);

    if (null === $srcPath) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_INVALID_PATH');
    }

    if (false === @\file_exists($srcPath)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_SOURCE_FILE_NOT_FOUND');
    }

    $ext = (new \SplFileInfo($srcPath))->getExtension();
    $newFileName = $newBaseName . ('' === $ext ? '' : '.' . $ext);

    $destPath = $this->paths->filePath($folder, $newFileName);

    if (null === $destPath) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_INVALID_PATH');
    }

    if ($srcPath === $destPath) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_FILE_NAME_UNCHANGED');
    }

    if (true === @\file_exists($destPath)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_DESTINATION_FILE_ALREADY_EXISTS');
    }

    if (null !== ($failure = $this->rename($srcPath, $destPath))) {
      return $failure;
    }

    return OperationResult::ok('MEDIAMANAGER_SUCCESS_FILRENAME', [], ['file' => $newFileName]);
  }

  public function moveFile(string $folder, string $fileName, string $newFolder): OperationResult
  {
    if ('' === $fileName) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_NO_FILE_SPECIFIED');
    }

    $folder = \trim($folder, '/');
    $newFolder = \trim($this->paths->decode($newFolder), '/');

    if ($folder === $newFolder) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_SAME_FILEPATH');
    }

    $srcPath = $this->paths->filePath($folder, $fileName);
    $destFolderPath = $this->paths->folderPath($newFolder);
    $destPath = $this->paths->filePath($newFolder, $fileName);

    if (null === $srcPath || null === $destFolderPath || null === $destPath) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_INVALID_PATH');
    }

    if (false === @\file_exists($srcPath)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_SOURCE_FILE_NOT_FOUND');
    }

    if (false === @\is_dir($destFolderPath)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_DESTINATION_FOLDER_NOT_FOUND');
    }

    if (true === @\file_exists($destPath)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_DESTINATION_FILE_ALREADY_EXISTS');
    }

    if (null !== ($failure = $this->rename($srcPath, $destPath))) {
      return $failure;
    }

    return OperationResult::ok('MEDIAMANAGER_SUCCESS_FILMOVE', [], ['folder' => $newFolder, 'file' => $fileName]);
  }

  public function deleteFile(string $folder, string $fileName): OperationResult
  {
    if ('' === $fileName) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_NO_FILE_SPECIFIED');
    }

    $path = $this->paths->filePath($folder, $fileName);

    if (null === $path) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_INVALID_PATH');
    }

    if (false === @\file_exists($path)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_SOURCE_FILE_NOT_FOUND');
    }

    try {
      (new Filesystem())->remove($path);
    } catch (\Throwable $e) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_OPERATION_FAILED', ['%error%' => $e->getMessage()]);
    }

    $this->refreshStats();

    return OperationResult::ok('MEDIAMANAGER_SUCCESS_FILDELETE', ['%filename%' => $fileName]);
  }

  /**
   * Absolute path of a file that may safely be streamed out.
   *
   * @return string|null  null when the request points outside the library
   */
  public function resolveDownloadPath(string $folder, string $fileName): ?string
  {
    $path = $this->paths->filePath($folder, $fileName);

    if (null === $path || false === @\is_file($path)) {
      return null;
    }

    return $path;
  }

  // -------------------------------------------------------------- uploads --

  public function upload(UploadedFile $file, string $folder, bool $overwrite = false): UploadResult
  {
    $stats = $this->getStats();

    if (false === $stats->uploadable) {
      $error = $stats->errors[0] ?? ['key' => 'MEDIAMANAGER_ERROR_UPLOAD_FILESYSTEM_FULL', 'params' => []];

      return UploadResult::fail($error['key'], $error['params'], $file->getClientOriginalName());
    }

    $folder = \trim($this->paths->decode($folder), '/');
    $path = $this->paths->folderPath($folder);

    if (null === $path || false === @\is_dir($path)) {
      return UploadResult::fail('MEDIAMANAGER_ERROR_INVALID_PATH', [], $file->getClientOriginalName());
    }

    $result = $this->uploader()->upload($file, $path, $folder, $overwrite);

    if (true === $result->isSuccess()) {
      $this->refreshStats();
    }

    return $result;
  }

  // ---------------------------------------------------------- protection ---

  public function protectImage(string $folder, string $fileName): OperationResult
  {
    $path = $this->paths->filePath($folder, $fileName);

    if (null === $path) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_INVALID_PATH');
    }

    return $this->protector()->protect($path, $fileName);
  }

  public function unprotectImage(string $folder, string $fileName): OperationResult
  {
    $path = $this->paths->filePath($folder, $fileName);

    if (null === $path) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_INVALID_PATH');
    }

    return $this->protector()->unprotect($path, $fileName);
  }

  public function isProtected(string $fileName): bool
  {
    return $this->protector()->isProtected($fileName);
  }

  // ------------------------------------------------------------ internals --

  private function uploader(): Uploader
  {
    return $this->uploader ??= new Uploader($this->config);
  }

  private function protector(): ImageProtector
  {
    return $this->protector ??= new ImageProtector($this->config);
  }

  /**
   * The Tree classes strip their base path off as a plain string prefix, so
   * they want it with its trailing slash.
   */
  private function treeBasePath(): string
  {
    return $this->paths->getRootPath() . '/';
  }

  /**
   * Root and system folders are structural : they may not be moved, renamed
   * or removed.
   */
  private function guardFolderIsEditable(string $folder): ?OperationResult
  {
    if ('' === $folder) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_ROOT_CANNOT_BE_CHANGED');
    }

    if (true === $this->config->isSystemFolder($folder)) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_ROOT_FOLDERS_CANNOT_BE_CHANGED');
    }

    return null;
  }

  private function rename(string $from, string $to): ?OperationResult
  {
    try {
      (new Filesystem())->rename($from, $to);
    } catch (\Throwable $e) {
      return OperationResult::fail('MEDIAMANAGER_ERROR_OPERATION_FAILED', ['%error%' => $e->getMessage()]);
    }

    return null;
  }

  private function isPathEmpty(string $path): bool
  {
    $finder = Finder::create()->in($path)->ignoreUnreadableDirs()->depth(0);

    return 0 === \iterator_count($finder);
  }

  /**
   * Immediate subfolder names, alphabetically.
   *
   * @return string[]
   */
  private function childFolderNames(string $path, bool $noUnderscore = false): array
  {
    $names = [];

    $finder = Finder::create()
      ->directories()
      ->in($path)
      ->ignoreUnreadableDirs()
      ->depth(0)
      ->sortByName();

    foreach ($finder as $dir) {
      $name = $dir->getFilename();

      if (true === $noUnderscore && \str_starts_with($name, '_')) {
        continue;
      }

      $names[] = $name;
    }

    return $names;
  }

  /**
   * Immediate file names, alphabetically.
   *
   * @return string[]
   */
  private function childFileNames(string $path, bool $noUnderscore = false, array $extensions = []): array
  {
    $names = [];

    $finder = Finder::create()
      ->files()
      ->in($path)
      ->ignoreUnreadableDirs()
      ->depth(0)
      ->notName(self::NOISE_FILES)
      ->sortByName();

    foreach ($finder as $file) {
      $name = $file->getFilename();

      if (true === $noUnderscore && \str_starts_with($name, '_')) {
        continue;
      }

      if ([] !== $extensions && false === \in_array(\strtolower($file->getExtension()), $extensions, true)) {
        continue;
      }

      $names[] = $name;
    }

    return $names;
  }

  /**
   * Every file below `$path`, recursively.
   *
   * @return iterable<\SplFileInfo>
   */
  private function allFiles(string $path): iterable
  {
    return Finder::create()
      ->files()
      ->in($path)
      ->ignoreUnreadableDirs()
      ->notName(self::NOISE_FILES)
      ->notPath(['__MACOSX']);
  }

  /**
   * @return array<array{value: string, name: string, level: int}>
   */
  private function folderChildren(string $folder): array
  {
    $folders = [];
    $path = $this->paths->folderPath($folder);

    if (null === $path || false === @\is_dir($path)) {
      return $folders;
    }

    foreach ($this->childFolderNames($path) as $name) {
      $value = ('' === $folder ? '' : $folder . '/') . $name;

      $folders[] = [
        'value' => $value,
        'name' => $name,
        'level' => \count(\explode('/', $value)),
      ];

      $folders = \array_merge($folders, $this->folderChildren($value));
    }

    return $folders;
  }

  /**
   * @return object[]
   */
  private function treeChildren(string $folder, string $activeFolder, int $level): array
  {
    $tree = [];
    $path = $this->paths->folderPath($folder);

    if (null === $path || false === @\is_dir($path)) {
      return $tree;
    }

    $activeParts = '' === $activeFolder ? [] : \explode('/', $activeFolder);

    foreach ($this->childFolderNames($path) as $name) {
      $value = ('' === $folder ? '' : $folder . '/') . $name;
      $parts = \explode('/', $value);

      $selected = $value === $activeFolder;
      $active = $selected;

      if (false === $active && \count($activeParts) >= $level) {
        // the branch is open when it prefixes the active folder
        $active = \array_slice($activeParts, 0, $level) === \array_slice($parts, 0, $level);
      }

      $tree[] = (object)[
        'value' => \implode('[-]', $parts),
        'readable' => $value,
        'text' => $name,
        'level' => $level,
        'active' => $active,
        'selected' => $selected,
        'children' => $this->treeChildren($value, $activeFolder, $level + 1),
      ];
    }

    return $tree;
  }

  private function selectorFolder(array &$list, string $folder, string $urlBase, ?callable $imageResolver, int $level): void
  {
    $path = $this->paths->folderPath($folder);

    if (null === $path || false === @\is_dir($path)) {
      return;
    }

    $images = $this->childFileNames($path, true, ['png', 'jpg', 'jpeg', 'gif']);

    if ([] !== $images) {
      $group = new \stdClass;
      $group->name = $folder;
      $group->level = $level;
      $group->files = [];

      foreach ($images as $image) {
        $relPath = ('' === $folder ? '' : $folder . '/') . $image;

        $resolved = null === $imageResolver ? ['thumb' => '', 'orientation' => ''] : $imageResolver($relPath);

        if (null === $resolved) {
          continue;
        }

        $fi = new \SplFileInfo($image);

        $file = new \stdClass;
        $file->value = $relPath;
        $file->text = $fi->getBasename('.' . $fi->getExtension());
        $file->valid = true;
        $file->thumb = (string)($resolved['thumb'] ?? '');
        $file->orientation = (string)($resolved['orientation'] ?? '');
        $file->url = $urlBase . $relPath;

        $group->files[] = $file;
      }

      $list[] = $group;
    }

    foreach ($this->childFolderNames($path, true) as $subfolder) {
      $this->selectorFolder(
        $list,
        ('' === $folder ? '' : $folder . '/') . $subfolder,
        $urlBase,
        $imageResolver,
        $level + 1,
      );
    }
  }

  /**
   * Regex the file mime must match, '' when everything passes.
   */
  private function mimeFilter(string $only): string
  {
    $mimes = match ($only) {
      'images' => ['image\/.+'],
      'videos' => ['video\/.+'],
      'audios' => ['audio\/.+'],
      'documents' => [
        'application\/pdf',
        'text\/plain',
        'application\/vnd\.(oasis|ms|openxmlformats).+',
        'application\/msword',
      ],
      default => [],
    };

    if ([] === $mimes) {
      return '';
    }

    return '/^(' . \implode(')|(', $mimes) . ')$/';
  }

  private function mimeOf(string $path): string
  {
    $mime = @\mime_content_type($path);

    return false === $mime ? '' : $mime;
  }
}
