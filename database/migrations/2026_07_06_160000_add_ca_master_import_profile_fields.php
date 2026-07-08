<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            if (! Schema::hasColumn('ca_masters', 'membership_no')) {
                $table->string('membership_no', 60)->nullable()->after('gst_no');
            }
            if (! Schema::hasColumn('ca_masters', 'frn')) {
                $table->string('frn', 60)->nullable()->after('membership_no');
            }
            if (! Schema::hasColumn('ca_masters', 'address')) {
                $table->text('address')->nullable()->after('frn');
            }
            if (! Schema::hasColumn('ca_masters', 'pincode')) {
                $table->string('pincode', 12)->nullable()->after('address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            foreach (['pincode', 'address', 'frn', 'membership_no'] as $column) {
                if (Schema::hasColumn('ca_masters', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
