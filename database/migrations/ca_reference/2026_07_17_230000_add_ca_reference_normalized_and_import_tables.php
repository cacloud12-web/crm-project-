<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: normalized match columns + import batch/row audit tables.
 * Does not drop or recreate ca_firms / ca_partners / ca_addresses.
 */
return new class extends Migration
{
    protected $connection = 'ca_reference';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('ca_firms') && ! $schema->hasColumn('ca_firms', 'normalized_firm_name')) {
            $schema->table('ca_firms', function (Blueprint $table) {
                $table->string('normalized_firm_name', 255)->nullable()->after('firm_name');
                $table->index('normalized_firm_name', 'ca_firms_normalized_firm_name_index');
            });
        }

        if ($schema->hasTable('ca_partners') && ! $schema->hasColumn('ca_partners', 'normalized_partner_name')) {
            $schema->table('ca_partners', function (Blueprint $table) {
                $table->string('normalized_partner_name', 255)->nullable()->after('partner_name');
                $table->index(['firm_id', 'normalized_partner_name'], 'ca_partners_firm_norm_partner_index');
            });
        }

        if ($schema->hasTable('ca_addresses') && ! $schema->hasColumn('ca_addresses', 'normalized_city')) {
            $schema->table('ca_addresses', function (Blueprint $table) {
                $table->string('normalized_city', 120)->nullable()->after('city');
                $table->index(['firm_id', 'normalized_city'], 'ca_addresses_firm_norm_city_index');
            });
        }

        if (! $schema->hasTable('ca_reference_import_batches')) {
            $schema->create('ca_reference_import_batches', function (Blueprint $table) {
                $table->id();
                $table->string('source_file');
                $table->string('source_file_hash', 64)->nullable()->index();
                $table->string('status', 32)->default('processing')->index();
                $table->boolean('dry_run')->default(false);
                $table->unsignedInteger('chunk_size')->default(1000);
                $table->unsignedInteger('source_rows')->default(0);
                $table->unsignedInteger('imported_firms')->default(0);
                $table->unsignedInteger('imported_partners')->default(0);
                $table->unsignedInteger('imported_cities')->default(0);
                $table->unsignedInteger('duplicate_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->unsignedInteger('reused_firms')->default(0);
                $table->json('reconciliation')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('ca_reference_import_rows')) {
            $schema->create('ca_reference_import_rows', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('batch_id')->index();
                $table->unsignedInteger('row_number');
                $table->string('source_file');
                $table->string('raw_firm_name')->nullable();
                $table->string('raw_ca_name')->nullable();
                $table->string('raw_city')->nullable();
                $table->string('normalized_firm_name')->nullable();
                $table->string('normalized_ca_name')->nullable();
                $table->string('normalized_city')->nullable();
                $table->unsignedBigInteger('firm_id')->nullable()->index();
                $table->unsignedBigInteger('partner_id')->nullable();
                $table->unsignedBigInteger('address_id')->nullable();
                $table->string('status', 32)->index();
                $table->boolean('is_duplicate')->default(false);
                $table->string('failure_reason', 255)->nullable();
                $table->json('details')->nullable();
                $table->timestamps();

                $table->index(['batch_id', 'row_number'], 'ca_ref_import_rows_batch_row_index');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('ca_reference_import_rows')) {
            $schema->drop('ca_reference_import_rows');
        }
        if ($schema->hasTable('ca_reference_import_batches')) {
            $schema->drop('ca_reference_import_batches');
        }

        if ($schema->hasTable('ca_addresses') && $schema->hasColumn('ca_addresses', 'normalized_city')) {
            $schema->table('ca_addresses', function (Blueprint $table) {
                $table->dropIndex('ca_addresses_firm_norm_city_index');
                $table->dropColumn('normalized_city');
            });
        }

        if ($schema->hasTable('ca_partners') && $schema->hasColumn('ca_partners', 'normalized_partner_name')) {
            $schema->table('ca_partners', function (Blueprint $table) {
                $table->dropIndex('ca_partners_firm_norm_partner_index');
                $table->dropColumn('normalized_partner_name');
            });
        }

        if ($schema->hasTable('ca_firms') && $schema->hasColumn('ca_firms', 'normalized_firm_name')) {
            $schema->table('ca_firms', function (Blueprint $table) {
                $table->dropIndex('ca_firms_normalized_firm_name_index');
                $table->dropColumn('normalized_firm_name');
            });
        }
    }
};
