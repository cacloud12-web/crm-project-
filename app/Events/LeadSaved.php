<?php

namespace App\Events;

use App\Models\CaMaster;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LeadSaved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CaMaster $lead,
        public readonly bool $wasRecentlyCreated,
        public readonly ?User $actor = null,
    ) {}
}
