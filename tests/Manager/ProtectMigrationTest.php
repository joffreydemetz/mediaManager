<?php

namespace JDZ\MediaManager\Tests\Manager;

use JDZ\MediaManager\Manager\ProtectMigration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Moving the 2.x flat backups (`protect/<name>`) to their 3.x slot
 * (`protect/<relative path>`).
 */
class ProtectMigrationTest extends TestCase
{
  use ManagerFixture;

  /**
   * The flat backups of a 2.x install, one of each kind :
   *
   *   a.jpg     -> photos/a.jpg is the only a.jpg            : moved
   *   deep.jpg  -> photos/sub/deep.jpg                        : moved
   *   logo.png  -> the root logo.png                          : already in place
   *   b.png     -> photos/b.png AND a root b.png              : ambiguous
   *   gone.jpg  -> no such file in the library                : orphan
   *   home.png  -> pages/home.png, but that slot is taken     : conflict
   */
  private function flatBackups(): void
  {
    $this->makePng($this->rootPath('b.png'), 5, 5);

    foreach (['a.jpg', 'deep.jpg', 'logo.png', 'b.png', 'gone.jpg', 'home.png'] as $name) {
      (new Filesystem())->dumpFile($this->protectPath($name), 'backup of ' . $name);
    }

    (new Filesystem())->dumpFile($this->protectPath('pages/home.png'), 'already migrated');
  }

  private function migration(): ProtectMigration
  {
    return new ProtectMigration($this->manager()->config());
  }

  public function testDryRunReportsWithoutTouchingAnything(): void
  {
    $this->flatBackups();

    $report = $this->migration()->migrate();

    $this->assertFalse($report['written']);
    $this->assertSame([
      ['from' => 'a.jpg', 'to' => 'photos/a.jpg'],
      ['from' => 'deep.jpg', 'to' => 'photos/sub/deep.jpg'],
    ], $report['moves']);
    $this->assertSame(['logo.png'], $report['inPlace']);
    $this->assertSame(['b.png' => ['b.png', 'photos/b.png']], $report['ambiguous']);
    $this->assertSame(['gone.jpg'], $report['orphans']);
    $this->assertSame(['home.png' => 'pages/home.png'], $report['conflicts']);

    $this->assertFileExists($this->protectPath('a.jpg'));
    $this->assertFileDoesNotExist($this->protectPath('photos/a.jpg'));
  }

  public function testWriteMovesTheUnambiguousBackupsOnly(): void
  {
    $this->flatBackups();

    $report = $this->migration()->migrate(true);

    $this->assertTrue($report['written']);
    $this->assertCount(2, $report['moves']);

    $this->assertFileDoesNotExist($this->protectPath('a.jpg'));
    $this->assertSame('backup of a.jpg', \file_get_contents($this->protectPath('photos/a.jpg')));
    $this->assertSame('backup of deep.jpg', \file_get_contents($this->protectPath('photos/sub/deep.jpg')));

    $this->assertFileExists($this->protectPath('logo.png'));
    $this->assertFileExists($this->protectPath('b.png'));
    $this->assertFileExists($this->protectPath('gone.jpg'));
    $this->assertFileExists($this->protectPath('home.png'));
    $this->assertSame('already migrated', \file_get_contents($this->protectPath('pages/home.png')));

    $this->assertTrue($this->manager()->isProtected('photos', 'a.jpg'));
  }

  public function testASecondRunHasNothingLeftToMove(): void
  {
    $this->flatBackups();
    $this->migration()->migrate(true);

    $report = $this->migration()->migrate(true);

    $this->assertSame([], $report['moves']);
    $this->assertSame(['logo.png'], $report['inPlace']);
    $this->assertSame(['b.png' => ['b.png', 'photos/b.png']], $report['ambiguous']);
  }

  public function testNestedBackupsAreNotFlatOnes(): void
  {
    (new Filesystem())->dumpFile($this->protectPath('photos/a.jpg'), 'migrated');

    $report = $this->migration()->migrate(true);

    $this->assertSame([], $report['moves']);
    $this->assertSame([], $report['orphans']);
    $this->assertSame('migrated', \file_get_contents($this->protectPath('photos/a.jpg')));
  }

  public function testAnEmptyProtectFolderIsANoOp(): void
  {
    $report = $this->migration()->migrate(true);

    $this->assertSame([], $report['moves']);
    $this->assertSame([], $report['inPlace']);
  }

  public function testWithoutAProtectFolderThereIsNothingToMigrate(): void
  {
    $migration = new ProtectMigration($this->manager(['protectPath' => ''])->config());

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Protect folder not configured');

    $migration->migrate();
  }

  public function testAMissingProtectFolderIsAnError(): void
  {
    $migration = new ProtectMigration($this->manager(['protectPath' => $this->baseDir . '/nope'])->config());

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Protect folder not found');

    $migration->migrate();
  }

  public function testTheManagerExposesTheMigration(): void
  {
    $this->flatBackups();

    $report = $this->manager()->migrateProtectBackups();

    $this->assertFalse($report['written']);
    $this->assertCount(2, $report['moves']);
  }

  public function testTheConsoleScriptReportsAndWrites(): void
  {
    $this->flatBackups();
    $script = \dirname(__DIR__, 2) . '/bin/migrate-protect';

    $dryRun = $this->runScript([$script, $this->rootDir, $this->protectDir]);

    $this->assertSame(0, $dryRun['code'], $dryRun['output']);
    $this->assertStringContainsString('a.jpg -> photos/a.jpg', $dryRun['output']);
    $this->assertStringContainsString('ambiguous', $dryRun['output']);
    $this->assertStringContainsString('dry run', $dryRun['output']);
    $this->assertFileExists($this->protectPath('a.jpg'));

    $write = $this->runScript([$script, $this->rootDir, $this->protectDir, '--write']);

    $this->assertSame(0, $write['code'], $write['output']);
    $this->assertFileDoesNotExist($this->protectPath('a.jpg'));
    $this->assertFileExists($this->protectPath('photos/a.jpg'));
  }

  public function testTheConsoleScriptRefusesBadArguments(): void
  {
    $script = \dirname(__DIR__, 2) . '/bin/migrate-protect';

    $this->assertSame(1, $this->runScript([$script])['code']);
    $this->assertSame(1, $this->runScript([$script, $this->rootDir, $this->baseDir . '/nope'])['code']);
  }

  /**
   * @return array{code: int, output: string}
   */
  private function runScript(array $args): array
  {
    $command = \escapeshellarg(\PHP_BINARY) . ' ' . \implode(' ', \array_map('escapeshellarg', $args)) . ' 2>&1';

    \exec($command, $lines, $code);

    return ['code' => $code, 'output' => \implode("\n", $lines)];
  }
}
