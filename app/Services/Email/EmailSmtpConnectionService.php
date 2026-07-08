<?php

namespace App\Services\Email;

use App\Models\EmailSetting;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

class EmailSmtpConnectionService
{
    /**
     * @param  array<string, mixed>  $config
     * @return array{success: bool, message: string}
     */
    public function test(array $config): array
    {
        $config = $this->normalizeConfig($config);

        $host = (string) ($config['smtp_host'] ?? '');
        $port = (int) ($config['smtp_port'] ?? 0);
        $username = (string) ($config['smtp_username'] ?? '');
        $password = (string) ($config['smtp_password'] ?? '');
        $encryption = strtolower((string) ($config['smtp_encryption'] ?? 'tls'));

        if ($host === '' || $port <= 0 || $username === '' || $password === '') {
            return [
                'success' => false,
                'message' => 'SMTP host, port, email address, and app password are required.',
            ];
        }

        if (! filter_var($username, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'SMTP username must be your full email address (for example you@gmail.com), not a display label.',
            ];
        }

        $useTls = $encryption === 'ssl' || $port === 465;

        try {
            $transport = new EsmtpTransport($host, $port, $useTls);
            $transport->setUsername($username);
            $transport->setPassword($password);
            $transport->start();
            $transport->stop();

            return [
                'success' => true,
                'message' => 'SMTP connection and authentication successful.',
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'message' => $this->humanizeError($exception, $username, $host),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function normalizeConfig(array $config): array
    {
        $fromEmail = strtolower(trim((string) ($config['from_email'] ?? '')));
        $username = trim((string) ($config['smtp_username'] ?? ''));
        $password = (string) ($config['smtp_password'] ?? '');
        $host = strtolower(trim((string) ($config['smtp_host'] ?? '')));

        if ($password !== '') {
            $password = str_replace(' ', '', $password);
        }

        if ($fromEmail !== '' && (! str_contains($username, '@') || ! filter_var($username, FILTER_VALIDATE_EMAIL))) {
            $username = $fromEmail;
        }

        if ($fromEmail !== '' && str_ends_with($fromEmail, '@gmail.com')) {
            $host = 'smtp.gmail.com';
            $config['smtp_port'] = (int) ($config['smtp_port'] ?? 0) ?: 587;
            $config['smtp_encryption'] = $config['smtp_encryption'] ?? 'tls';
        }

        $config['from_email'] = $fromEmail !== '' ? $fromEmail : ($config['from_email'] ?? null);
        $config['smtp_username'] = $username;
        $config['smtp_password'] = $password;
        $config['smtp_host'] = $host;

        return $config;
    }

    public function testSetting(EmailSetting $settings): array
    {
        return $this->test([
            'smtp_host' => $settings->smtp_host,
            'smtp_port' => $settings->smtp_port,
            'smtp_username' => $settings->smtp_username,
            'smtp_password' => $settings->smtp_password,
            'smtp_encryption' => $settings->smtp_encryption,
        ]);
    }

    private function humanizeError(Throwable $exception, string $username = '', string $host = ''): string
    {
        $message = $exception->getMessage();
        $lower = strtolower($message);
        $isGmail = str_ends_with(strtolower($username), '@gmail.com')
            || str_contains(strtolower($host), 'gmail.com');

        if (str_contains($lower, 'authentication') || str_contains($lower, 'authenticate') || str_contains($message, '535')) {
            if ($isGmail) {
                return 'Gmail rejected the login. Use your full Gmail address as the email field and a 16-character Google App Password (not your normal Gmail password). Enable 2-Step Verification first, then create an App Password at myaccount.google.com/apppasswords.';
            }

            return 'SMTP authentication failed. Use your full email address as the username and the correct mailbox or app password.';
        }

        if (str_contains($lower, 'connection') || str_contains($lower, 'could not connect')) {
            return 'Could not connect to SMTP server. Check host, port, and encryption.';
        }

        return 'SMTP connection failed: '.$message;
    }
}
