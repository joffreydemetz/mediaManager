<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JDZ\Image;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Filesystem\Path;

class Upload
{
  public string $filename = '';
  public string $originalName = '';
  public string $cleanFilename = '';
  public string $path = '';
  public ?string $error = null;

  public int $documentMaxWeight = 5;
  public array $documentAuthMimes = [
    'application/pdf',
    // 'application/msword',
    // 'application/vnd.ms-excel',
    // 'application/vnd.ms-powerpoint',
    // 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    // 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    // 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    // 'text/plain',
    // 'text/rtf',
  ];
  public array $documentAuthExts = [
    'pdf',
    // 'doc',
    // 'docx',
    // 'xls',
    // 'xlsx',
    // 'ppt',
    // 'pptx',
    // 'txt',
    // 'rtf',
  ];

  public int $imageMaxWeight = 2;
  public array $imageAuthMimes = [
    'image/png',
    'image/gif',
    'image/jpeg',
  ];
  public array $imageAuthExts = [
    'png',
    'gif',
    'jpg',
    'jpeg',
  ];
  public int $maxPictureLongSide = 1800;
  public bool $overwrite = false;

  public int $maxWeight = 5;
  public array $authMimes = [
    'image/png',
    'image/gif',
    'image/jpeg',
    'application/pdf',
    // 'application/msword',
    // 'application/vnd.ms-excel',
    // 'application/vnd.ms-powerpoint',
    // 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    // 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    // 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    // 'text/plain',
    // 'text/rtf',
  ];
  public array $authExts = [
    'png',
    'gif',
    'jpg',
    'jpeg',
    'pdf',
    // 'doc',
    // 'docx',
    // 'xls',
    // 'xlsx',
    // 'ppt',
    // 'pptx',
    // 'txt',
    // 'rtf',
  ];

  public function checkStorageTotalWeight() {}

  protected function validMimes(): array
  {
    $validMimes = [];
    foreach ($this->authMimes as $mime) {
      $validMimes[] = str_replace('/', '\/', $mime);
    }
    return $validMimes;
  }

  public function upload(UploadedFile $file)
  {
    $this->error = null;

    $this->originalName = $file->getClientOriginalName();

    if ($this->maxWeight * 1000000 < $file->getSize()) {
      throw new \Exception('File is too big : exceeds (' . $this->maxWeight . ')');
    }

    $mime = $file->getClientMimeType();

    if (!\preg_match("/^(" . implode(')|(', $this->validMimes()) . ")$/i", $mime)) {
      throw new \Exception('Uauthorized Mime ' . $mime . ' - Not in auth list (' . implode(', ', $this->validMimes()) . ')');
    }

    if (\in_array($mime, $this->documentAuthMimes)) {
      if ($this->documentMaxWeight * 1000000 < $file->getSize()) {
        throw new \Exception('Document is too big : exceeds (' . $this->documentMaxWeight . ')');
      }
    }

    $fi = new \SplFileInfo($this->originalName);
    $ext = $fi->getExtension();
    $cleanFilename = $this->cleanFilename($fi->getBasename('.' . $ext));

    $ext = strtolower($ext);

    if ('jpeg' === $ext) {
      $ext = 'jpg';
    }

    if (\file_exists($this->path . $cleanFilename . '.' . $ext)) {
      if (false === $this->overwrite) {
        $cleanFilename = $this->guessAvailableFilename($cleanFilename, $ext, 5);
      }
    }

    $filename = $cleanFilename . '.' . $ext;

    $this->cleanFilename = $cleanFilename;

    $oMimeType = $file->getClientMimeType();
    $oSize = $file->getSize();

    $file->move($this->path, $filename, $this->overwrite);

    if (!\file_exists($this->path . $filename)) {
      throw new \Exception('File was not found where it should have been uploaded..');
    }

    $this->filename = $filename;

    if (preg_match("/^image\/(png|gif|jpeg)$/", $oMimeType)) {
      $imgSourcePath = Path::normalize($this->path . $this->filename);

      // @chmod($imgSourcePath, 755);    

      list($width, $height) = \getimagesize($imgSourcePath);
      // throw new \Exception($newWidth.'/'.$newHeight);

      if (
        $this->imageMaxWeight * 1000000 < $oSize
        || $width > $this->maxPictureLongSide
        || $height > $this->maxPictureLongSide
      ) {
        list($newWidth, $newHeight) = $this->scaledImageSizes($width, $height, $this->maxPictureLongSide, $this->maxPictureLongSide);

        try {
          $imagine = new \Imagine\Gd\Imagine();

          $imagine->setMetadataReader(new \Imagine\Image\Metadata\ExifMetadataReader());
          $img = $imagine->open($imgSourcePath);

          $img->resize(new \Imagine\Image\Box($newWidth, $newHeight));

          $filter = new \Imagine\Filter\Basic\Autorotate();
          $filter->apply($img);

          $img
            ->save($imgSourcePath, [
              'jpeg_quality' => 100,
              'png_compression_level' => 9,
              'resolution-units' => \Imagine\Image\ImageInterface::RESOLUTION_PIXELSPERINCH,
              'resolution-x' => 96,
              'resolution-y' => 96,
            ]);
        } catch (\Exception $e) {
        }
      }
    }
  }

  protected function cleanFilename(string $filename): string
  {
    $slugger = new AsciiSlugger();
    return $slugger->slug($filename);
  }

  protected function guessAvailableFilename(string $baseFilename, string $ext, int $maxTries = 5): string
  {
    for ($i = 1; $i < $maxTries; $i++) {
      $testFilename = $baseFilename . '-' . $i . '.' . $ext;

      if (false === @file_exists($this->path . $testFilename)) {
        return $baseFilename . '-' . $i;
      }
    }

    throw new \Exception('File exists .. maxTries reach to increment the file name');
  }

  private function scaledImageSizes($x, $y, $cx, $cy): array
  {
    $nx = $x;
    $ny = $y;

    // If image is generally smaller, don't even bother
    if ($x >= $cx || $y >= $cx) {
      // Work out ratios
      if ($x > 0) {
        $rx = $cx / $x;
      }

      if ($y > 0) {
        $ry = $cy / $y;
      }

      // Use the lowest ratio, to ensure we don't go over the wanted image size
      if ($rx > $ry) {
        $r = $ry;
      } else {
        $r = $rx;
      }

      // Calculate the new size based on the chosen ratio
      $nx = intval($x * $r);
      $ny = intval($y * $r);
    }

    return [$nx, $ny];
  }
}
