<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            $table->string('alternate_mobile_no', 20)->nullable()->after('mobile_no');
        });
    }

    public function down(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            $table->dropColumn('alternate_mobile_no');
        });
    }
};
