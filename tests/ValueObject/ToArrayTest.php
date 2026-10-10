<?php

namespace JDZ\MediaManager\Tests\ValueObject;

use JDZ\MediaManager\ValueObject\FileInfo;
use JDZ\MediaManager\ValueObject\FilesystemStats;
use JDZ\MediaManager\ValueObject\FolderInfo;
use JDZ\MediaManager\ValueObject\ImageMetadata;
use JDZ\MediaManager\ValueObject\MediaConfig;
use JDZ\MediaManager\ValueObject\OperationResult;
use JDZ\MediaManager\ValueObject\UploadResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The array shapes consumers merge into their JSON answers and views.
 */
class ToArrayTest extends TestCase
{
  public static function shapeProvider(): array
  {
    return [
      'operation success' => [
        OperationResult::ok('MEDIAMANAGER_SUCCESS_DIRCREATE', [], ['folder' => 'photos/2025']),
        ['success' => true, 'key' => 'MEDIAMANAGER_SUCCESS_DIRCREATE', 'params' => [], 'data' => ['folder' => 'photos/2025']],
      ],
      'operation failure' => [
        OperationResult::fail('MEDIAMANAGER_ERROR_OPERATION_FAILED', ['%error%' => 'boom']),
        ['success' => false, 'key' => 'MEDIAMANAGER_ERROR_OPERATION_FAILED', 'params' => ['%error%' => 'boom'], 'data' => []],
      ],
      'upload at the root' => [
        UploadResult::ok('Mon Été.png', 'mon-ete', 'mon-ete.png', ''),
        ['originalName' => 'Mon Été.png', 'id' => 'mon-ete', 'filename' => 'mon-ete.png', 'value' => 'mon-ete.png'],
      ],
      'upload in a folder' => [
        UploadResult::ok('Deep.png', 'deep', 'deep.png', 'photos/sub'),
        ['originalName' => 'Deep.png', 'id' => 'deep', 'filename' => 'deep.png', 'value' => 'photos/sub/deep.png'],
      ],
      'upload failure keeps only the client name' => [
        UploadResult::fail('MEDIAMANAGER_ERROR_UPLOAD_FILE_TOO_BIG', ['%maxFileSize%' => 2], 'big.png'),
        ['originalName' => 'big.png', 'id' => '', 'filename' => '', 'value' => ''],
      ],
      'file info exports its folder as path' => [
        new FileInfo('a.jpg', 'a', 'jpg', 'photos', 'image/jpeg', 1234, 1700000000, 1700000001, true, 30, 20),
        [
          'name' => 'a.jpg',
          'namenoext' => 'a',
          'ext' => 'jpg',
          'path' => 'photos',
          'mime' => 'image/jpeg',
          'sizeBytes' => 1234,
          'createdAt' => 1700000000,
          'modifiedAt' => 1700000001,
          'isImage' => true,
          'width' => 30,
          'height' => 20,
        ],
      ],
      'folder info' => [
        new FolderInfo('sub', 'photos/sub', 0, 1, 1234),
        ['name' => 'sub', 'path' => 'photos/sub', 'nbFolders' => 0, 'nbFiles' => 1, 'sizeBytes' => 1234],
      ],
      'stats round the weight up to the next Mo' => [
        FilesystemStats::measure(9, 2_500_001, 6000, 3000),
        ['maxWeightFiles' => 3000, 'maxNumFiles' => 6000, 'numFiles' => 9, 'weightFiles' => 3, 'uploadable' => true],
      ],
      'stats leave their errors out' => [
        FilesystemStats::measure(10, 0, 10, 0),
        ['maxWeightFiles' => 0, 'maxNumFiles' => 10, 'numFiles' => 10, 'weightFiles' => 0, 'uploadable' => false],
      ],
      'image metadata' => [
        ImageMetadata::forImage('photos/b.png', '/srv/media/photos/b.png', 'image/png', 20, 30),
        [
          'filepath' => 'photos/b.png',
          'fullpath' => '/srv/media/photos/b.png',
          'mime' => 'image/png',
          'width' => 20,
          'height' => 30,
          'ratio' => 0.7,
          'orientation' => 'portrait',
        ],
      ],
      'non-image metadata carries no geometry' => [
        ImageMetadata::forOther('manual.pdf', '/srv/media/manual.pdf', 'application/pdf'),
        ['filepath' => 'manual.pdf', 'fullpath' => '/srv/media/manual.pdf', 'mime' => 'application/pdf'],
      ],
      'config canonicalises its paths and fills the defaults' => [
        MediaConfig::fromArray([
          'rootPath' => '/srv/site/./public/media/',
          'systemFolders' => ['pages' => 'pages'],
          'protectPath' => '/srv/site/protect/',
          'watermarkPath' => '\\srv\\site\\mark.png',
        ]),
        [
          'rootPath' => '/srv/site/public/media',
          'systemFolders' => ['pages'],
          'extsImage' => [],
          'extsDocument' => [],
          'mimesImage' => [],
          'mimesDocument' => [],
          'maxWeightImage' => 2,
          'maxWeightDocument' => 5,
          'maxWeightFiles' => 0,
          'maxNumFiles' => 0,
          'maxPictureLongSide' => 1800,
          'protectPath' => '/srv/site/protect',
          'watermarkPath' => '/srv/site/mark.png',
        ],
      ],
    ];
  }

  #[DataProvider('shapeProvider')]
  public function testToArray(object $subject, array $expected): void
  {
    $this->assertSame($expected, $subject->toArray());
  }
}
