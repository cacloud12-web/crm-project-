<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('audience', 20)->default('user');
            $table->json('audience_roles')->nullable();
            $table->string('type', 50)->index();
            $table->string('title');
            $table->text('message');
            $table->string('severity', 20)->default('brand');
            $table->string('entity_type', 80)->nullable();
            $table->string('entity_id', 80)->nullable();
            $table->json('payload')->nullable();
            $table->string('dedup_key', 120)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['audience', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->unique(['user_id', 'dedup_key']);
        });

        Schema::create('crm_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_notification_id')->constrained('crm_notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['crm_notification_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_notification_reads');
        Schema::dropIfExists('crm_notifications');
    }
};
