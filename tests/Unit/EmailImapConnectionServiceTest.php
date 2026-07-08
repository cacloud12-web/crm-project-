<?php

namespace Tests\Unit;

use App\Services\Email\EmailImapConnectionService;
use Tests\TestCase;

class EmailImapConnectionServiceTest extends TestCase
{
    public function test_normalize_config_fills_gmail_defaults_and_strips_password_spaces(): void
    {
        $service = new EmailImapConnectionService;

        $normalized = $service->normalizeConfig([
            'from_email' => 'user@gmail.com',
            'imap_username' => '',
            'imap_password' => 'abcd efgh ijkl mnop',
            'imap_host' => '',
            'imap_port' => null,
        ]);

        $this->assertSame('imap.gmail.com', $normalized['imap_host']);
        $this->assertSame(993, $normalized['imap_port']);
        $this->assertSame('user@gmail.com', $normalized['imap_username']);
        $this->assertSame('abcdefghijklmnop', $normalized['imap_password']);
    }

    public function test_test_requires_host_username_and_password(): void
    {
        $service = new EmailImapConnectionService;

        $result = $service->test([
            'imap_host' => '',
            'imap_username' => '',
            'imap_password' => '',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('missing_credentials', $result['error_code']);
    }
}
