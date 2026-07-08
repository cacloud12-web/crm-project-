<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            if (! Schema::hasColumn('ca_masters', 'lead_tags')) {
                $table->json('lead_tags')->nullable()->after('status');
            }
            if (! Schema::hasColumn('ca_masters', 'priority')) {
                $table->string('priority', 20)->default('Medium')->after('lead_tags');
            }
            if (! Schema::hasColumn('ca_masters', 'research_status')) {
                $table->string('research_status', 50)->nullable()->after('priority');
            }
            if (! Schema::hasColumn('ca_masters', 'view_count')) {
                $table->unsignedInteger('view_count')->default(0)->after('research_status');
            }
            if (! Schema::hasColumn('ca_masters', 'last_viewed_at')) {
                $table->timestamp('last_viewed_at')->nullable()->after('view_count');
            }
        });

        Schema::create('lead_views', function (Blueprint $table) {
            $table->id('lead_view_id');
            $table->unsignedBigInteger('ca_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('viewed_at');
            $table->timestamps();

            $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['ca_id', 'viewed_at']);
            $table->index(['user_id', 'viewed_at']);
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id('approval_request_id');
            $table->string('request_type', 80);
            $table->unsignedBigInteger('ca_id');
            $table->unsignedBigInteger('followup_id')->nullable();
            $table->unsignedBigInteger('requested_by_user_id');
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->cascadeOnDelete();
            $table->foreign('requested_by_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['status', 'created_at']);
            $table->index(['ca_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('lead_views');

        Schema::table('ca_masters', function (Blueprint $table) {
            foreach (['lead_tags', 'priority', 'research_status', 'view_count', 'last_viewed_at'] as $column) {
                if (Schema::hasColumn('ca_masters', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
