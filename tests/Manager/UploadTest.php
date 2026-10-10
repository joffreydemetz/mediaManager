<?php

namespace JDZ\MediaManager\Tests\Manager;

use JDZ\MediaManager\Manager\Uploader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class UploadTest extends TestCase
{
  use ManagerFixture;

  public function testUploadLandsInTheGivenFolder(): void
  {
    $result = $this->manager()->upload($this->uploadedPng('Photo.png'), 'photos');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('photo.png', $result->filename);
    $this->assertSame('photos/photo.png', $result->value());
    $this->assertFileExists($this->rootPath('photos/photo.png'));
  }

  public function testUploadLandsAtTheRootWhenNoFolderIsGiven(): void
  {
    $result = $this->manager()->upload($this->uploadedPng('Photo.png'), '');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('photo.png', $result->value());
    $this->assertFileExists($this->rootPath('photo.png'));
  }

  public function testUploadDecodesTheWireFolder(): void
  {
    $result = $this->manager()->upload($this->uploadedPng('deep.png'), 'photos[-]sub');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('photos/sub/deep.png', $result->value());
    $this->assertFileExists($this->rootPath('photos/sub/deep.png'));
  }

  public function testUploadRefusesToEscape(): void
  {
    $result = $this->manager()->upload($this->uploadedPng('evil.png'), '../protect');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_INVALID_PATH', $result->key);
    $this->assertFileDoesNotExist($this->protectPath('evil.png'));
  }

  public function testUploadIntoAMissingFolderFails(): void
  {
    $result = $this->manager()->upload($this->uploadedPng('x.png'), 'nope');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_INVALID_PATH', $result->key);
  }

  public function testResultCarriesTheOriginalName(): void
  {
    $result = $this->manager()->upload($this->uploadedPng('Mon Été 2024.png'), 'photos');

    $this->assertSame('Mon Été 2024.png', $result->originalName);
    $this->assertSame('mon-ete-2024', $result->id);
    $this->assertSame('mon-ete-2024.png', $result->filename);
  }

  // -- naming ---------------------------------------------------------------

  public static function nameProvider(): array
  {
    return [
      'accents dropped' => ['Château.png', 'chateau.png'],
      'spaces to dashes' => ['mon image.png', 'mon-image.png'],
      'camelCase split' => ['MonImage.png', 'mon-image.png'],
      'underscores to dashes' => ['mon_image.png', 'mon-image.png'],
      'runs collapsed' => ['mon   --  image.png', 'mon-image.png'],
      'punctuation stripped' => ["l'été, c'est bien!.png", 'l-ete-c-est-bien.png'],
      'uppercase extension' => ['IMAGE.PNG', 'image.png'],
      'unnamed falls back' => ['???.png', 'fichier.png'],
    ];
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('nameProvider')]
  public function testFilenameIsSlugged(string $originalName, string $expected): void
  {
    $result = $this->manager()->upload($this->uploadedPng($originalName), 'empty');

    $this->assertTrue($result->isSuccess(), $result->key);
    $this->assertSame($expected, $result->filename);
  }

  public function testJpegBecomesJpg(): void
  {
    $result = $this->manager()->upload($this->uploadedJpg('vacances.jpeg'), 'empty');

    $this->assertSame('vacances.jpg', $result->filename);
  }

  // -- collisions -----------------------------------------------------------

  public function testCollisionsAreSuffixed(): void
  {
    $manager = $this->manager();

    $this->assertSame('doublon.png', $manager->upload($this->uploadedPng('doublon.png'), 'empty')->filename);
    $this->assertSame('doublon-1.png', $manager->upload($this->uploadedPng('doublon.png'), 'empty')->filename);
    $this->assertSame('doublon-2.png', $manager->upload($this->uploadedPng('doublon.png'), 'empty')->filename);
    $this->assertSame('doublon-3.png', $manager->upload($this->uploadedPng('doublon.png'), 'empty')->filename);
  }

  public function testCollisionsRunOutAfterThreeSuffixes(): void
  {
    $manager = $this->manager();

    for ($i = 0; $i < 4; $i++) {
      $manager->upload($this->uploadedPng('doublon.png'), 'empty');
    }

    $result = $manager->upload($this->uploadedPng('doublon.png'), 'empty');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_DESTINATION_FILE_ALREADY_EXISTS', $result->key);
  }

  public function testForcedUploadOverwrites(): void
  {
    $manager = $this->manager();
    $manager->upload($this->uploadedPng('doublon.png', 20, 20), 'empty');

    $result = $manager->upload($this->uploadedPng('doublon.png', 45, 35), 'empty', true);

    $this->assertTrue($result->isSuccess());
    $this->assertSame('doublon.png', $result->filename);
    $this->assertSame([45, 35], \array_slice(\getimagesize($this->rootPath('empty/doublon.png')), 0, 2));
  }

  // -- validation -----------------------------------------------------------

  public function testUnauthorisedMimeIsRefused(): void
  {
    $result = $this->manager()->upload($this->uploadedRaw('script.exe', 'application/x-msdownload'), 'empty');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_UPLOAD_FILE_UNAUTH_EXT', $result->key);
    $this->assertSame(['%authExts%' => 'pdf, png, gif, jpg, jpeg'], $result->params);
    $this->assertFileDoesNotExist($this->rootPath('empty/script.exe'));
  }

  public function testOversizedFileIsRefused(): void
  {
    $manager = $this->manager(['maxWeightImage' => 1, 'maxWeightDocument' => 1]);
    $file = $this->uploadedRaw('gros.png', 'image/png', 1_200_000);

    $result = $manager->upload($file, 'empty');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_UPLOAD_FILE_TOO_BIG', $result->key);
    $this->assertSame(['%maxFileSize%' => 1], $result->params);
  }

  public function testOversizedDocumentIsRefusedOnItsOwnCeiling(): void
  {
    // documents cap at 1 Mo while the global ceiling sits at 5
    $manager = $this->manager(['maxWeightDocument' => 1, 'maxWeightImage' => 5]);
    $file = $this->uploadedRaw('gros.pdf', 'application/pdf', 1_200_000);

    $result = $manager->upload($file, 'empty');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_UPLOAD_FILE_TOO_BIG', $result->key);
    $this->assertSame(['%maxFileSize%' => 1], $result->params);
  }

  // -- quotas ---------------------------------------------------------------

  public function testUploadIsRefusedWhenTheLibraryIsFull(): void
  {
    $result = $this->manager(['maxNumFiles' => 2])->upload($this->uploadedPng('x.png'), 'empty');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_UPLOAD_TOO_MANY_FILES', $result->key);
    $this->assertSame(['%maxFileCount%' => 2], $result->params);
  }

  public function testUploadIsRefusedWhenTheLibraryIsTooHeavy(): void
  {
    $result = $this->manager(['maxWeightFiles' => 1])->upload($this->uploadedPng('x.png'), 'empty');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_UPLOAD_FILESYSTEM_FULL', $result->key);
  }

  public function testQuotasAreOptional(): void
  {
    $result = $this->manager(['maxNumFiles' => 0, 'maxWeightFiles' => 0])->upload($this->uploadedPng('x.png'), 'empty');

    $this->assertTrue($result->isSuccess());
  }

  public function testNothingIsAuthorisedWithoutMimes(): void
  {
    $manager = $this->manager(['mimesImage' => [], 'mimesDocument' => []]);

    $result = $manager->upload($this->uploadedPng('x.png'), 'empty');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_UPLOAD_FILE_UNAUTH_EXT', $result->key);
  }

  public function testAFailedMoveIsReported(): void
  {
    // the manager only hands over existing folders : drive the uploader itself
    $uploader = new Uploader($this->manager()->config());

    $result = $uploader->upload($this->uploadedPng('x.png'), $this->rootPath('logo.png'), 'logo.png');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('MEDIAMANAGER_ERROR_OPERATION_FAILED', $result->key);
    $this->assertStringStartsWith('Unable to create the "', $result->params['%error%']);
    $this->assertSame('x.png', $result->originalName);
  }

  // -- downscaling ----------------------------------------------------------

  public static function downscaleProvider(): array
  {
    return [
      'oversized landscape is bounded by its width' => [100, 2, 400, 200, [100, 50]],
      'oversized portrait is bounded by its height' => [100, 2, 200, 400, [50, 100]],
      'picture within bounds is left alone' => [1000, 2, 400, 200, [400, 200]],
      'over-weight picture within bounds keeps its size' => [1000, 0, 400, 200, [400, 200]],
    ];
  }

  #[DataProvider('downscaleProvider')]
  public function testPictureIsFittedWithinTheLongSide(int $longSide, int $maxWeightImage, int $width, int $height, array $expected): void
  {
    $manager = $this->manager(['maxPictureLongSide' => $longSide, 'maxWeightImage' => $maxWeightImage]);

    $result = $manager->upload($this->uploadedPng('image.png', $width, $height), 'empty');

    $this->assertTrue($result->isSuccess(), $result->key);
    $this->assertSame($expected, \array_slice(\getimagesize($this->rootPath('empty/image.png')), 0, 2));
  }

  public function testAPictureThatIsNotReallyOneIsKeptAsIs(): void
  {
    $result = $this->manager()->upload($this->uploadedRaw('fake.png', 'image/png', 64), 'empty');

    $this->assertTrue($result->isSuccess());
    $this->assertSame(\str_repeat('x', 64), \file_get_contents($this->rootPath('empty/fake.png')));
  }

  public function testAPictureThatCannotBeResizedIsKeptAsIs(): void
  {
    // a valid header announcing 400x200, then nothing to decode
    $png = $this->scratchDir . '/source.png';
    $this->makePng($png, 400, 200);
    \file_put_contents($png, \substr(\file_get_contents($png), 0, 40));
    $truncated = \file_get_contents($png);

    $file = new UploadedFile($png, 'cut.png', 'image/png', null, true);
    $result = $this->manager(['maxPictureLongSide' => 100])->upload($file, 'empty');

    $this->assertTrue($result->isSuccess());
    $this->assertSame($truncated, \file_get_contents($this->rootPath('empty/cut.png')));
  }

  public function testDocumentsAreNeverTouched(): void
  {
    $manager = $this->manager();
    $file = $this->uploadedRaw('notice.pdf', 'application/pdf', 64);

    $result = $manager->upload($file, 'empty');

    $this->assertTrue($result->isSuccess());
    $this->assertSame(64, \filesize($this->rootPath('empty/notice.pdf')));
  }
}
