<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ca_masters')) {
            return;
        }

        if (! Schema::hasColumn('ca_masters', 'sales_remarks')) {
            Schema::table('ca_masters', function (Blueprint $table) {
                $table->text('sales_remarks')->nullable()->after('email_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ca_masters')) {
            return;
        }

        if (Schema::hasColumn('ca_masters', 'sales_remarks')) {
            Schema::table('ca_masters', function (Blueprint $table) {
                $table->dropColumn('sales_remarks');
            });
        }
    }
};
