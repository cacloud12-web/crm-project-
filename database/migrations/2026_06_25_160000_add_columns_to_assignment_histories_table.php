<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('ca_id')->after('id');
            $table->unsignedBigInteger('previous_employee_id')->nullable()->after('ca_id');
            $table->unsignedBigInteger('new_employee_id')->after('previous_employee_id');
            $table->string('assignment_type')->default('Manual')->after('new_employee_id');
            $table->string('reason')->nullable()->after('assignment_type');
            $table->unsignedBigInteger('assigned_by')->nullable()->after('reason');
            $table->timestamp('assigned_at')->useCurrent()->after('assigned_by');

            $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->cascadeOnDelete();
            $table->foreign('previous_employee_id')->references('employee_id')->on('employees')->nullOnDelete();
            $table->foreign('new_employee_id')->references('employee_id')->on('employees')->cascadeOnDelete();
            $table->foreign('assigned_by')->references('employee_id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assignment_histories', function (Blueprint $table) {
            $table->dropForeign(['ca_id']);
            $table->dropForeign(['previous_employee_id']);
            $table->dropForeign(['new_employee_id']);
            $table->dropForeign(['assigned_by']);
            $table->dropColumn([
                'ca_id',
                'previous_employee_id',
                'new_employee_id',
                'assignment_type',
                'reason',
                'assigned_by',
                'assigned_at',
            ]);
        });
    }
};
