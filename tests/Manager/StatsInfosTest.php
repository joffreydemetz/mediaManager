<?php

namespace JDZ\MediaManager\Tests\Manager;

use PHPUnit\Framework\TestCase;

class StatsInfosTest extends TestCase
{
  use ManagerFixture;

  // -- stats ----------------------------------------------------------------

  public function testStatsCountEveryFileRecursively(): void
  {
    $stats = $this->manager()->getStats();

    // logo, photo, manual, _draft, pages/home, photos/{a,b,notes}, photos/sub/deep
    $this->assertSame(9, $stats->numFiles);
    $this->assertTrue($stats->uploadable);
    $this->assertSame([], $stats->errors);
  }

  public function testStatsReportTheConfiguredCeilings(): void
  {
    $stats = $this->manager()->getStats();

    $this->assertSame(6000, $stats->maxNumFiles);
    $this->assertSame(3000, $stats->maxWeightFiles);
  }

  public function testStatsRefuseUploadsWhenTheFileCountIsReached(): void
  {
    $stats = $this->manager(['maxNumFiles' => 9])->getStats();

    $this->assertFalse($stats->uploadable);
    $this->assertSame([[
      'key' => 'MEDIAMANAGER_ERROR_UPLOAD_TOO_MANY_FILES',
      'params' => ['%maxFileCount%' => 9],
    ]], $stats->errors);
  }

  public function testStatsRefuseUploadsWhenTheWeightIsReached(): void
  {
    $stats = $this->manager(['maxWeightFiles' => 1])->getStats();

    $this->assertFalse($stats->uploadable);
    $this->assertSame('MEDIAMANAGER_ERROR_UPLOAD_FILESYSTEM_FULL', $stats->errors[0]['key']);
  }

  public function testZeroQuotasAreNotEnforced(): void
  {
    $stats = $this->manager(['maxNumFiles' => 0, 'maxWeightFiles' => 0])->getStats();

    $this->assertTrue($stats->uploadable);
    $this->assertSame([], $stats->errors);
  }

  public function testStatsAreCached(): void
  {
    $manager = $this->manager();
    $manager->getStats();

    $this->makePng($this->rootPath('empty/extra.png'), 5, 5);

    $this->assertSame(9, $manager->getStats()->numFiles);
  }

  public function testStatsAreRefreshedAfterAnUpload(): void
  {
    $manager = $this->manager();
    $this->assertSame(9, $manager->getStats()->numFiles);

    $manager->upload($this->uploadedPng('extra.png'), 'empty');

    $this->assertSame(10, $manager->getStats()->numFiles);
  }

  public function testStatsAreRefreshedAfterADelete(): void
  {
    $manager = $this->manager();
    $this->assertSame(9, $manager->getStats()->numFiles);

    $manager->deleteFile('photos', 'a.jpg');

    $this->assertSame(8, $manager->getStats()->numFiles);
  }

  public function testStatsCanBeRefreshedByHand(): void
  {
    $manager = $this->manager();
    $manager->getStats();

    $this->makePng($this->rootPath('empty/extra.png'), 5, 5);
    $manager->refreshStats();

    $this->assertSame(10, $manager->getStats()->numFiles);
  }

  // -- folder infos ---------------------------------------------------------

  public function testFolderInfosCountRecursively(): void
  {
    $infos = $this->manager()->getFolderInfos('photos');

    $this->assertSame('photos', $infos->name);
    $this->assertSame('photos', $infos->path);
    $this->assertSame(1, $infos->nbFolders);
    $this->assertSame(4, $infos->nbFiles);
    $this->assertGreaterThan(0, $infos->sizeBytes);
  }

  public function testRootFolderInfos(): void
  {
    $infos = $this->manager()->getFolderInfos('');

    $this->assertSame('', $infos->name);
    $this->assertSame('', $infos->path);
    $this->assertSame(4, $infos->nbFolders);
    $this->assertSame(9, $infos->nbFiles);
  }

  public function testEmptyFolderInfos(): void
  {
    $infos = $this->manager()->getFolderInfos('empty');

    $this->assertSame(0, $infos->nbFolders);
    $this->assertSame(0, $infos->nbFiles);
    $this->assertSame(0, $infos->sizeBytes);
    $this->assertSame(0, $infos->sizeMo());
  }

  public function testFolderInfosOfAMissingFolderIsNull(): void
  {
    $this->assertNull($this->manager()->getFolderInfos('nope'));
    $this->assertNull($this->manager()->getFolderInfos('../protect'));
  }

  // -- file infos -----------------------------------------------------------

  public function testFileInfosOfAnImage(): void
  {
    $infos = $this->manager()->getFileInfos('photos', 'a.jpg');

    $this->assertSame('a.jpg', $infos->name);
    $this->assertSame('a', $infos->namenoext);
    $this->assertSame('jpg', $infos->ext);
    $this->assertSame('photos', $infos->folder);
    $this->assertSame('image/jpeg', $infos->mime);
    $this->assertTrue($infos->isImage);
    $this->assertSame(30, $infos->width);
    $this->assertSame(20, $infos->height);
    $this->assertGreaterThan(0, $infos->sizeBytes);
    $this->assertIsInt($infos->modifiedAt);
    $this->assertSame('photos/a.jpg', $infos->value());
  }

  public function testFileInfosOfADocument(): void
  {
    $infos = $this->manager()->getFileInfos('photos', 'notes.pdf');

    $this->assertSame('application/pdf', $infos->mime);
    $this->assertFalse($infos->isImage);
    $this->assertNull($infos->width);
    $this->assertNull($infos->height);
  }

  public function testFileInfosOfAMissingFileIsNull(): void
  {
    $this->assertNull($this->manager()->getFileInfos('photos', 'nope.jpg'));
    $this->assertNull($this->manager()->getFileInfos('photos', '../logo.png'));
  }

  // -- image metadata -------------------------------------------------------

  public function testImageMetadataLandscape(): void
  {
    $meta = $this->manager()->getImageMetadata('photos/a.jpg');

    $this->assertTrue($meta->isImage);
    $this->assertSame(30, $meta->width);
    $this->assertSame(20, $meta->height);
    $this->assertSame(1.5, $meta->ratio);
    $this->assertSame('landscape', $meta->orientation);
    $this->assertSame('photos/a.jpg', $meta->path);
  }

  public function testImageMetadataPortrait(): void
  {
    $meta = $this->manager()->getImageMetadata('photos/b.png');

    $this->assertSame('portrait', $meta->orientation);
    $this->assertSame(0.7, $meta->ratio);
  }

  public function testImageMetadataSquare(): void
  {
    $meta = $this->manager()->getImageMetadata('photos/sub/deep.jpg');

    $this->assertSame('square', $meta->orientation);
    $this->assertSame(1.0, $meta->ratio);
  }

  public function testImageMetadataOfADocumentIsFlaggedNotAnImage(): void
  {
    $meta = $this->manager()->getImageMetadata('manual.pdf');

    $this->assertFalse($meta->isImage);
    $this->assertSame('application/pdf', $meta->mime);
    $this->assertArrayNotHasKey('width', $meta->toArray());
  }

  public function testImageMetadataRefusesTraversal(): void
  {
    $this->assertNull($this->manager()->getImageMetadata('../watermark.png'));
    $this->assertNull($this->manager()->getImageMetadata(''));
    $this->assertNull($this->manager()->getImageMetadata('nope.png'));
  }

  // -- dropzone -------------------------------------------------------------

  public function testDropzoneParams(): void
  {
    $manager = $this->manager();

    $this->assertSame([
      'acceptedFiles' => 'application/pdf,image/png,image/gif,image/jpeg,.pdf,.png,.gif,.jpg,.jpeg',
      'maxFilesize' => 10,
    ], $manager->getDropzoneParams());

    $this->assertSame([
      'acceptedFiles' => 'image/png,image/gif,image/jpeg,.png,.gif,.jpg,.jpeg',
      'maxFilesize' => 2,
    ], $manager->getDropzoneParams('image'));

    $this->assertSame([
      'acceptedFiles' => 'application/pdf,.pdf',
      'maxFilesize' => 10,
    ], $manager->getDropzoneParams('document'));
  }
}
