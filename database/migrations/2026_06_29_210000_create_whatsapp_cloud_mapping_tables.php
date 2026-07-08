<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_settings')) {
            Schema::create('whatsapp_settings', function (Blueprint $table) {
                $table->id();
                $table->string('provider_name')->default('Meta WhatsApp Cloud API');
                $table->string('phone_number_id')->nullable();
                $table->string('business_account_id')->nullable();
                $table->text('access_token')->nullable();
                $table->string('api_version', 20)->default('v21.0');
                $table->string('mode', 20)->default('simulation');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('message_templates')) {
            Schema::create('message_templates', function (Blueprint $table) {
                $table->id();
                $table->string('channel', 30)->default('whatsapp');
                $table->string('template_name');
                $table->string('language_code', 12)->default('en');
                $table->text('body_template');
                $table->string('status', 30)->default('approved');
                $table->string('category', 80)->nullable();
                $table->json('variable_map')->nullable();
                $table->json('meta_components')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['channel', 'template_name', 'language_code'], 'message_templates_channel_name_lang_unique');
                $table->index(['channel', 'status', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('whatsapp_settings');
    }
};
