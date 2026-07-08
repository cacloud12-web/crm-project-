<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('label', 100);
            $table->boolean('is_system')->default(true);
            $table->boolean('is_editable')->default(true);
            $table->timestamps();
        });

        Schema::create('crm_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('module', 60);
            $table->string('action', 60);
            $table->string('label', 120)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['module', 'action']);
            $table->index('module');
        });

        Schema::create('crm_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_role_id')->constrained('crm_roles')->cascadeOnDelete();
            $table->foreignId('crm_permission_id')->constrained('crm_permissions')->cascadeOnDelete();
            $table->boolean('granted')->default(true);
            $table->timestamps();

            $table->unique(['crm_role_id', 'crm_permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_role_permissions');
        Schema::dropIfExists('crm_permissions');
        Schema::dropIfExists('crm_roles');
    }
};
