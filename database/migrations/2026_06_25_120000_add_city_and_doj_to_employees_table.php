<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id')->nullable()->after('mobile_no');
            $table->date('date_of_joining')->nullable()->after('role');

            $table->foreign('city_id')->references('city_id')->on('cities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['city_id']);
            $table->dropColumn(['city_id', 'date_of_joining']);
        });
    }
};
