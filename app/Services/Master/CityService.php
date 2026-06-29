<?php

namespace App\Services\Master;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\Employee;
use App\Models\State;
use App\Services\Concerns\SearchesListings;
use App\Services\Master\Concerns\LogsMasterActivity;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class CityService
{
    use LogsMasterActivity;
    use SearchesListings;

    public function __construct(
        private readonly LookupResolverService $lookupResolver,
    ) {}

    public function search(array $params = []): array
    {
        return $this->searchListing(
            City::query()->with('state'),
            $params,
            'cities',
        );
    }

    public function list(): Collection
    {
        return $this->listAllFromSearch(
            City::query()->with('state'),
            [],
            'cities',
        );
    }

    public function find(int|string $id): City
    {
        return City::query()->with('state')->findOrFail($id);
    }

    public function create(array $data): City
    {
        $stateId = $this->lookupResolver->resolveStateId($data['state_id']);
        $city = City::create([
            'city_name' => trim($data['city_name']),
            'state_id' => $stateId,
        ]);

        $this->logMasterActivity(
            'Add City',
            'CITY_MASTER',
            (string) $city->city_id,
            $city->city_name.' · State '.$stateId,
        );

        return $city->fresh()->load('state');
    }

    public function update(City $city, array $data): City
    {
        $stateId = array_key_exists('state_id', $data)
            ? $this->lookupResolver->resolveStateId($data['state_id'])
            : $city->state_id;

        $this->assertCityBelongsToState($city, $stateId);

        $before = $city->city_name;
        $city->update([
            'city_name' => trim($data['city_name'] ?? $city->city_name),
            'state_id' => $stateId,
        ]);

        $this->logMasterActivity(
            'Update City',
            'CITY_MASTER',
            (string) $city->city_id,
            $before.' → '.$city->city_name,
        );

        return $city->fresh()->load('state');
    }

    public function delete(City $city): void
    {
        if (CaMaster::query()->where('city_id', $city->city_id)->exists()) {
            throw new InvalidArgumentException('Cannot delete a city that is used by CA Master records.');
        }

        if (Employee::query()->where('city_id', $city->city_id)->exists()) {
            throw new InvalidArgumentException('Cannot delete a city that is used by employees.');
        }

        $name = $city->city_name;
        $id = (string) $city->city_id;
        $city->delete();

        $this->logMasterActivity('Delete City', 'CITY_MASTER', $id, $name);
    }

    private function assertCityBelongsToState(City $city, int $stateId): void
    {
        if (! State::query()->where('state_id', $stateId)->exists()) {
            throw new InvalidArgumentException('Selected state does not exist.');
        }
    }
}
