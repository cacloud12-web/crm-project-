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
            if (! Schema::hasColumn('ca_masters', 'normalized_mobile')) {
                $table->string('normalized_mobile', 15)->nullable()->after('mobile_no');
                $table->index('normalized_mobile');
            }
            if (! Schema::hasColumn('ca_masters', 'normalized_alternate_mobile')) {
                $table->string('normalized_alternate_mobile', 15)->nullable()->after('alternate_mobile_no');
                $table->index('normalized_alternate_mobile');
            }
            if (! Schema::hasColumn('ca_masters', 'created_by_employee_id')) {
                $table->unsignedBigInteger('created_by_employee_id')->nullable()->after('bulk_action_id');
                $table->foreign('created_by_employee_id')
                    ->references('employee_id')
                    ->on('employees')
                    ->nullOnDelete();
                $table->index('created_by_employee_id');
            }
        });

        if (! Schema::hasTable('lead_phone_numbers')) {
            Schema::create('lead_phone_numbers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ca_id');
                $table->string('normalized_number', 15);
                $table->string('phone_type', 20);
                $table->timestamps();

                $table->foreign('ca_id')
                    ->references('ca_id')
                    ->on('ca_masters')
                    ->cascadeOnDelete();
                $table->unique('normalized_number');
                $table->index(['ca_id', 'phone_type']);
            });
        }

        if (! Schema::hasTable('duplicate_attempt_logs')) {
            Schema::create('duplicate_attempt_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->unsignedBigInteger('lead_id');
                $table->string('attempted_mobile', 20);
                $table->timestamp('attempted_at');
                $table->string('reason')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamps();

                $table->foreign('employee_id')
                    ->references('employee_id')
                    ->on('employees')
                    ->nullOnDelete();
                $table->foreign('lead_id')
                    ->references('ca_id')
                    ->on('ca_masters')
                    ->cascadeOnDelete();
                $table->index(['employee_id', 'attempted_at']);
                $table->index('lead_id');
            });
        }

        $this->backfillNormalizedPhones();
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_attempt_logs');
        Schema::dropIfExists('lead_phone_numbers');

        Schema::table('ca_masters', function (Blueprint $table) {
            if (Schema::hasColumn('ca_masters', 'created_by_employee_id')) {
                $table->dropForeign(['created_by_employee_id']);
                $table->dropColumn('created_by_employee_id');
            }
            if (Schema::hasColumn('ca_masters', 'normalized_alternate_mobile')) {
                $table->dropIndex(['normalized_alternate_mobile']);
                $table->dropColumn('normalized_alternate_mobile');
            }
            if (Schema::hasColumn('ca_masters', 'normalized_mobile')) {
                $table->dropIndex(['normalized_mobile']);
                $table->dropColumn('normalized_mobile');
            }
        });
    }

    private function backfillNormalizedPhones(): void
    {
        if (! Schema::hasTable('ca_masters')) {
            return;
        }

        $normalize = static function (?string $value): ?string {
            if ($value === null || trim($value) === '') {
                return null;
            }

            $digits = preg_replace('/\D/', '', $value) ?? '';
            if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
                $digits = substr($digits, -10);
            }

            return strlen($digits) >= 10 ? $digits : null;
        };

        DB::table('ca_masters')
            ->select(['ca_id', 'mobile_no', 'alternate_mobile_no'])
            ->orderBy('ca_id')
            ->chunkById(200, function ($leads) use ($normalize) {
                foreach ($leads as $lead) {
                    $primary = $normalize($lead->mobile_no);
                    $alternate = $normalize($lead->alternate_mobile_no);

                    DB::table('ca_masters')
                        ->where('ca_id', $lead->ca_id)
                        ->update([
                            'normalized_mobile' => $primary,
                            'normalized_alternate_mobile' => $alternate,
                        ]);

                    DB::table('lead_phone_numbers')->where('ca_id', $lead->ca_id)->delete();

                    $registry = [];
                    if ($primary) {
                        $registry[$primary] = 'primary';
                    }
                    if ($alternate && $alternate !== $primary) {
                        $registry[$alternate] = 'alternate';
                    }

                    $now = now();
                    foreach ($registry as $number => $type) {
                        if (DB::table('lead_phone_numbers')->where('normalized_number', $number)->exists()) {
                            continue;
                        }

                        DB::table('lead_phone_numbers')->insert([
                            'ca_id' => $lead->ca_id,
                            'normalized_number' => $number,
                            'phone_type' => $type,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }, 'ca_id');
    }
};
