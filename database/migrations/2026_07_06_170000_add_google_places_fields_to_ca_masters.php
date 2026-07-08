<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            if (! Schema::hasColumn('ca_masters', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('google_maps_url');
            }
            if (! Schema::hasColumn('ca_masters', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (! Schema::hasColumn('ca_masters', 'verified_from_google')) {
                $table->boolean('verified_from_google')->default(false)->after('longitude');
            }
            if (! Schema::hasColumn('ca_masters', 'google_places_cache')) {
                $table->json('google_places_cache')->nullable()->after('verified_from_google');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            foreach (['google_places_cache', 'verified_from_google', 'longitude', 'latitude'] as $column) {
                if (Schema::hasColumn('ca_masters', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
