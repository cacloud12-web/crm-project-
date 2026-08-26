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

        Schema::table('ca_masters', function (Blueprint $table) {
            if (! Schema::hasColumn('ca_masters', 'remarks_1')) {
                $table->text('remarks_1')->nullable()->after('sales_remarks');
            }
            if (! Schema::hasColumn('ca_masters', 'remarks_2')) {
                $table->text('remarks_2')->nullable()->after('remarks_1');
            }
            if (! Schema::hasColumn('ca_masters', 'remarks_3')) {
                $table->text('remarks_3')->nullable()->after('remarks_2');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ca_masters')) {
            return;
        }

        Schema::table('ca_masters', function (Blueprint $table) {
            foreach (['remarks_1', 'remarks_2', 'remarks_3'] as $column) {
                if (Schema::hasColumn('ca_masters', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
