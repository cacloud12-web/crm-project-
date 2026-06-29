<?php

namespace App\Services\Listing;

use App\Models\SavedListingFilter;
use Illuminate\Support\Collection;

class SavedListingFilterService
{
    public function list(string $listingKey): Collection
    {
        return SavedListingFilter::query()
            ->where('listing_key', $listingKey)
            ->orderByDesc('is_preset')
            ->orderBy('name')
            ->get();
    }

    public function store(string $listingKey, string $name, array $filters, ?string $userId = null): SavedListingFilter
    {
        return SavedListingFilter::create([
            'listing_key' => $listingKey,
            'name' => $name,
            'filters' => $filters,
            'user_id' => $userId,
            'is_preset' => false,
        ]);
    }

    public function delete(int $id): void
    {
        SavedListingFilter::query()->whereKey($id)->delete();
    }
}
