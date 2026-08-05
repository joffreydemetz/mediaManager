<?php

namespace JDZ\MediaManager\Tests\Manager;

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

  public function testProtectingAMissingFileFails(): void
  {
    $result = $this->manager()->protectImage('photos', 'nope.jpg');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_SOURCE_FILE_NOT_FOUND', $result->key);
  }

  public function testUnprotectingWithoutABackupFails(): void
  {
    $result = $this->manager()->unprotectImage('photos', 'a.jpg');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_PROTECT_ORIGINAL_NOT_FOUND', $result->key);
  }

  public function testProtectWithoutAWatermarkFails(): void
  {
    $result = $this->manager(['watermarkPath' => ''])->protectImage('photos', 'a.jpg');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_OPERATION_FAILED', $result->key);
    $this->assertFileDoesNotExist($this->protectPath('a.jpg'));
  }

  public function testProtectRefusesTraversal(): void
  {
    $result = $this->manager()->protectImage('photos', '../logo.png');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_INVALID_PATH', $result->key);
  }
}
