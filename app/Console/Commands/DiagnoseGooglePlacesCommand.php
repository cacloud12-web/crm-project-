<?php

namespace App\Console\Commands;

use App\Services\Leads\GooglePlacesApiService;
use Illuminate\Console\Command;

class DiagnoseGooglePlacesCommand extends Command
{
    protected $signature = 'google-places:diagnose {query=CA firm Mumbai : Text search query}';

    protected $description = 'Test Google Places API (New) using the configured CRM API key';

    public function handle(GooglePlacesApiService $placesApi): int
    {
        $probe = $placesApi->probe((string) $this->argument('query'));

        $this->line('Endpoint: '.($probe['endpoint'] ?? '—'));
        $this->line('API key: '.($probe['api_key_masked'] ?? 'not set').' ('.($probe['api_key_source'] ?? 'none').')');
        $this->line('HTTP status: '.($probe['http_status'] ?? '—'));
        $this->line('Google status: '.($probe['google_status'] ?? '—'));
        $this->line('Google reason: '.($probe['google_reason'] ?? '—'));
        $this->line('Message: '.($probe['message'] ?? '—'));

        if (! empty($probe['recommendation'])) {
            $this->warn('Recommendation: '.$probe['recommendation']);
        }

        if (! empty($probe['response'])) {
            $this->newLine();
            $this->line('Response JSON:');
            $this->line(json_encode($probe['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return ($probe['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
