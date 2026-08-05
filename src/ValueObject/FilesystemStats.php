<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace JDZ\MediaManager\ValueObject;

/**
 * Library-wide counters and the quota verdict drawn from them.
 *
 * `errors` holds translation keys, not messages :
 * `[ ['key' => 'MEDIAMANAGER_ERROR_..', 'params' => ['%x%' => 1]], .. ]`
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class FilesystemStats
{
  public function __construct(
    public readonly int $numFiles,
    public readonly int $weightFiles,
    public readonly int $maxNumFiles,
    public readonly int $maxWeightFiles,
    public readonly bool $uploadable,
    public readonly array $errors = [],
  ) {}

  /**
   * Weigh a library and rule on whether it may still take uploads.
   *
   * A quota of 0 means "not enforced" — the package stays usable without one.
   *
   * @param int $totalBytes  cumulated size of every file in the library
   */
  public static function measure(int $numFiles, int $totalBytes, int $maxNumFiles, int $maxWeightFiles): static
  {
    $errors = [];
    $uploadable = true;

    if ($maxNumFiles > 0 && $numFiles >= $maxNumFiles) {
      $errors[] = [
        'key' => 'MEDIAMANAGER_ERROR_UPLOAD_TOO_MANY_FILES',
        'params' => ['%maxFileCount%' => $maxNumFiles],
      ];
      $uploadable = false;
    }

    $weightFiles = (int)\ceil($totalBytes / 1000000);

    if ($maxWeightFiles > 0 && $weightFiles >= $maxWeightFiles) {
      $errors[] = [
        'key' => 'MEDIAMANAGER_ERROR_UPLOAD_FILESYSTEM_FULL',
        'params' => ['%maxWeightFiles%' => $maxWeightFiles],
      ];
      $uploadable = false;
    }

    return new static($numFiles, $weightFiles, $maxNumFiles, $maxWeightFiles, $uploadable, $errors);
  }

  /**
   * The scalars, ready to merge with the consumer's translated `errors`.
   */
  public function toArray(): array
  {
    return [
      'maxWeightFiles' => $this->maxWeightFiles,
      'maxNumFiles' => $this->maxNumFiles,
      'numFiles' => $this->numFiles,
      'weightFiles' => $this->weightFiles,
      'uploadable' => $this->uploadable,
    ];
  }
}
