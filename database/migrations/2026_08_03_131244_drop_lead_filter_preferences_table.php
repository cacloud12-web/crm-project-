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
        Schema::dropIfExists('lead_filter_preferences');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('lead_filter_preferences', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
