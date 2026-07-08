<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            if (! Schema::hasColumn('ca_masters', 'verified_address')) {
                $table->text('verified_address')->nullable()->after('google_place_id');
            }
            if (! Schema::hasColumn('ca_masters', 'google_rating')) {
                $table->decimal('google_rating', 3, 1)->nullable()->after('verified_address');
            }
            if (! Schema::hasColumn('ca_masters', 'google_review_count')) {
                $table->unsignedInteger('google_review_count')->nullable()->after('google_rating');
            }
            if (! Schema::hasColumn('ca_masters', 'google_business_status')) {
                $table->string('google_business_status', 50)->nullable()->after('google_review_count');
            }
            if (! Schema::hasColumn('ca_masters', 'google_maps_url')) {
                $table->string('google_maps_url', 500)->nullable()->after('google_business_status');
            }
            if (! Schema::hasColumn('ca_masters', 'researched_at')) {
                $table->timestamp('researched_at')->nullable()->after('google_maps_url');
            }
        });

        if (! Schema::hasTable('lead_research_logs')) {
            Schema::create('lead_research_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ca_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('query', 500)->nullable();
                $table->string('source', 40)->default('fallback');
                $table->string('place_id', 128)->nullable();
                $table->json('result_payload')->nullable();
                $table->json('saved_fields')->nullable();
                $table->string('action', 40)->default('search');
                $table->string('ip_address', 45)->nullable();
                $table->timestamps();

                $table->foreign('ca_id')->references('ca_id')->on('ca_masters')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_research_logs');

        Schema::table('ca_masters', function (Blueprint $table) {
            foreach ([
                'verified_address',
                'google_rating',
                'google_review_count',
                'google_business_status',
                'google_maps_url',
                'researched_at',
            ] as $column) {
                if (Schema::hasColumn('ca_masters', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
