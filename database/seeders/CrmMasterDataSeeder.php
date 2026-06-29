<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\RoleMaster;
use App\Models\SourceLead;
use App\Models\State;
use App\Models\TeamSizeMaster;
use Illuminate\Database\Seeder;

class CrmMasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $states = [
            'Maharashtra',
            'Karnataka',
            'Gujarat',
            'Delhi',
            'Tamil Nadu',
            'Telangana',
        ];

        foreach ($states as $stateName) {
            State::firstOrCreate(['state_name' => $stateName]);
        }

        $cities = [
            ['Mumbai', 'Maharashtra'],
            ['Pune', 'Maharashtra'],
            ['Bangalore', 'Karnataka'],
            ['Delhi', 'Delhi'],
            ['Ahmedabad', 'Gujarat'],
            ['Hyderabad', 'Telangana'],
            ['Chennai', 'Tamil Nadu'],
        ];

        foreach ($cities as [$cityName, $stateName]) {
            $stateId = State::where('state_name', $stateName)->value('state_id');
            if (! $stateId) {
                continue;
            }

            City::firstOrCreate(
                ['city_name' => $cityName, 'state_id' => $stateId],
                ['city_name' => $cityName, 'state_id' => $stateId],
            );
        }

        foreach (['Website', 'Referral', 'Exhibition', 'Cold Call'] as $sourceName) {
            SourceLead::firstOrCreate(['source_name' => $sourceName]);
        }

        $teamSizes = [
            ['min' => 1, 'max' => 5, 'label' => 'Solo / Small'],
            ['min' => 6, 'max' => 15, 'label' => 'Mid-size'],
            ['min' => 16, 'max' => 50, 'label' => 'Large'],
            ['min' => 51, 'max' => 999, 'label' => 'Enterprise'],
        ];

        foreach ($teamSizes as $range) {
            TeamSizeMaster::firstOrCreate(
                [
                    'team_size_min' => $range['min'],
                    'team_size_max' => $range['max'],
                ],
                [
                    'team_size_label' => $range['label'],
                ],
            );
        }

        $roles = [
            ['Sales Executive', 'Front-line lead conversion'],
            ['Sales Manager', 'Team lead and target ownership'],
            ['Regional Manager', 'Multi-city oversight'],
            ['Admin', 'System configuration access'],
        ];

        foreach ($roles as [$roleName, $description]) {
            RoleMaster::firstOrCreate(
                ['role_name' => $roleName],
                ['description' => $description],
            );
        }
    }
}
