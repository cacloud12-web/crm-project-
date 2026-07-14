<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (Schema::hasColumn('users', 'last_seen_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // No ->after() — SQLite does not support column positioning.
            $table->timestamp('last_seen_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'last_seen_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
