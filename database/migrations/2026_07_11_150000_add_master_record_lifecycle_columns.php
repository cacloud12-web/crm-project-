<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'states',
        'cities',
        'source_leads',
        'team_size_masters',
        'role_masters',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'is_active')) {
                    $table->boolean('is_active')->default(true);
                }
                if (! Schema::hasColumn($tableName, 'deactivated_at')) {
                    $table->timestamp('deactivated_at')->nullable();
                }
                if (! Schema::hasColumn($tableName, 'deactivated_by')) {
                    $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn($tableName, 'is_system')) {
                    $table->boolean('is_system')->default(false);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'deactivated_by')) {
                    $table->dropConstrainedForeignId('deactivated_by');
                }
                foreach (['is_system', 'deactivated_at', 'is_active'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
