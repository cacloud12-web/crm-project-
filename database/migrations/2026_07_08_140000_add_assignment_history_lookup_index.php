<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Support\Database\MigrationIndexHelper;
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_histories', function (Blueprint $table) {
            if (! MigrationIndexHelper::exists('assignment_histories', 'assignment_histories_ca_assigned_index')) {
                $table->index(['ca_id', 'assigned_at'], 'assignment_histories_ca_assigned_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assignment_histories', function (Blueprint $table) {
            $table->dropIndex('assignment_histories_ca_assigned_index');
        });
    }

};
