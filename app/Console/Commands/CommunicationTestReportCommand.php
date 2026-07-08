<?php

namespace App\Console\Commands;

use App\Services\Communication\CommunicationChannelTestReportService;
use Illuminate\Console\Command;

class CommunicationTestReportCommand extends Command
{
    protected $signature = 'communication:test-report
                            {--live : Send a live WhatsApp test message (requires full Meta configuration)}
                            {--json : Output raw JSON instead of formatted text}';

    protected $description = 'Generate configuration and API payload report for WhatsApp, Email, and SMS channels';

    public function handle(CommunicationChannelTestReportService $reportService): int
    {
        $report = $reportService->generate((bool) $this->option('live'));

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Communication Channel Test Report');
        $this->line('Generated: '.$report['generated_at']);
        $this->newLine();

        foreach (['whatsapp' => 'WhatsApp', 'email' => 'Email', 'sms' => 'SMS'] as $key => $label) {
            $section = $report[$key];
            $this->components->twoColumnDetail(
                $label.' — Configuration',
                $this->statusIcon($section['configuration_status'] ?? 'unknown').' '.($section['configuration_status'] ?? 'unknown'),
            );
            if (! empty($section['api_request'])) {
                $this->line('  API Request:');
                $this->line('  '.json_encode($section['api_request'], JSON_UNESCAPED_SLASHES));
            }
            if (! empty($section['api_response'])) {
                $this->line('  API Response:');
                $this->line('  '.json_encode($section['api_response'], JSON_UNESCAPED_SLASHES));
            }
            $this->components->twoColumnDetail(
                '  Delivery Status',
                $section['delivery_status'] ?? 'unknown',
            );
            if (! empty($section['error_message'])) {
                $this->components->twoColumnDetail('  Error', $section['error_message']);
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function statusIcon(string $status): string
    {
        return match ($status) {
            'ok', 'integrated', 'ready', 'connected' => '✓',
            default => '✗',
        };
    }
}
