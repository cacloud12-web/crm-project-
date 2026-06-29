<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('user_name')->default('System')->after('id');
            $table->string('module')->after('user_name');
            $table->string('record_id')->nullable()->after('module');
            $table->string('action')->after('record_id');
            $table->text('detail')->nullable()->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn(['user_name', 'module', 'record_id', 'action', 'detail']);
        });
    }
};
