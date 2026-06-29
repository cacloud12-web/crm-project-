<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_size_masters', function (Blueprint $table) {
            $table->integer('team_size_min')->nullable();
            $table->integer('team_size_max')->nullable();
            $table->string('team_size_label')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('team_size_masters', function (Blueprint $table) {
            //
        });
    }
};
