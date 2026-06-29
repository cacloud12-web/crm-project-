<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_actions', function (Blueprint $table) {
            $table->string('imported_by')->default('System')->after('initiated_by');
        });

        Schema::table('bulk_action_logs', function (Blueprint $table) {
            $table->json('original_data')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_action_logs', function (Blueprint $table) {
            $table->dropColumn('original_data');
        });

        Schema::table('bulk_actions', function (Blueprint $table) {
            $table->dropColumn('imported_by');
        });
    }
};
