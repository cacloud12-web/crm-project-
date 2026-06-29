<?php

namespace App\Support\Listing;

use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ListingResponse
{
    public static function from(
        array $result,
        string $resourceClass,
        string $message = 'Listing loaded',
    ): JsonResponse {
        $resolvedItems = self::resolveItems($result['items'] ?? [], $resourceClass);

        if ($result['pagination'] === null) {
            return ApiResponse::success([
                'items' => $resolvedItems,
                'meta' => $result['meta'] ?? [],
            ], $message);
        }

        return ApiResponse::success([
            'items' => $resolvedItems,
            'pagination' => $result['pagination'],
            'meta' => $result['meta'] ?? [],
        ], $message);
    }

    /**
     * @param  array<int, mixed>|Collection<int, mixed>  $items
     * @param  class-string<JsonResource>  $resourceClass
     * @return list<array<string, mixed>>
     */
    private static function resolveItems(array|Collection $items, string $resourceClass): array
    {
        if ($items instanceof Collection) {
            $items = $items->all();
        }

        if ($items === []) {
            return [];
        }

        $first = $items[0];

        // Cached listings store plain array rows — skip JsonResource wrapping.
        if (is_array($first) && ! $first instanceof Model) {
            return array_map(
                fn ($row) => is_array($row) ? $row : (array) $row,
                $items,
            );
        }

        /** @var JsonResource $resourceClass */
        return $resourceClass::collection($items)->resolve();
    }
}
