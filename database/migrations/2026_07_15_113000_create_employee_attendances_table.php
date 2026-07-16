<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_attendances')) {
            return;
        }

        Schema::create('employee_attendances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('attendance_date');
            $table->string('status', 20);
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'attendance_date'], 'employee_attendances_employee_date_unique');
            $table->index('attendance_date');
            $table->index('status');

            $table->foreign('employee_id')
                ->references('employee_id')
                ->on('employees')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_attendances');
    }
};
