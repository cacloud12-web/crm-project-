<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_actions', function (Blueprint $table) {
            $table->integer('duplicate_records')->default(0)->after('success_records');
            $table->integer('skipped_records')->default(0)->after('duplicate_records');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_actions', function (Blueprint $table) {
            $table->dropColumn(['duplicate_records', 'skipped_records']);
        });
    }
};
