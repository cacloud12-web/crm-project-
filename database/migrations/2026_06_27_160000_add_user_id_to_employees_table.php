<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('employee_id')->constrained('users')->nullOnDelete();
        });

        $employees = DB::table('employees')->whereNull('user_id')->orderBy('employee_id')->get();

        foreach ($employees as $employee) {
            $userId = DB::table('users')
                ->where('email', $employee->email_id)
                ->value('id');

            if ($userId) {
                DB::table('employees')
                    ->where('employee_id', $employee->employee_id)
                    ->update(['user_id' => $userId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
