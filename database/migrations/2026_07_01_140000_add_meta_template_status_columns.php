<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('message_templates', 'meta_template_id')) {
                $table->string('meta_template_id', 64)->nullable()->after('meta_api_name');
            }
            if (! Schema::hasColumn('message_templates', 'meta_status')) {
                $table->string('meta_status', 40)->nullable()->after('meta_template_id');
            }
            if (! Schema::hasColumn('message_templates', 'meta_rejection_reason')) {
                $table->text('meta_rejection_reason')->nullable()->after('meta_status');
            }
            if (! Schema::hasColumn('message_templates', 'meta_status_payload')) {
                $table->json('meta_status_payload')->nullable()->after('meta_rejection_reason');
            }
            if (! Schema::hasColumn('message_templates', 'meta_submitted_at')) {
                $table->timestamp('meta_submitted_at')->nullable()->after('meta_status_payload');
            }
            if (! Schema::hasColumn('message_templates', 'meta_status_updated_at')) {
                $table->timestamp('meta_status_updated_at')->nullable()->after('meta_submitted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            foreach ([
                'meta_template_id',
                'meta_status',
                'meta_rejection_reason',
                'meta_status_payload',
                'meta_submitted_at',
                'meta_status_updated_at',
            ] as $column) {
                if (Schema::hasColumn('message_templates', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
