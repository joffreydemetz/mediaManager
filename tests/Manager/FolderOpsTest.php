<?php

namespace JDZ\MediaManager\Tests\Manager;

use PHPUnit\Framework\TestCase;

class FolderOpsTest extends TestCase
{
  use ManagerFixture;

  // -- create ---------------------------------------------------------------

  public function testCreateAtTheRoot(): void
  {
    $result = $this->manager()->createFolder('', 'nouveau');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_SUCCESS_DIRCREATE', $result->key);
    $this->assertSame('nouveau', $result->get('folder'));
    $this->assertDirectoryExists($this->rootPath('nouveau'));
  }

  public function testCreateInsideAFolder(): void
  {
    $result = $this->manager()->createFolder('photos', '2025');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('photos/2025', $result->get('folder'));
    $this->assertDirectoryExists($this->rootPath('photos/2025'));
  }

  public function testCreateWithoutANameFails(): void
  {
    $result = $this->manager()->createFolder('', '  ');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_ENTER_FOLDER_NAME', $result->key);
  }

  public function testCreateOverAnExistingFolderFails(): void
  {
    $result = $this->manager()->createFolder('', 'photos');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_DESTINATION_FOLDER_ALREADY_EXISTS', $result->key);
  }

  public function testCreateRefusesToEscape(): void
  {
    $result = $this->manager()->createFolder('..', 'evil');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_INVALID_PATH', $result->key);
    $this->assertDirectoryDoesNotExist($this->baseDir . '/evil');
  }

  // -- rename ---------------------------------------------------------------

  public function testRenameUsesTheSubmittedName(): void
  {
    $result = $this->manager()->renameFolder('photos', 'images');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_SUCCESS_DIRRENAME', $result->key);
    $this->assertSame('images', $result->get('folder'));
    $this->assertDirectoryExists($this->rootPath('images/sub'));
    $this->assertDirectoryDoesNotExist($this->rootPath('photos'));
  }

  public function testRenameKeepsTheParent(): void
  {
    $result = $this->manager()->renameFolder('photos/sub', 'deeper');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('photos/deeper', $result->get('folder'));
    $this->assertFileExists($this->rootPath('photos/deeper/deep.jpg'));
  }

  public function testRenameDecodesTheWireFormat(): void
  {
    $result = $this->manager()->renameFolder('photos', 'mes%20images');

    $this->assertTrue($result->isSuccess());
    $this->assertDirectoryExists($this->rootPath('mes images'));
  }

  public function testRenameTheRootFails(): void
  {
    $result = $this->manager()->renameFolder('', 'whatever');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_ROOT_CANNOT_BE_CHANGED', $result->key);
  }

  public function testRenameASystemFolderFails(): void
  {
    $result = $this->manager()->renameFolder('pages', 'whatever');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_ROOT_FOLDERS_CANNOT_BE_CHANGED', $result->key);
    $this->assertDirectoryExists($this->rootPath('pages'));
  }

  public function testSystemFolderListDrivesTheGuard(): void
  {
    // 'photos' is not a system folder here, 'pages' no longer is
    $manager = $this->manager(['systemFolders' => ['photos']]);

    $this->assertFalse($manager->renameFolder('photos', 'x')->isSuccess());
    $this->assertTrue($manager->renameFolder('pages', 'x')->isSuccess());
  }

  public function testRenameWithoutANameFails(): void
  {
    $result = $this->manager()->renameFolder('photos', '');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_ENTER_FOLDER_NEW_NAME', $result->key);
  }

  public function testRenameToTheSameNameFails(): void
  {
    $result = $this->manager()->renameFolder('photos', 'photos');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_FOLDER_NAME_UNCHANGED', $result->key);
  }

  public function testRenameOntoAnExistingFolderFails(): void
  {
    $result = $this->manager()->renameFolder('photos', 'empty');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_DESTINATION_FOLDER_ALREADY_EXISTS', $result->key);
  }

  public function testRenameOfAMissingFolderFails(): void
  {
    $result = $this->manager()->renameFolder('nope', 'whatever');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_SOURCE_FOLDER_INVALID', $result->key);
  }

  // -- move -----------------------------------------------------------------

  public function testMoveUsesTheSubmittedDestination(): void
  {
    $result = $this->manager()->moveFolder('photos', 'empty');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_SUCCESS_DIRMOVE', $result->key);
    $this->assertSame('empty/photos', $result->get('folder'));
    $this->assertFileExists($this->rootPath('empty/photos/a.jpg'));
    $this->assertDirectoryDoesNotExist($this->rootPath('photos'));
  }

  public function testMoveDecodesTheWireFormat(): void
  {
    $this->manager()->createFolder('empty', 'deep');

    $result = $this->manager()->moveFolder('photos', 'empty[-]deep');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('empty/deep/photos', $result->get('folder'));
    $this->assertFileExists($this->rootPath('empty/deep/photos/a.jpg'));
  }

  public function testMoveBackToTheRoot(): void
  {
    $result = $this->manager()->moveFolder('photos/sub', '');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('sub', $result->get('folder'));
    $this->assertFileExists($this->rootPath('sub/deep.jpg'));
  }

  public function testMoveIntoItselfFails(): void
  {
    $result = $this->manager()->moveFolder('photos', 'photos');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_CANNOT_MOVE_INTO_ITSELF', $result->key);
    $this->assertDirectoryExists($this->rootPath('photos'));
  }

  public function testMoveIntoItsOwnChildFails(): void
  {
    $result = $this->manager()->moveFolder('photos', 'photos/sub');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_CANNOT_MOVE_INTO_ITSELF', $result->key);
    $this->assertFileExists($this->rootPath('photos/sub/deep.jpg'));
  }

  public function testMoveWhereItAlreadyIsFails(): void
  {
    $result = $this->manager()->moveFolder('photos/sub', 'photos');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_SAME_FILEPATH', $result->key);
  }

  public function testMoveIntoAMissingFolderFails(): void
  {
    $result = $this->manager()->moveFolder('photos', 'nope');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_DESTINATION_FOLDER_NOT_FOUND', $result->key);
  }

  public function testMoveOntoAnExistingFolderFails(): void
  {
    $this->manager()->createFolder('empty', 'photos');

    $result = $this->manager()->moveFolder('photos', 'empty');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_DESTINATION_FOLDER_ALREADY_EXISTS', $result->key);
  }

  public function testMoveASystemFolderFails(): void
  {
    $result = $this->manager()->moveFolder('pages', 'empty');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_ROOT_FOLDERS_CANNOT_BE_CHANGED', $result->key);
  }

  public function testMoveRefusesToEscape(): void
  {
    $result = $this->manager()->moveFolder('photos', '..');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_INVALID_PATH', $result->key);
    $this->assertDirectoryExists($this->rootPath('photos'));
  }

  // -- delete ---------------------------------------------------------------

  public function testDeleteAnEmptyFolder(): void
  {
    $result = $this->manager()->deleteFolder('empty');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_SUCCESS_DIRDELETE', $result->key);
    $this->assertSame('', $result->get('parent'));
    $this->assertDirectoryDoesNotExist($this->rootPath('empty'));
  }

  public function testDeleteReportsTheParent(): void
  {
    $this->manager()->createFolder('photos/sub', 'gone');

    $result = $this->manager()->deleteFolder('photos/sub/gone');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('photos/sub', $result->get('parent'));
  }

  public function testDeleteANonEmptyFolderFails(): void
  {
    $result = $this->manager()->deleteFolder('photos');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_FOLDER_NOT_EMPTY', $result->key);
    $this->assertDirectoryExists($this->rootPath('photos'));
  }

  public function testDeleteTheRootFails(): void
  {
    $result = $this->manager()->deleteFolder('');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_ROOT_CANNOT_BE_CHANGED', $result->key);
    $this->assertDirectoryExists($this->rootPath());
  }

  public function testDeleteASystemFolderFails(): void
  {
    $result = $this->manager()->deleteFolder('pages');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_ROOT_FOLDERS_CANNOT_BE_CHANGED', $result->key);
  }

  public function testDeleteAMissingFolderFails(): void
  {
    $result = $this->manager()->deleteFolder('nope');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_SOURCE_FOLDER_NOT_FOUND', $result->key);
  }

  public function testDeleteRefusesToEscape(): void
  {
    $result = $this->manager()->deleteFolder('../protect');

    $this->assertFalse($result->isSuccess());
    $this->assertDirectoryExists($this->protectPath(''));
  }
}
