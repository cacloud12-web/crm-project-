<?php

namespace App\Models;

class SmsLogStatus
{
    public const PENDING = 'Pending';

    public const SENT = 'Sent';

    public const FAILED = 'Failed';

    public const API_ERROR = 'API Error';

    public const MAPPED = 'Mapped';

    public const QUEUED = 'Queued';

    public const DELIVERED = 'Delivered';

    public const SKIPPED = 'Skipped';
}
