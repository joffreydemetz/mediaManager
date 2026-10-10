<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace JDZ\MediaManager\Manager;

use JDZ\MediaManager\ValueObject\MediaConfig;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * One-off move of the 2.x flat protect backups (`<protectPath>/<name>`) to
 * their 3.x slot (`<protectPath>/<library-relative path>`).
 *
 * A flat backup is moved when exactly one file of that name exists in the
 * library and its slot is free. Everything else is reported and left alone :
 * the same name in several folders (ambiguous), no such file (orphan), a slot
 * already taken (conflict). Dry run unless told to write.
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class ProtectMigration
{
  private const NOISE_FILES = ['.DS_Store', 'Thumbs.db'];

  public function __construct(private readonly MediaConfig $config) {}

  /**
   * @return array{
   *   written: bool,
   *   moves: list<array{from: string, to: string}>,
   *   inPlace: list<string>,
   *   ambiguous: array<string, list<string>>,
   *   orphans: list<string>,
   *   conflicts: array<string, string>
   * }
   *
   * @throws \RuntimeException  without a protect folder, or when it is missing
   */
  public function migrate(bool $write = false): array
  {
    if ('' === $this->config->protectPath) {
      throw new \RuntimeException('Protect folder not configured');
    }

    if (false === @\is_dir($this->config->protectPath)) {
      throw new \RuntimeException('Protect folder not found: ' . $this->config->protectPath);
    }

    if (false === @\is_dir($this->config->rootPath)) {
      throw new \RuntimeException('Media root not found: ' . $this->config->rootPath);
    }

    $report = [
      'written' => $write,
      'moves' => [],
      'inPlace' => [],
      'ambiguous' => [],
      'orphans' => [],
      'conflicts' => [],
    ];

    $flat = $this->flatBackupNames();

    if ([] === $flat) {
      return $report;
    }

    $library = $this->libraryFilesByName();

    foreach ($flat as $name) {
      $matches = $library[$name] ?? [];

      if ([] === $matches) {
        $report['orphans'][] = $name;
        continue;
      }

      if (\count($matches) > 1) {
        $report['ambiguous'][$name] = $matches;
        continue;
      }

      $to = $matches[0];

      if ($to === $name) {
        $report['inPlace'][] = $name;
        continue;
      }

      if (true === @\file_exists($this->config->protectPath . '/' . $to)) {
        $report['conflicts'][$name] = $to;
        continue;
      }

      $report['moves'][] = ['from' => $name, 'to' => $to];
    }

    if (true === $write) {
      $fs = new Filesystem();

      foreach ($report['moves'] as $move) {
        $target = $this->config->protectPath . '/' . $move['to'];

        $fs->mkdir(\dirname($target));
        $fs->rename($this->config->protectPath . '/' . $move['from'], $target);
      }
    }

    return $report;
  }

  /**
   * Names of the files sitting directly under the protect folder.
   *
   * @return string[]
   */
  private function flatBackupNames(): array
  {
    $names = [];

    $finder = Finder::create()
      ->files()
      ->in($this->config->protectPath)
      ->ignoreUnreadableDirs()
      ->depth(0)
      ->notName(self::NOISE_FILES)
      ->sortByName();

    foreach ($finder as $file) {
      $names[] = $file->getFilename();
    }

    return $names;
  }

  /**
   * Every library file, grouped by bare name, as relative paths.
   *
   * @return array<string, list<string>>
   */
  private function libraryFilesByName(): array
  {
    $byName = [];

    $finder = Finder::create()
      ->files()
      ->in($this->config->rootPath)
      ->ignoreUnreadableDirs()
      ->notName(self::NOISE_FILES)
      ->sortByName();

    foreach ($finder as $file) {
      $byName[$file->getFilename()][] = \str_replace('\\', '/', $file->getRelativePathname());
    }

    foreach ($byName as &$paths) {
      \sort($paths);
    }

    return $byName;
  }
}
