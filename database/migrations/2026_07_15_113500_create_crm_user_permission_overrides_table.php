<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('crm_permission_id')->constrained('crm_permissions')->cascadeOnDelete();
            $table->string('effect', 10); // allow | deny
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'crm_permission_id'], 'crm_user_perm_override_unique');
            $table->index(['user_id', 'effect']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_user_permission_overrides');
    }
};
