<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Formerly mutated demo_providers by personal name / meeting URL.
 * Data mutations during migrate are unsafe — cleanup is now an explicit Artisan command:
 *   php artisan crm:audit-demo-records
 *   php artisan crm:cleanup-demo-records --force
 *
 * This migration is intentionally a no-op so environments that already recorded it
 * keep a stable migrations table entry without rewriting history.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op: do not rename, delete, or clear production records from migrations.
    }

    public function down(): void
    {
        // No-op.
    }
};
