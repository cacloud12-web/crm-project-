<?php

namespace App\Console\Commands;

use App\Models\DemoProvider;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Audit-only: lists candidates that look like demo/test data.
 * Never mutates. Never matches by personal name.
 *
 * Safe markers:
 *   - demo_providers.is_demo = true
 *   - users/employees email domain in @ca.local, @example.local, @example.test, @test.local
 */
class AuditDemoRecordsCommand extends Command
{
    protected $signature = 'crm:audit-demo-records';

    protected $description = 'Audit demo/test-marked records without changing any data';

    /** @var list<string> */
    public const TEST_EMAIL_DOMAINS = ['ca.local', 'example.local', 'example.test', 'test.local'];

    public function handle(): int
    {
        $this->info('Demo data audit (read-only). No records will be changed.');
        $this->newLine();

        $this->auditDemoProviders();
        $this->auditUsersByDomain();
        $this->auditEmployeesByDomain();

        $this->newLine();
        $this->comment('To remove only marker-matched rows (never by name):');
        $this->line('  php artisan crm:cleanup-demo-records --force');
        $this->comment('Unmarked production rows require manual review — this audit does not guess from names.');

        return self::SUCCESS;
    }

    private function auditDemoProviders(): void
    {
        if (! Schema::hasTable('demo_providers')) {
            $this->warn('demo_providers table missing.');

            return;
        }

        $hasFlag = Schema::hasColumn('demo_providers', 'is_demo');
        $marked = $hasFlag
            ? DemoProvider::query()->where('is_demo', true)->orderBy('id')->get(['id', 'name', 'is_demo', 'is_active'])
            : collect();

        $unmarked = DemoProvider::query()
            ->when($hasFlag, fn ($q) => $q->where(function ($inner) {
                $inner->where('is_demo', false)->orWhereNull('is_demo');
            }))
            ->orderBy('id')
            ->get(['id', 'name', 'is_active']);

        $this->line('=== demo_providers (is_demo = true) — safe cleanup candidates ===');
        if ($marked->isEmpty()) {
            $this->line('  (none)');
        } else {
            $this->table(
                ['id', 'name', 'is_active'],
                $marked->map(fn ($p) => [$p->id, $p->name, $p->is_active ? 'yes' : 'no'])->all()
            );
        }

        $this->line('=== demo_providers WITHOUT is_demo marker — manual review only ===');
        if ($unmarked->isEmpty()) {
            $this->line('  (none)');
        } else {
            $this->table(
                ['id', 'name', 'is_active', 'note'],
                $unmarked->map(fn ($p) => [$p->id, $p->name, $p->is_active ? 'yes' : 'no', 'Do not auto-delete'])->all()
            );
            $this->warn('Unmarked providers are NOT cleanup targets. Confirm manually if any are legacy seed data.');
        }
    }

    private function auditUsersByDomain(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $query = User::query()->orderBy('id');
        $query->where(function ($q) {
            foreach (self::TEST_EMAIL_DOMAINS as $domain) {
                $q->orWhere('email', 'like', '%@'.$domain);
            }
        });

        $rows = $query->get(['id', 'email', 'crm_role', 'is_active']);
        $this->line('=== users with test-only email domains ===');
        if ($rows->isEmpty()) {
            $this->line('  (none)');
        } else {
            $this->table(
                ['id', 'email', 'crm_role', 'is_active'],
                $rows->map(fn ($u) => [$u->id, $u->email, $u->crm_role, $u->is_active ? 'yes' : 'no'])->all()
            );
        }
    }

    private function auditEmployeesByDomain(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        $query = Employee::query()->orderBy('employee_id');
        $query->where(function ($q) {
            foreach (self::TEST_EMAIL_DOMAINS as $domain) {
                $q->orWhere('email_id', 'like', '%@'.$domain);
            }
        });

        $rows = $query->get(['employee_id', 'email_id', 'status']);
        $this->line('=== employees with test-only email domains ===');
        if ($rows->isEmpty()) {
            $this->line('  (none)');
        } else {
            $this->table(
                ['employee_id', 'email_id', 'status'],
                $rows->map(fn ($e) => [$e->employee_id, $e->email_id, $e->status])->all()
            );
        }
    }
}
