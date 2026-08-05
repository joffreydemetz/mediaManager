# JDZ MediaManager

Framework-agnostic media library management: browse, reshape and fill a media folder — plus upload validation, watermarking and folder trees for pickers.

## Installation

```bash
composer require jdz/mediamanager
```

## MediaManager

`JDZ\MediaManager\Manager\MediaManager` is the whole API. Point it at a folder and it owns everything under it.

```php
use JDZ\MediaManager\Manager\MediaManager;
use JDZ\MediaManager\ValueObject\MediaConfig;

$manager = new MediaManager(MediaConfig::fromArray([
  'rootPath'           => $publicPath . 'media/',
  'systemFolders'      => ['pages', 'documents'],  // structural, cannot be moved/renamed/deleted
  'extsImage'          => ['png', 'gif', 'jpg', 'jpeg'],
  'extsDocument'       => ['pdf'],
  'mimesImage'         => ['image/png', 'image/gif', 'image/jpeg'],
  'mimesDocument'      => ['application/pdf'],
  'maxWeightImage'     => 2,      // Mo, per file
  'maxWeightDocument'  => 10,     // Mo, per file
  'maxWeightFiles'     => 3000,   // Mo, whole library (0 = no quota)
  'maxNumFiles'        => 6000,   // whole library (0 = no quota)
  'maxPictureLongSide' => 1200,   // px, images above are downscaled on upload
  'protectPath'        => $publicPath . 'protect/',
  'watermarkPath'      => $publicPath . 'media/nepascopier.png',
]));
```

### Browsing

```php
$manager->getFilesystem('photos/2024', 'images', $thumbResolver);
// ['value' => 'photos/2024', 'name' => '2024', 'previous' => 'photos',
//  'folders' => [['value','name','previous','type','icon'], ...],
//  'files'   => [['value','name','ext','thumb'], ...]]

$manager->getBreadcrumbs('photos/2024');  // [['folder' => '', 'title' => 'Medias'], ['folder' => 'photos', ...], ...]
$manager->getFolderSelectList();          // [['value','name','level'], ...], flattened, 'Racine' first
$manager->getTree('photos/2024');         // ROOT node + nested children, active branch flagged
$manager->getSelectorList('', '../media/', $imageResolver);  // images grouped per folder, for a picker
```

Thumbnails are **not** this package's business. `getFilesystem()` and `getSelectorList()` take optional resolvers so the consumer plugs in its own image pipeline:

```php
$thumbResolver = fn (string $relPath, string $fileName): ?string => $myImager->thumb($relPath, 150);
$imageResolver = fn (string $relPath): ?array => ['thumb' => ..., 'orientation' => 'landscape'];  // null drops the file
```

### Reshaping

Every mutation returns an `OperationResult`.

```php
$result = $manager->moveFolder('photos/2024', 'archive');

if (false === $result->isSuccess()) {
  echo $translator->translate($result->key, $result->params);
} else {
  $newPath = $result->get('folder');
}
```

```php
$manager->createFolder('photos', '2025');
$manager->renameFolder('photos/2024', 'archives-2024');   // data: folder
$manager->moveFolder('photos/2024', 'archive');           // data: folder
$manager->deleteFolder('photos/2024');                    // data: parent — refuses non-empty
$manager->renameFile('photos', 'img.jpg', 'sunset');      // data: file — extension is preserved
$manager->moveFile('photos', 'img.jpg', 'archive');       // data: folder, file
$manager->deleteFile('photos', 'img.jpg');
$manager->resolveDownloadPath('photos', 'img.jpg');       // absolute path, or null
```

### Uploading

```php
$result = $manager->upload($request->files->get('fileUploadName'), 'photos', $force);

if (true === $result->isSuccess()) {
  echo $result->filename;   // slugged, collision-free, downscaled if needed
  echo $result->value();    // 'photos/mon-image.jpg'
}
```

Validates mime and weight against the config, slugs the name (camelCase split, transliterated, lowercased, dash-separated), resolves collisions with `-1`, `-2`, `-3` unless `$force`, then downscales oversized pictures in place (GD via Imagine, EXIF autorotate). A failed resize never fails the upload.

Library quotas are enforced up front — `$manager->getStats()` exposes the counters and the verdict:

```php
$stats = $manager->getStats();   // numFiles, weightFiles (Mo), maxNumFiles, maxWeightFiles, uploadable, errors
```

### Watermarking

```php
$manager->protectImage('photos', 'img.jpg');    // backs the original up, stamps the watermark over it
$manager->unprotectImage('photos', 'img.jpg');  // restores it, drops the backup
$manager->isProtected('img.jpg');
```

Backups live flat under `protectPath`, filed under the file's own name — two files sharing a basename in different folders share one backup slot.

## Paths and safety

Folder paths travel through the browser with their slashes swapped for `[-]`. `decodePath()` / `encodePath()` handle the codec; run every inbound folder value through `decodePath()`.

Every path is canonicalized and checked to be inside the root before anything touches the disk — `..` segments, absolute paths and directory parts in file names are refused with `MEDIAMANAGER_ERROR_INVALID_PATH`. Paths stay forward-slashed on every platform.

## Translation keys

The package never renders a message. It returns keys, which the consumer translates:

| | |
|---|---|
| **Success** | `MEDIAMANAGER_SUCCESS_DIRCREATE`, `_DIRRENAME`, `_DIRMOVE`, `_DIRDELETE`, `_FILRENAME`, `_FILMOVE`, `_FILDELETE` (`%filename%`), `_PROTECT`, `_UNPROTECT` |
| **Folders** | `MEDIAMANAGER_ERROR_ROOT_CANNOT_BE_CHANGED`, `_ROOT_FOLDERS_CANNOT_BE_CHANGED`, `_ENTER_FOLDER_NAME`, `_ENTER_FOLDER_NEW_NAME`, `_FOLDER_NAME_UNCHANGED`, `_FOLDER_NOT_EMPTY`, `_SOURCE_FOLDER_INVALID`, `_SOURCE_FOLDER_NOT_FOUND`, `_DESTINATION_FOLDER_NOT_FOUND`, `_DESTINATION_FOLDER_ALREADY_EXISTS`, `_CANNOT_MOVE_INTO_ITSELF` |
| **Files** | `MEDIAMANAGER_ERROR_NO_FILE_SPECIFIED`, `_ENTER_FILE_NEW_NAME`, `_FILE_NAME_UNCHANGED`, `_SOURCE_FILE_NOT_FOUND`, `_DESTINATION_FILE_ALREADY_EXISTS`, `_SAME_FILEPATH`, `_NOT_AN_IMAGE`, `_PROTECT_ORIGINAL_NOT_FOUND` |
| **Uploads** | `MEDIAMANAGER_ERROR_UPLOAD_FILE_TOO_BIG` (`%maxFileSize%`), `_UPLOAD_FILE_UNAUTH_EXT` (`%authExts%`), `_UPLOAD_TOO_MANY_FILES` (`%maxFileCount%`), `_UPLOAD_FILESYSTEM_FULL` (`%maxWeightFiles%`) |
| **Generic** | `MEDIAMANAGER_ERROR_INVALID_PATH`, `MEDIAMANAGER_ERROR_OPERATION_FAILED` (`%error%`) |

## Trees

`ImageTree` builds a nested folders/files structure; `DocumentTree` builds a flat list with per-type icons. Both skip entries whose basename starts with `_`, and accept ignore lists. Reachable directly, or through `getRedactorImageTree()` / `getRedactorDocumentTree()`.

```php
use JDZ\MediaManager\Tree\ImageTree;
use JDZ\MediaManager\Tree\DocumentTree;

$images = (new ImageTree())
  ->setBasePath($publicPath . 'media/')
  ->setBaseUrl('../media/')
  ->setIgnoreFolders(['template'])
  ->setIgnoreFiles(['share.jpg'])
  ->getTree();
// ['folders' => [['label','path','files' => [...]], ...], 'files' => [['url','id','title','ext'], ...]]

$documents = (new DocumentTree())
  ->setBasePath($publicPath . 'media/')
  ->setBaseUrl('../media/')
  ->getTree();
// [['id','title','text','icon','url','ext'], ...]
```

## Upgrading from 1.x

`JDZ\MediaManager\Upload` is gone — its work is done by `MediaManager::upload()`, which takes an explicit target folder, enforces library quotas and returns an `UploadResult` instead of throwing. `Tree/` is unchanged.

## Tests

```bash
composer test
```

## License

MIT — see [LICENSE](LICENSE).
