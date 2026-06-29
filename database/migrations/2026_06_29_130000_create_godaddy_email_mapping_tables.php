<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_settings')) {
            Schema::create('email_settings', function (Blueprint $table) {
                $table->id();
                $table->string('provider_name')->default('GoDaddy SMTP');
                $table->string('smtp_host')->nullable();
                $table->unsignedSmallInteger('smtp_port')->nullable();
                $table->string('smtp_username')->nullable();
                $table->text('smtp_password')->nullable();
                $table->string('smtp_encryption')->nullable();
                $table->string('from_email')->nullable();
                $table->string('from_name')->nullable();
                $table->string('mode')->default('simulation');
                $table->timestamps();
            });

            DB::table('email_settings')->insert([
                'provider_name' => 'GoDaddy SMTP',
                'smtp_host' => 'smtpout.secureserver.net',
                'smtp_port' => 465,
                'smtp_username' => null,
                'smtp_password' => null,
                'smtp_encryption' => 'ssl',
                'from_email' => null,
                'from_name' => null,
                'mode' => 'simulation',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('email_templates')) {
            Schema::create('email_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('subject');
                $table->text('body');
                $table->json('variables')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        Schema::table('email_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('email_logs', 'employee_id')) {
                $table->unsignedBigInteger('employee_id')->nullable()->after('ca_id');
                $table->foreign('employee_id')
                    ->references('employee_id')
                    ->on('employees')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('email_logs', 'provider_response')) {
                $table->text('provider_response')->nullable()->after('failed_reason');
            }
            if (! Schema::hasColumn('email_logs', 'error_message')) {
                $table->text('error_message')->nullable()->after('provider_response');
            }
            if (! Schema::hasColumn('email_logs', 'opened_at')) {
                $table->timestamp('opened_at')->nullable()->after('sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            if (Schema::hasColumn('email_logs', 'employee_id')) {
                $table->dropForeign(['employee_id']);
                $table->dropColumn('employee_id');
            }
            foreach (['provider_response', 'error_message', 'opened_at'] as $column) {
                if (Schema::hasColumn('email_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('email_settings');
    }
};
