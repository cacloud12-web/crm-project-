<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            $table->unsignedBigInteger('team_size_id')->nullable()->after('team_size');

            $table->foreign('team_size_id')
                ->references('id')
                ->on('team_size_masters')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            //
        });
    }
};
