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
 * Outcome of a media operation.
 *
 * Carries a translation KEY and its params, never a rendered message : the
 * package has no language layer, the consumer translates.
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class OperationResult
{
  private function __construct(
    public readonly bool $success,
    public readonly string $key = '',
    public readonly array $params = [],
    public readonly array $data = [],
  ) {}

  public static function ok(string $key = '', array $params = [], array $data = []): static
  {
    return new static(true, $key, $params, $data);
  }

  public static function fail(string $key, array $params = [], array $data = []): static
  {
    return new static(false, $key, $params, $data);
  }

  public function isSuccess(): bool
  {
    return $this->success;
  }

  /**
   * A value from the operation payload (new folder path, parent, file name, ..).
   */
  public function get(string $key, mixed $default = null): mixed
  {
    return $this->data[$key] ?? $default;
  }

  public function toArray(): array
  {
    return [
      'success' => $this->success,
      'key' => $this->key,
      'params' => $this->params,
      'data' => $this->data,
    ];
  }
}
