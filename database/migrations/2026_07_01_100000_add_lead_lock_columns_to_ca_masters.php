<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            if (! Schema::hasColumn('ca_masters', 'locked_by')) {
                $table->unsignedBigInteger('locked_by')->nullable()->after('last_viewed_at');
                $table->foreign('locked_by')
                    ->references('employee_id')
                    ->on('employees')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('ca_masters', 'locked_at')) {
                $table->timestamp('locked_at')->nullable()->after('locked_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            if (Schema::hasColumn('ca_masters', 'locked_by')) {
                $table->dropForeign(['locked_by']);
                $table->dropColumn(['locked_by', 'locked_at']);
            }
        });
    }
};
