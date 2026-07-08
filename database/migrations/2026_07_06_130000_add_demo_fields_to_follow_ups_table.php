<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->unsignedInteger('team_size')->nullable()->after('priority');
            $table->string('demo_provider_name')->nullable()->after('team_size');
            $table->string('meeting_link', 500)->nullable()->after('demo_provider_name');
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropColumn(['team_size', 'demo_provider_name', 'meeting_link']);
        });
    }
};
