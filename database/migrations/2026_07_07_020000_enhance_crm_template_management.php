<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('email_templates', 'category')) {
                $table->string('category', 64)->nullable()->after('slug');
            }
            if (! Schema::hasColumn('email_templates', 'header')) {
                $table->string('header', 120)->nullable()->after('category');
            }
            if (! Schema::hasColumn('email_templates', 'footer')) {
                $table->string('footer', 255)->nullable()->after('body');
            }
            if (! Schema::hasColumn('email_templates', 'publish_status')) {
                $table->string('publish_status', 32)->default('active')->after('is_active');
            }
            if (! Schema::hasColumn('email_templates', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('publish_status')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('email_templates', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('message_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('message_templates', 'header')) {
                $table->string('header', 120)->nullable()->after('category');
            }
            if (! Schema::hasColumn('message_templates', 'footer')) {
                $table->string('footer', 255)->nullable()->after('body_template');
            }
            if (! Schema::hasColumn('message_templates', 'publish_status')) {
                $table->string('publish_status', 32)->default('active')->after('is_active');
            }
            if (! Schema::hasColumn('message_templates', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('publish_status')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('message_templates', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
        });

        if (! Schema::hasTable('crm_template_variables')) {
            Schema::create('crm_template_variables', function (Blueprint $table) {
                $table->id();
                $table->string('group_name', 80);
                $table->string('variable_key', 80);
                $table->string('label', 120);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['variable_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_template_variables');

        Schema::table('message_templates', function (Blueprint $table) {
            foreach (['updated_by', 'created_by', 'publish_status', 'footer', 'header'] as $column) {
                if (Schema::hasColumn('message_templates', $column)) {
                    if (in_array($column, ['created_by', 'updated_by'], true)) {
                        $table->dropConstrainedForeignId($column);
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });

        Schema::table('email_templates', function (Blueprint $table) {
            foreach (['updated_by', 'created_by', 'publish_status', 'footer', 'header', 'category'] as $column) {
                if (Schema::hasColumn('email_templates', $column)) {
                    if (in_array($column, ['created_by', 'updated_by'], true)) {
                        $table->dropConstrainedForeignId($column);
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });
    }
};
