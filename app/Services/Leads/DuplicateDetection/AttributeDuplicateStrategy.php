<?php

namespace App\Services\Leads\DuplicateDetection;

use App\Models\CaMaster;
use Closure;
use Illuminate\Database\Eloquent\Builder;

class AttributeDuplicateStrategy
{
  public function __construct(
    private readonly string $key,
    private readonly string $inputField,
    private readonly string $column,
    private readonly Closure $normalizer,
  ) {}

  public function key(): string
  {
    return $this->key;
  }

  public function inputField(): string
  {
    return $this->inputField;
  }

  /**
   * @return array{normalized: ?string, duplicate: ?CaMaster}
   */
  public function inspect(mixed $value, ?int $excludeCaId = null): array
  {
    $normalized = ($this->normalizer)($value);

    if ($normalized === null || $normalized === '') {
      return ['normalized' => null, 'duplicate' => null];
    }

    $query = CaMaster::query()->where($this->column, $normalized);

    if ($excludeCaId) {
      $query->where('ca_id', '!=', $excludeCaId);
    }

    return [
      'normalized' => $normalized,
      'duplicate' => $query->first(),
    ];
  }
}
