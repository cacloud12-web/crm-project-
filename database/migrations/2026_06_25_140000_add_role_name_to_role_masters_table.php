<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_masters', function (Blueprint $table) {
            $table->string('role_name')->after('id');
            $table->string('description')->nullable()->after('role_name');
        });
    }

    public function down(): void
    {
        Schema::table('role_masters', function (Blueprint $table) {
            $table->dropColumn(['role_name', 'description']);
        });
    }
};
