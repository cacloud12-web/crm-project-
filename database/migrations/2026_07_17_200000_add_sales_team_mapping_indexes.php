<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales-team mapping (state + firm + CA) indexes for 2L+ ca_masters.
 * Additive only — never wipes production data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ca_masters')) {
            Schema::table('ca_masters', function (Blueprint $table) {
                if (! Schema::hasColumn('ca_masters', 'normalized_ca_name')) {
                    $table->string('normalized_ca_name', 255)->nullable()->after('ca_name');
                }
                if (! Schema::hasColumn('ca_masters', 'normalized_state')) {
                    $table->string('normalized_state', 120)->nullable()->after('state_id');
                }
            });

            Schema::table('ca_masters', function (Blueprint $table) {
                $indexes = collect(Schema::getIndexes('ca_masters'))->pluck('name')->all();
                $this->addIndexWhenReady($table, $indexes, 'ca_masters_normalized_ca_name_index', ['normalized_ca_name']);
                $this->addIndexWhenReady($table, $indexes, 'ca_masters_normalized_state_index', ['normalized_state']);
                $this->addIndexWhenReady($table, $indexes, 'ca_masters_state_norm_firm_index', ['state_id', 'normalized_firm_name']);
                $this->addIndexWhenReady($table, $indexes, 'ca_masters_state_norm_firm_ca_index', ['state_id', 'normalized_firm_name', 'normalized_ca_name']);
                $this->addIndexWhenReady($table, $indexes, 'ca_masters_frn_index', ['frn']);
                $this->addIndexWhenReady($table, $indexes, 'ca_masters_membership_no_index', ['membership_no']);
            });
        }

        if (Schema::hasTable('master_mapping_decisions') && ! Schema::hasColumn('master_mapping_decisions', 'decision_meta')) {
            Schema::table('master_mapping_decisions', function (Blueprint $table) {
                $table->json('decision_meta')->nullable()->after('new_values');
            });
        }

        if (Schema::hasTable('lead_phone_numbers')) {
            Schema::table('lead_phone_numbers', function (Blueprint $table) {
                $indexes = collect(Schema::getIndexes('lead_phone_numbers'))->pluck('name')->all();
                $this->addIndexWhenReady($table, $indexes, 'lead_phone_numbers_normalized_number_index', ['normalized_number']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('master_mapping_decisions') && Schema::hasColumn('master_mapping_decisions', 'decision_meta')) {
            Schema::table('master_mapping_decisions', function (Blueprint $table) {
                $table->dropColumn('decision_meta');
            });
        }

        if (Schema::hasTable('ca_masters')) {
            Schema::table('ca_masters', function (Blueprint $table) {
                $indexes = collect(Schema::getIndexes('ca_masters'))->pluck('name')->all();
                foreach ([
                    'ca_masters_state_norm_firm_ca_index',
                    'ca_masters_state_norm_firm_index',
                    'ca_masters_normalized_state_index',
                    'ca_masters_normalized_ca_name_index',
                    'ca_masters_membership_no_index',
                    'ca_masters_frn_index',
                ] as $index) {
                    if (in_array($index, $indexes, true)) {
                        $table->dropIndex($index);
                    }
                }
            });

            Schema::table('ca_masters', function (Blueprint $table) {
                foreach (['normalized_state', 'normalized_ca_name'] as $column) {
                    if (Schema::hasColumn('ca_masters', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    /**
     * @param  list<string>  $existingIndexes
     * @param  list<string>  $columns
     */
    private function addIndexWhenReady(Blueprint $table, array $existingIndexes, string $name, array $columns): void
    {
        if (in_array($name, $existingIndexes, true)) {
            return;
        }
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table->getTable(), $column)) {
                return;
            }
        }
        $table->index($columns, $name);
    }
};
