<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_import_mapping_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_name');
            $table->json('field_mapping');
            $table->string('created_by')->default('System');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_import_mapping_templates');
    }
};
