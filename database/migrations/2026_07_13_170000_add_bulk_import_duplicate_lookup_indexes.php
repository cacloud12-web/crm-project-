<?php

use App\Support\Database\MigrationIndexHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            if (! MigrationIndexHelper::exists('ca_masters', 'ca_masters_gst_no_index')) {
                $table->index('gst_no', 'ca_masters_gst_no_index');
            }
            if (! MigrationIndexHelper::exists('ca_masters', 'ca_masters_frn_index')) {
                $table->index('frn', 'ca_masters_frn_index');
            }
            if (! MigrationIndexHelper::exists('ca_masters', 'ca_masters_membership_no_index')) {
                $table->index('membership_no', 'ca_masters_membership_no_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            foreach ([
                'ca_masters_gst_no_index',
                'ca_masters_frn_index',
                'ca_masters_membership_no_index',
            ] as $index) {
                if (MigrationIndexHelper::exists('ca_masters', $index)) {
                    $table->dropIndex($index);
                }
            }
        });
    }
};
