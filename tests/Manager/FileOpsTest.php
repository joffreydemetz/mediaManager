<?php

namespace JDZ\MediaManager\Tests\Manager;

use PHPUnit\Framework\TestCase;

class FileOpsTest extends TestCase
{
  use ManagerFixture;

  // -- rename ---------------------------------------------------------------

  public function testRenameKeepsTheExtension(): void
  {
    $result = $this->manager()->renameFile('photos', 'a.jpg', 'coucher-de-soleil');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_SUCCESS_FILRENAME', $result->key);
    $this->assertSame('coucher-de-soleil.jpg', $result->get('file'));
    $this->assertFileExists($this->rootPath('photos/coucher-de-soleil.jpg'));
    $this->assertFileDoesNotExist($this->rootPath('photos/a.jpg'));
  }

  public function testRenameAtTheRoot(): void
  {
    $result = $this->manager()->renameFile('', 'logo.png', 'marque');

    $this->assertTrue($result->isSuccess());
    $this->assertFileExists($this->rootPath('marque.png'));
  }

  public function testRenameWithoutAFileFails(): void
  {
    $result = $this->manager()->renameFile('photos', '', 'whatever');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_NO_FILE_SPECIFIED', $result->key);
  }

  public function testRenameWithoutANewNameFails(): void
  {
    $result = $this->manager()->renameFile('photos', 'a.jpg', '  ');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_ENTER_FILE_NEW_NAME', $result->key);
  }

  public function testRenameToTheSameNameFails(): void
  {
    $result = $this->manager()->renameFile('photos', 'a.jpg', 'a');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_FILE_NAME_UNCHANGED', $result->key);
    $this->assertFileExists($this->rootPath('photos/a.jpg'));
  }

  public function testRenameOntoAnExistingFileFails(): void
  {
    $this->makeJpg($this->rootPath('photos/pris.jpg'), 10, 10);

    $result = $this->manager()->renameFile('photos', 'a.jpg', 'pris');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_DESTINATION_FILE_ALREADY_EXISTS', $result->key);
    $this->assertFileExists($this->rootPath('photos/a.jpg'));
  }

  public function testRenameOnlyCollidesOnTheFullName(): void
  {
    // b.png exists ; a.jpg -> b.jpg is a different file and must go through
    $result = $this->manager()->renameFile('photos', 'a.jpg', 'b');

    $this->assertTrue($result->isSuccess());
    $this->assertFileExists($this->rootPath('photos/b.jpg'));
    $this->assertFileExists($this->rootPath('photos/b.png'));
  }

  public function testRenameOfAMissingFileFails(): void
  {
    $result = $this->manager()->renameFile('photos', 'nope.jpg', 'whatever');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_SOURCE_FILE_NOT_FOUND', $result->key);
  }

  public function testRenameRefusesTraversalInEitherName(): void
  {
    $manager = $this->manager();

    $this->assertSame('MEDIAMANAGER_ERROR_INVALID_PATH', $manager->renameFile('photos', '../logo.png', 'x')->key);
    $this->assertSame('MEDIAMANAGER_ERROR_INVALID_PATH', $manager->renameFile('photos', 'a.jpg', '../evil')->key);
    $this->assertFileExists($this->rootPath('logo.png'));
  }

  // -- move -----------------------------------------------------------------

  public function testMove(): void
  {
    $result = $this->manager()->moveFile('photos', 'a.jpg', 'empty');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_SUCCESS_FILMOVE', $result->key);
    $this->assertSame('empty', $result->get('folder'));
    $this->assertSame('a.jpg', $result->get('file'));
    $this->assertFileExists($this->rootPath('empty/a.jpg'));
    $this->assertFileDoesNotExist($this->rootPath('photos/a.jpg'));
  }

  public function testMoveDecodesTheWireFormat(): void
  {
    $result = $this->manager()->moveFile('', 'logo.png', 'photos[-]sub');

    $this->assertTrue($result->isSuccess());
    $this->assertFileExists($this->rootPath('photos/sub/logo.png'));
  }

  public function testMoveToTheRoot(): void
  {
    $result = $this->manager()->moveFile('photos', 'a.jpg', '');

    $this->assertTrue($result->isSuccess());
    $this->assertFileExists($this->rootPath('a.jpg'));
  }

  public function testMoveToTheSameFolderFails(): void
  {
    $result = $this->manager()->moveFile('photos', 'a.jpg', 'photos');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_SAME_FILEPATH', $result->key);
  }

  public function testMoveIntoAMissingFolderFails(): void
  {
    $result = $this->manager()->moveFile('photos', 'a.jpg', 'nope');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_DESTINATION_FOLDER_NOT_FOUND', $result->key);
  }

  public function testMoveOntoAnExistingFileFails(): void
  {
    $this->manager()->moveFile('photos', 'a.jpg', 'empty');
    $this->manager()->createFolder('', 'other');
    (new \Symfony\Component\Filesystem\Filesystem())->dumpFile($this->rootPath('other/a.jpg'), 'x');

    $result = $this->manager()->moveFile('other', 'a.jpg', 'empty');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_DESTINATION_FILE_ALREADY_EXISTS', $result->key);
  }

  public function testMoveOfAMissingFileFails(): void
  {
    $result = $this->manager()->moveFile('photos', 'nope.jpg', 'empty');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_SOURCE_FILE_NOT_FOUND', $result->key);
  }

  public function testMoveRefusesToEscape(): void
  {
    $result = $this->manager()->moveFile('photos', 'a.jpg', '../protect');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_INVALID_PATH', $result->key);
    $this->assertFileExists($this->rootPath('photos/a.jpg'));
  }

  // -- delete ---------------------------------------------------------------

  public function testDelete(): void
  {
    $result = $this->manager()->deleteFile('photos', 'a.jpg');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_SUCCESS_FILDELETE', $result->key);
    $this->assertSame(['%filename%' => 'a.jpg'], $result->params);
    $this->assertFileDoesNotExist($this->rootPath('photos/a.jpg'));
  }

  public function testDeleteWithoutAFileFails(): void
  {
    $result = $this->manager()->deleteFile('photos', '');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_NO_FILE_SPECIFIED', $result->key);
  }

  public function testDeleteOfAMissingFileFails(): void
  {
    $result = $this->manager()->deleteFile('photos', 'nope.jpg');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_SOURCE_FILE_NOT_FOUND', $result->key);
  }

  public function testDeleteRefusesToEscape(): void
  {
    $result = $this->manager()->deleteFile('photos', '../../watermark.png');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_INVALID_PATH', $result->key);
    $this->assertFileExists($this->baseDir . '/watermark.png');
  }

  // -- download -------------------------------------------------------------

  public function testResolveDownloadPath(): void
  {
    $this->assertSame(
      $this->rootPath('photos/a.jpg'),
      $this->manager()->resolveDownloadPath('photos', 'a.jpg'),
    );
  }

  public function testResolveDownloadPathRefusesTraversal(): void
  {
    $manager = $this->manager();

    $this->assertNull($manager->resolveDownloadPath('', '../watermark.png'));
    $this->assertNull($manager->resolveDownloadPath('..', 'watermark.png'));
    $this->assertNull($manager->resolveDownloadPath('photos', 'sub/deep.jpg'));
  }

  public function testResolveDownloadPathOfAMissingFileIsNull(): void
  {
    $this->assertNull($this->manager()->resolveDownloadPath('photos', 'nope.jpg'));
  }

  public function testResolveDownloadPathOfAFolderIsNull(): void
  {
    $this->assertNull($this->manager()->resolveDownloadPath('', 'photos'));
  }
}
