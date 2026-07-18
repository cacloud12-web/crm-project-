<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema-only: technical demo marker for safe, explicit cleanup commands.
 * Does not insert or rewrite any provider rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('demo_providers')) {
            return;
        }

        Schema::table('demo_providers', function (Blueprint $table) {
            if (! Schema::hasColumn('demo_providers', 'is_demo')) {
                $table->boolean('is_demo')->default(false)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('demo_providers')) {
            return;
        }

        Schema::table('demo_providers', function (Blueprint $table) {
            if (Schema::hasColumn('demo_providers', 'is_demo')) {
                $table->dropColumn('is_demo');
            }
        });
    }
};
