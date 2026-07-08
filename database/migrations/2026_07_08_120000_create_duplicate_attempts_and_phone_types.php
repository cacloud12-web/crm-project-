<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ca_masters', function (Blueprint $table) {
            if (! Schema::hasColumn('ca_masters', 'mobile_no_type')) {
                $table->string('mobile_no_type', 16)->nullable()->after('normalized_mobile');
                $table->index('mobile_no_type');
            }
            if (! Schema::hasColumn('ca_masters', 'alternate_mobile_no_type')) {
                $table->string('alternate_mobile_no_type', 16)->nullable()->after('normalized_alternate_mobile');
            }
        });

        if (! Schema::hasTable('duplicate_attempts')) {
            Schema::create('duplicate_attempts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->unsignedBigInteger('lead_id')->nullable();
                $table->string('duplicate_number', 20);
                $table->string('saved_number', 20)->nullable();
                $table->unsignedBigInteger('matched_lead_id')->nullable();
                $table->string('attempt_type', 32)->default('duplicate');
                $table->string('status', 32)->default('open');
                $table->string('field_name', 32)->nullable();
                $table->string('browser', 255)->nullable();
                $table->string('ip', 45)->nullable();
                $table->boolean('number_changed')->default(false);
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->foreign('employee_id')
                    ->references('employee_id')
                    ->on('employees')
                    ->nullOnDelete();
                $table->foreign('lead_id')
                    ->references('ca_id')
                    ->on('ca_masters')
                    ->nullOnDelete();
                $table->foreign('matched_lead_id')
                    ->references('ca_id')
                    ->on('ca_masters')
                    ->nullOnDelete();
                $table->index(['employee_id', 'created_at']);
                $table->index(['attempt_type', 'created_at']);
                $table->index(['status', 'created_at']);
                $table->index('matched_lead_id');
            });
        }

        $this->backfillPhoneTypes();
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_attempts');

        Schema::table('ca_masters', function (Blueprint $table) {
            foreach (['mobile_no_type', 'alternate_mobile_no_type'] as $column) {
                if (Schema::hasColumn('ca_masters', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function backfillPhoneTypes(): void
    {
        if (! Schema::hasTable('ca_masters')) {
            return;
        }

        DB::table('ca_masters')
            ->select(['ca_id', 'mobile_no', 'alternate_mobile_no'])
            ->orderBy('ca_id')
            ->chunkById(200, function ($rows) {
                $classifier = app(\App\Services\Leads\PhoneClassificationService::class);

                foreach ($rows as $row) {
                    DB::table('ca_masters')->where('ca_id', $row->ca_id)->update([
                        'mobile_no_type' => $classifier->classify($row->mobile_no),
                        'alternate_mobile_no_type' => $classifier->classify($row->alternate_mobile_no),
                    ]);
                }
            }, 'ca_id');
    }
};
