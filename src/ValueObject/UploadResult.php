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
 * Outcome of a single file upload.
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class UploadResult
{
  private function __construct(
    public readonly bool $success,
    public readonly string $key = '',
    public readonly array $params = [],
    public readonly string $originalName = '',
    public readonly string $id = '',
    public readonly string $filename = '',
    public readonly string $folder = '',
  ) {}

  /**
   * @param string $id  the cleaned base name, without extension
   */
  public static function ok(string $originalName, string $id, string $filename, string $folder): static
  {
    return new static(true, '', [], $originalName, $id, $filename, $folder);
  }

  public static function fail(string $key, array $params = [], string $originalName = ''): static
  {
    return new static(false, $key, $params, $originalName);
  }

  public function isSuccess(): bool
  {
    return $this->success;
  }

  /**
   * Library-relative path of the uploaded file.
   */
  public function value(): string
  {
    return ('' === $this->folder ? '' : $this->folder . '/') . $this->filename;
  }

  public function toArray(): array
  {
    return [
      'originalName' => $this->originalName,
      'id' => $this->id,
      'filename' => $this->filename,
      'value' => $this->value(),
    ];
  }
}
