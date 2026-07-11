# JDZ MediaManager

Framework-agnostic media utilities: file/image upload with automatic resize, and media-folder trees for pickers (images / documents).

## Installation

```bash
composer require jdz/mediamanager
```

## Upload

`JDZ\MediaManager\Upload` validates an uploaded file (mime, extension, weight), slugs the filename, moves it into place and downscales large images in-place (GD via Imagine, EXIF autorotate).

```php
use JDZ\MediaManager\Upload;

$upload = new Upload();
$upload->path = '/path/to/public/media/photos/';
$upload->overwrite = false;
$upload->maxPictureLongSide = 1800;

$upload->upload($request->files->get('file')); // Symfony UploadedFile
echo $upload->filename; // slugged, unique, resized if needed
```

Throws `\Exception` on unauthorized mime, oversized file or move failure.

## Trees

`ImageTree` builds a nested folders/files structure; `DocumentTree` builds a flat list with per-type icons. Both skip entries whose basename starts with `_`, and accept ignore lists.

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

Paths are normalized to forward slashes on every platform (`Symfony\Filesystem\Path::normalize`).

## Tests

```bash
composer test
```

## License

MIT — see [LICENSE](LICENSE).
