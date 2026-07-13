<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('demo_providers')) {
            Schema::create('demo_providers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('default_meeting_link', 500)->nullable();
                $table->unsignedSmallInteger('min_team_size')->nullable();
                $table->unsignedSmallInteger('max_team_size')->nullable();
                $table->unsignedSmallInteger('slot_duration_minutes')->default(60);
                $table->unsignedSmallInteger('buffer_minutes')->default(15);
                $table->unsignedSmallInteger('max_demos_per_day')->default(6);
                $table->time('work_start_time')->default('10:00:00');
                $table->time('work_end_time')->default('19:00:00');
                $table->time('break_start_time')->nullable();
                $table->time('break_end_time')->nullable();
                $table->json('working_days')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['is_active', 'sort_order']);
            });
        }

        if (! Schema::hasTable('demo_provider_leaves')) {
            Schema::create('demo_provider_leaves', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('demo_provider_id')->index();
                $table->date('leave_date')->index();
                $table->string('reason', 255)->nullable();
                $table->timestamps();

                $table->foreign('demo_provider_id')->references('id')->on('demo_providers')->cascadeOnDelete();
                $table->unique(['demo_provider_id', 'leave_date'], 'demo_provider_leaves_unique');
            });
        }

        Schema::table('demo_schedules', function (Blueprint $table) {
            if (! Schema::hasColumn('demo_schedules', 'demo_provider_id')) {
                $table->unsignedBigInteger('demo_provider_id')->nullable()->after('employee_id')->index();
                $table->foreign('demo_provider_id')->references('id')->on('demo_providers')->nullOnDelete();
            }
            if (! Schema::hasColumn('demo_schedules', 'manager_id')) {
                $table->unsignedBigInteger('manager_id')->nullable()->after('demo_provider_id')->index();
            }
            if (! Schema::hasColumn('demo_schedules', 'demo_end_at')) {
                $table->dateTime('demo_end_at')->nullable()->after('demo_at');
            }
            if (! Schema::hasColumn('demo_schedules', 'team_size')) {
                $table->unsignedSmallInteger('team_size')->nullable()->after('demo_end_at');
            }
            if (! Schema::hasColumn('demo_schedules', 'demo_provider_name')) {
                $table->string('demo_provider_name', 120)->nullable()->after('team_size');
            }
            if (! Schema::hasColumn('demo_schedules', 'notes')) {
                $table->text('notes')->nullable()->after('meeting_link');
            }
            if (! Schema::hasColumn('demo_schedules', 'updated_by_user_id')) {
                $table->unsignedBigInteger('updated_by_user_id')->nullable()->after('created_by_user_id');
            }
        });

        if (! Schema::hasTable('demo_schedule_history')) {
            Schema::create('demo_schedule_history', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('demo_schedule_id')->index();
                $table->string('action', 40);
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->unsignedBigInteger('performed_by_user_id')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('demo_schedule_id')->references('id')->on('demo_schedules')->cascadeOnDelete();
            });
        }

        $this->seedProvidersFromConfig();
        $this->backfillScheduleProviders();
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_schedule_history');
        Schema::dropIfExists('demo_provider_leaves');

        Schema::table('demo_schedules', function (Blueprint $table) {
            foreach (['demo_provider_id', 'manager_id', 'demo_end_at', 'team_size', 'demo_provider_name', 'notes', 'updated_by_user_id'] as $column) {
                if (Schema::hasColumn('demo_schedules', $column)) {
                    if ($column === 'demo_provider_id') {
                        $table->dropForeign(['demo_provider_id']);
                    }
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('demo_providers');
    }

    private function seedProvidersFromConfig(): void
    {
        if (DB::table('demo_providers')->exists()) {
            return;
        }

        $defaults = [
            ['start' => '10:00:00', 'end' => '19:00:00', 'break_start' => '13:00:00', 'break_end' => '14:00:00'],
        ];
        $workingDays = [1, 2, 3, 4, 5, 6];
        $sort = 0;

        foreach (config('demo_providers.tiers', []) as $tier) {
            $sort++;
            DB::table('demo_providers')->insert([
                'name' => (string) ($tier['provider'] ?? 'Provider '.$sort),
                'default_meeting_link' => (string) ($tier['meeting_link'] ?? ''),
                'min_team_size' => isset($tier['min']) ? (int) $tier['min'] : null,
                'max_team_size' => $tier['max'] !== null ? (int) $tier['max'] : null,
                'slot_duration_minutes' => 60,
                'buffer_minutes' => 15,
                'max_demos_per_day' => 6,
                'work_start_time' => $defaults[0]['start'],
                'work_end_time' => $defaults[0]['end'],
                'break_start_time' => $defaults[0]['break_start'],
                'break_end_time' => $defaults[0]['break_end'],
                'working_days' => json_encode($workingDays),
                'is_active' => true,
                'sort_order' => $sort,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function backfillScheduleProviders(): void
    {
        $providers = DB::table('demo_providers')->get();
        if ($providers->isEmpty()) {
            return;
        }

        DB::table('demo_schedules')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($providers) {
                foreach ($rows as $row) {
                    $updates = [];
                    if (empty($row->demo_end_at) && ! empty($row->demo_at)) {
                        $updates['demo_end_at'] = date('Y-m-d H:i:s', strtotime($row->demo_at.' +60 minutes'));
                    }

                    if (empty($row->demo_provider_id)) {
                        $providerName = null;
                        if (! empty($row->followup_id)) {
                            $providerName = DB::table('follow_ups')
                                ->where('followup_id', $row->followup_id)
                                ->value('demo_provider_name');
                        }
                        $teamSize = null;
                        if (! empty($row->ca_id)) {
                            $teamSize = DB::table('ca_masters')->where('ca_id', $row->ca_id)->value('team_size');
                        }
                        $match = $this->matchProvider($providers, $providerName, $teamSize);
                        if ($match) {
                            $updates['demo_provider_id'] = $match->id;
                            $updates['demo_provider_name'] = $match->name;
                        }
                    }

                    if ($updates !== []) {
                        DB::table('demo_schedules')->where('id', $row->id)->update($updates);
                    }
                }
            });
    }

    private function matchProvider($providers, ?string $providerName, mixed $teamSize): ?object
    {
        if ($providerName) {
            $byName = $providers->first(fn ($p) => strcasecmp((string) $p->name, $providerName) === 0);
            if ($byName) {
                return $byName;
            }
        }

        $size = is_numeric($teamSize) ? (int) $teamSize : null;
        if ($size === null || $size < 1) {
            return null;
        }

        foreach ($providers as $provider) {
            $min = $provider->min_team_size !== null ? (int) $provider->min_team_size : 1;
            $max = $provider->max_team_size !== null ? (int) $provider->max_team_size : null;
            if ($size < $min) {
                continue;
            }
            if ($max !== null && $size > $max) {
                continue;
            }

            return $provider;
        }

        return null;
    }
};
