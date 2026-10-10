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

    $this->assertFileExists($this->protectPath('photos/a.jpg'));
    $this->assertSame($before, \file_get_contents($this->protectPath('photos/a.jpg')));
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
    $this->assertSame($before, \file_get_contents($this->protectPath('photos/a.jpg')));
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
    $this->assertFileDoesNotExist($this->protectPath('photos/a.jpg'));
  }

  public function testIsProtectedTracksTheBackup(): void
  {
    $manager = $this->manager();

    $this->assertFalse($manager->isProtected('photos', 'a.jpg'));

    $manager->protectImage('photos', 'a.jpg');
    $this->assertTrue($manager->isProtected('photos', 'a.jpg'));
    $this->assertFalse($manager->isProtected('', 'a.jpg'));

    $manager->unprotectImage('photos', 'a.jpg');
    $this->assertFalse($manager->isProtected('photos', 'a.jpg'));
  }

  public function testIsProtectedRefusesTraversal(): void
  {
    $manager = $this->manager();
    $manager->protectImage('photos', 'a.jpg');

    $this->assertFalse($manager->isProtected('photos/..', 'photos/a.jpg'));
    $this->assertFalse($manager->isProtected('..', 'watermark.png'));
    $this->assertFalse($manager->isProtected('photos', ''));
  }

  public function testIsProtectedIsFalseWithoutAProtectPath(): void
  {
    $this->assertFalse($this->manager(['protectPath' => ''])->isProtected('photos', 'a.jpg'));
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
    $this->assertFileDoesNotExist($this->protectPath('photos/a.jpg'));
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
      'protect without a protect folder' => [
        static fn(self $test) => $test->manager(['protectPath' => ''])->protectImage('photos', 'a.jpg'),
        'MEDIAMANAGER_ERROR_OPERATION_FAILED',
        ['%error%' => 'Protect folder not configured'],
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
          \mkdir($test->protectPath('photos/a.jpg'), 0777, true);

          return $test->manager()->unprotectImage('photos', 'a.jpg');
        },
        'MEDIAMANAGER_ERROR_OPERATION_FAILED',
        ['%error%' => 'Failed to copy "{base}/protect/photos/a.jpg" because file does not exist.'],
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

  public function testSameNamedFilesHaveTheirOwnBackupSlot(): void
  {
    $this->makeJpg($this->rootPath('a.jpg'), 60, 60);
    $rootOriginal = \file_get_contents($this->rootPath('a.jpg'));
    $manager = $this->manager();

    $manager->protectImage('photos', 'a.jpg');

    $this->assertFalse($manager->isProtected('', 'a.jpg'), 'the untouched root a.jpg is not protected');

    $result = $manager->protectImage('', 'a.jpg');

    $this->assertFalse($result->get('updated'));
    $this->assertSame($rootOriginal, \file_get_contents($this->protectPath('a.jpg')));
    $this->assertSame(
      [60, 60],
      \array_slice(\getimagesize($this->rootPath('a.jpg')), 0, 2),
      'the root a.jpg was stamped from its own original',
    );
    $this->assertFileExists($this->protectPath('photos/a.jpg'));
  }

  // -- backups follow their file --------------------------------------------

  public function testRenameFileMovesTheBackup(): void
  {
    $manager = $this->manager();
    $before = \file_get_contents($this->rootPath('photos/a.jpg'));
    $manager->protectImage('photos', 'a.jpg');

    $this->assertTrue($manager->renameFile('photos', 'a.jpg', 'b')->isSuccess());

    $this->assertFileDoesNotExist($this->protectPath('photos/a.jpg'));
    $this->assertTrue($manager->isProtected('photos', 'b.jpg'));
    $this->assertTrue($manager->unprotectImage('photos', 'b.jpg')->isSuccess());
    $this->assertSame($before, \file_get_contents($this->rootPath('photos/b.jpg')));
  }

  public function testMoveFileMovesTheBackup(): void
  {
    $manager = $this->manager();
    $manager->protectImage('photos', 'a.jpg');

    $this->assertTrue($manager->moveFile('photos', 'a.jpg', 'empty')->isSuccess());

    $this->assertFileDoesNotExist($this->protectPath('photos/a.jpg'));
    $this->assertFileExists($this->protectPath('empty/a.jpg'));
    $this->assertTrue($manager->isProtected('empty', 'a.jpg'));
  }

  public function testDeleteFileDropsTheBackup(): void
  {
    $manager = $this->manager();
    $manager->protectImage('photos', 'a.jpg');

    $this->assertTrue($manager->deleteFile('photos', 'a.jpg')->isSuccess());

    $this->assertFileDoesNotExist($this->protectPath('photos/a.jpg'));
  }

  public function testRenameFolderMovesItsBackups(): void
  {
    $manager = $this->manager();
    $manager->protectImage('photos', 'a.jpg');
    $manager->protectImage('photos/sub', 'deep.jpg');

    $this->assertTrue($manager->renameFolder('photos', 'images')->isSuccess());

    $this->assertDirectoryDoesNotExist($this->protectPath('photos'));
    $this->assertTrue($manager->isProtected('images', 'a.jpg'));
    $this->assertTrue($manager->isProtected('images/sub', 'deep.jpg'));
  }

  public function testMoveFolderMovesItsBackups(): void
  {
    $manager = $this->manager();
    $manager->protectImage('photos', 'a.jpg');

    $this->assertTrue($manager->moveFolder('photos', 'empty')->isSuccess());

    $this->assertDirectoryDoesNotExist($this->protectPath('photos'));
    $this->assertTrue($manager->isProtected('empty/photos', 'a.jpg'));
  }

  public function testDeleteFolderDropsItsStaleBackups(): void
  {
    $manager = $this->manager();
    $this->makeJpg($this->protectPath('empty/stale.jpg'), 5, 5);

    $this->assertTrue($manager->deleteFolder('empty')->isSuccess());

    $this->assertDirectoryDoesNotExist($this->protectPath('empty'));
  }

  public function testOperationsOnUnprotectedFilesLeaveTheBackupsAlone(): void
  {
    $manager = $this->manager();

    $this->assertTrue($manager->renameFile('photos', 'a.jpg', 'b')->isSuccess());
    $this->assertTrue($manager->moveFile('photos', 'b.jpg', 'empty')->isSuccess());
    $this->assertTrue($manager->renameFolder('photos', 'images')->isSuccess());
    $this->assertTrue($manager->deleteFile('empty', 'b.jpg')->isSuccess());

    $this->assertSame([], \glob($this->protectDir . '/*'), 'no backup folder was created');
  }

  public function testOperationsWorkWithoutAProtectPath(): void
  {
    $manager = $this->manager(['protectPath' => '']);

    $this->assertTrue($manager->renameFile('photos', 'a.jpg', 'b')->isSuccess());
    $this->assertTrue($manager->moveFolder('photos', 'empty')->isSuccess());
    $this->assertTrue($manager->deleteFile('empty/photos', 'b.jpg')->isSuccess());
  }
}
