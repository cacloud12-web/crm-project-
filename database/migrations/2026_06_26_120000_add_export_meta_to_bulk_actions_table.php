<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_actions', function (Blueprint $table) {
            $table->string('export_format')->nullable()->after('file_name');
            $table->json('export_filters')->nullable()->after('export_format');
            $table->string('output_path')->nullable()->after('export_filters');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_actions', function (Blueprint $table) {
            $table->dropColumn(['export_format', 'export_filters', 'output_path']);
        });
    }
};
