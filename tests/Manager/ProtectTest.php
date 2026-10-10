<?php

namespace JDZ\MediaManager\Tests\Manager;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProtectTest extends TestCase
{
  use ManagerFixture;

  public function testProtectBacksTheOriginalUpAndStampsTheFile(): void
  {
    $before = \file_get_contents($this->rootPath('photos/a.jpg'));

    $result = $this->manager()->protectImage('photos', 'a.jpg');

    $this->assertTrue($result->isSuccess(), $result->key);
    $this->assertSame('MEDIAMANAGER_SUCCESS_PROTECT', $result->key);
    $this->assertFalse($result->get('updated'));

    $this->assertFileExists($this->protectPath('a.jpg'));
    $this->assertSame($before, \file_get_contents($this->protectPath('a.jpg')));
    $this->assertNotSame($before, \file_get_contents($this->rootPath('photos/a.jpg')));
  }

  public function testProtectingTwiceKeepsThePristineBackup(): void
  {
    $manager = $this->manager();
    $before = \file_get_contents($this->rootPath('photos/a.jpg'));

    $manager->protectImage('photos', 'a.jpg');
    $result = $manager->protectImage('photos', 'a.jpg');

    $this->assertTrue($result->isSuccess());
    $this->assertTrue($result->get('updated'));
    $this->assertSame($before, \file_get_contents($this->protectPath('a.jpg')));
  }

  public function testUnprotectRestoresTheOriginalExactly(): void
  {
    $manager = $this->manager();
    $before = \file_get_contents($this->rootPath('photos/a.jpg'));

    $manager->protectImage('photos', 'a.jpg');
    $result = $manager->unprotectImage('photos', 'a.jpg');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_SUCCESS_UNPROTECT', $result->key);
    $this->assertSame($before, \file_get_contents($this->rootPath('photos/a.jpg')));
    $this->assertFileDoesNotExist($this->protectPath('a.jpg'));
  }

  public function testIsProtectedTracksTheBackup(): void
  {
    $manager = $this->manager();

    $this->assertFalse($manager->isProtected('a.jpg'));

    $manager->protectImage('photos', 'a.jpg');
    $this->assertTrue($manager->isProtected('a.jpg'));

    $manager->unprotectImage('photos', 'a.jpg');
    $this->assertFalse($manager->isProtected('a.jpg'));
  }

  public function testProtectingANonImageFails(): void
  {
    $result = $this->manager()->protectImage('photos', 'notes.pdf');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_NOT_AN_IMAGE', $result->key);
    $this->assertFileDoesNotExist($this->protectPath('notes.pdf'));
  }

  public function testProtectWithoutAWatermarkFails(): void
  {
    $result = $this->manager(['watermarkPath' => ''])->protectImage('photos', 'a.jpg');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_OPERATION_FAILED', $result->key);
    $this->assertFileDoesNotExist($this->protectPath('a.jpg'));
  }

  public static function failureProvider(): array
  {
    return [
      'protect a missing file' => [
        static fn(self $test) => $test->manager()->protectImage('photos', 'nope.jpg'),
        'MEDIAMANAGER_ERROR_SOURCE_FILE_NOT_FOUND',
        [],
      ],
      'protect through traversal' => [
        static fn(self $test) => $test->manager()->protectImage('photos', '../logo.png'),
        'MEDIAMANAGER_ERROR_INVALID_PATH',
        [],
      ],
      'protect with a corrupt watermark' => [
        static function (self $test) {
          // keep the PNG signature and header, drop the pixel data
          \file_put_contents($test->watermarkPath, \substr(\file_get_contents($test->watermarkPath), 0, 40));

          return $test->manager()->protectImage('photos', 'a.jpg');
        },
        'MEDIAMANAGER_ERROR_OPERATION_FAILED',
        ['%error%' => 'Unable to open image {base}/watermark.png'],
      ],
      'unprotect a missing file' => [
        static fn(self $test) => $test->manager()->unprotectImage('photos', 'nope.jpg'),
        'MEDIAMANAGER_ERROR_SOURCE_FILE_NOT_FOUND',
        [],
      ],
      'unprotect a non-image' => [
        static fn(self $test) => $test->manager()->unprotectImage('photos', 'notes.pdf'),
        'MEDIAMANAGER_ERROR_NOT_AN_IMAGE',
        [],
      ],
      'unprotect through traversal' => [
        static fn(self $test) => $test->manager()->unprotectImage('photos', '../logo.png'),
        'MEDIAMANAGER_ERROR_INVALID_PATH',
        [],
      ],
      'unprotect without a backup' => [
        static fn(self $test) => $test->manager()->unprotectImage('photos', 'a.jpg'),
        'MEDIAMANAGER_ERROR_PROTECT_ORIGINAL_NOT_FOUND',
        [],
      ],
      'unprotect from an unreadable backup' => [
        static function (self $test) {
          // a folder where the backup file should be
          \mkdir($test->protectPath('a.jpg'));

          return $test->manager()->unprotectImage('photos', 'a.jpg');
        },
        'MEDIAMANAGER_ERROR_OPERATION_FAILED',
        ['%error%' => 'Failed to copy "{base}/protect/a.jpg" because file does not exist.'],
      ],
    ];
  }

  #[DataProvider('failureProvider')]
  public function testFailureAnswersItsKey(\Closure $operation, string $key, array $params): void
  {
    $result = $operation($this);

    $this->assertFalse($result->isSuccess());
    $this->assertSame($key, $result->key);
    $this->assertSame(
      \array_map(fn(string $value): string => \str_replace('{base}', $this->baseDir, $value), $params),
      $result->params,
    );
  }

  public function testKnownLimitationSameNamedFilesShareOneBackupSlot(): void
  {
    // NOT a contract : backups are filed flat, by file name (see ImageProtector),
    // so photos/a.jpg and a.jpg share protect/a.jpg. Flip this test once backups
    // get a path-qualified slot.
    $this->makeJpg($this->rootPath('a.jpg'), 60, 60);
    $manager = $this->manager();

    $manager->protectImage('photos', 'a.jpg');

    $this->assertTrue($manager->isProtected('a.jpg'), 'the untouched root a.jpg already reads as protected');

    $result = $manager->protectImage('', 'a.jpg');

    $this->assertTrue($result->get('updated'));
    $this->assertSame(
      [30, 20],
      \array_slice(\getimagesize($this->rootPath('a.jpg')), 0, 2),
      'the root a.jpg was re-stamped from photos/a.jpg\'s original',
    );
  }
}
