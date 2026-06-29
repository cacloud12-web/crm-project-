<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('lead_assignment_engines', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('all_main_tables', function (Blueprint $table) {
            //
        });
    }
};
