<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('states', function (Blueprint $table) {
            $table->unique('state_name');
        });

        Schema::table('cities', function (Blueprint $table) {
            $table->unique(['state_id', 'city_name']);
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropUnique(['state_id', 'city_name']);
        });

        Schema::table('states', function (Blueprint $table) {
            $table->dropUnique(['state_name']);
        });
    }
};
