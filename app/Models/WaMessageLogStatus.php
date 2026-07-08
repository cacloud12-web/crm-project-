<?php

namespace App\Models;

final class WaMessageLogStatus
{
    public const PAYLOAD_GENERATED = 'Payload Generated';

    public const PENDING = 'Pending';

    public const QUEUED = 'Queued';

    public const SKIPPED = 'Skipped';

    public const SENT = 'Sent';

    public const DELIVERED = 'Delivered';

    public const READ = 'Read';

    public const FAILED = 'Failed';

    public const API_ERROR = 'API Error';
}
