<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_name', 120);
            $table->string('sender_id', 20);
            $table->text('body_template');
            $table->json('variable_map')->nullable();
            $table->string('status', 20)->default('pending');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('template_name');
        });

        \Illuminate\Support\Facades\DB::table('sms_templates')->insert([
            'template_name' => 'Demo Reminder DLT',
            'sender_id' => 'CACLOD',
            'body_template' => 'Dear {#var#}, your demo for {#var#} is scheduled. Contact CA Cloud Desk.',
            'variable_map' => json_encode(['ca_name', 'firm_name']),
            'status' => 'approved',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('sms_campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('sms_campaigns', 'sms_template_id')) {
                $table->unsignedBigInteger('sms_template_id')->nullable()->after('sender_id');
            }
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('sms_logs', 'sms_template_id')) {
                $table->unsignedBigInteger('sms_template_id')->nullable()->after('campaign_id');
            }
            if (! Schema::hasColumn('sms_logs', 'template_name')) {
                $table->string('template_name', 120)->nullable()->after('sms_template_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            foreach (['sms_template_id', 'template_name'] as $column) {
                if (Schema::hasColumn('sms_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('sms_campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('sms_campaigns', 'sms_template_id')) {
                $table->dropColumn('sms_template_id');
            }
        });

        Schema::dropIfExists('sms_templates');
    }
};
