<?php

namespace App\Services\Email;

use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

class EmailImapConnectionService
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function test(array $config): array
    {
        $config = $this->normalizeConfig($config);

        $host = (string) ($config['imap_host'] ?? '');
        $port = (int) ($config['imap_port'] ?? 993);
        $username = (string) ($config['imap_username'] ?? '');
        $password = (string) ($config['imap_password'] ?? '');

        if ($host === '' || $port <= 0 || $username === '' || $password === '') {
            return [
                'success' => false,
                'error_code' => 'missing_credentials',
                'message' => 'IMAP host, port, email address, and app password are required.',
            ];
        }

        if (! filter_var($username, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error_code' => 'invalid_username',
                'message' => 'IMAP username must be your full email address (for example you@gmail.com).',
            ];
        }

        $client = null;

        try {
            $client = $this->createClient($config);
            $client->connect();

            $inbox = $this->resolveFolder($client, 'INBOX');
            if (! $inbox) {
                $client->disconnect();

                return [
                    'success' => false,
                    'error_code' => 'inbox_not_found',
                    'message' => 'Connected to mailbox but INBOX folder was not found.',
                ];
            }

            $status = $inbox->status();
            $folders = $client->getFolders();
            $folderNames = [];
            foreach ($folders as $folder) {
                $folderNames[] = (string) $folder->name;
            }

            $client->disconnect();

            $messageCount = (int) ($status['messages'] ?? $status['exists'] ?? 0);
            $unreadCount = (int) ($status['unseen'] ?? $status['recent'] ?? 0);

            return [
                'success' => true,
                'message' => 'IMAP Connected Successfully',
                'connected_mailbox' => $host.':'.$port,
                'inbox_found' => true,
                'inbox_count' => $messageCount,
                'unread_count' => $unreadCount,
                'folders' => array_slice($folderNames, 0, 25),
                'folders_count' => count($folderNames),
            ];
        } catch (Throwable $exception) {
            if ($client) {
                try {
                    $client->disconnect();
                } catch (Throwable) {
                }
            }

            return [
                'success' => false,
                'error_code' => $this->errorCode($exception),
                'message' => $this->humanizeError($exception, $username, $host, $port),
            ];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchMessages(
        array $config,
        string $folder = 'INBOX',
        int $limit = 50,
        int $sinceUid = 0,
        ?\DateTimeInterface $sinceDate = null,
        bool $includeAttachmentContent = false,
        bool $quick = false,
    ): array {
        $config = $this->normalizeConfig($config);
        $client = null;

        try {
            $client = $this->createClient($config, $quick);
            $client->connect();

            $resolvedFolder = $this->resolveFolder($client, $folder);
            if (! $resolvedFolder) {
                $client->disconnect();

                return [];
            }

            $byUid = [];

            $mergeCollection = function ($collection) use (&$byUid): void {
                foreach ($collection as $message) {
                    if (! $message instanceof Message) {
                        continue;
                    }

                    $uid = (int) $message->getUid();
                    if ($uid > 0) {
                        $byUid[$uid] = $message;
                    }
                }
            };

            $mergeCollection(
                $resolvedFolder->messages()->fetchOrderDesc()->all()->limit($limit)->get(),
            );

            if (! $quick) {
                if ($sinceDate !== null) {
                    $mergeCollection(
                        $resolvedFolder->messages()->fetchOrderDesc()->whereSince($sinceDate)->limit($limit * 2)->get(),
                    );
                }

                if ($sinceUid > 0) {
                    $mergeCollection(
                        $resolvedFolder->messages()->fetchOrderDesc()->where('UID', ($sinceUid + 1).':*')->limit($limit)->get(),
                    );
                }
            }

            krsort($byUid);

            $messages = [];
            foreach ($byUid as $message) {
                $parsed = $this->messageToArray($message, $folder, $includeAttachmentContent);
                if ($parsed !== null) {
                    $messages[] = $parsed;
                }
            }

            usort($messages, function (array $a, array $b): int {
                $aTime = strtotime((string) ($a['received_at'] ?? '')) ?: 0;
                $bTime = strtotime((string) ($b['received_at'] ?? '')) ?: 0;
                if ($aTime !== $bTime) {
                    return $bTime <=> $aTime;
                }

                return ((int) ($b['uid'] ?? 0)) <=> ((int) ($a['uid'] ?? 0));
            });

            $client->disconnect();

            return array_slice($messages, 0, $quick ? $limit : $limit * 3);
        } catch (Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('IMAP fetch failed', [
                'folder' => $folder,
                'error' => $exception->getMessage(),
            ]);

            if ($client) {
                try {
                    $client->disconnect();
                } catch (Throwable) {
                }
            }

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function normalizeConfig(array $config): array
    {
        $fromEmail = strtolower(trim((string) ($config['from_email'] ?? '')));
        $username = trim((string) ($config['imap_username'] ?? ''));
        $password = (string) ($config['imap_password'] ?? '');
        $host = strtolower(trim((string) ($config['imap_host'] ?? '')));
        $port = (int) ($config['imap_port'] ?? 0);

        if ($password !== '') {
            $password = str_replace(' ', '', $password);
        }

        if ($fromEmail !== '' && (! str_contains($username, '@') || ! filter_var($username, FILTER_VALIDATE_EMAIL))) {
            $username = $fromEmail;
        }

        if ($fromEmail !== '' && str_ends_with($fromEmail, '@gmail.com')) {
            $host = 'imap.gmail.com';
            $port = $port > 0 ? $port : 993;
            $config['imap_encryption'] = $config['imap_encryption'] ?? 'ssl';
        }

        $config['from_email'] = $fromEmail !== '' ? $fromEmail : ($config['from_email'] ?? null);
        $config['imap_username'] = $username;
        $config['imap_password'] = $password;
        $config['imap_host'] = $host;
        $config['imap_port'] = $port > 0 ? $port : 993;

        return $config;
    }

    /**
     * @return array<int, string>
     */
    public function sentFolderCandidates(): array
    {
        return ['[Gmail]/Sent Mail', 'Sent', 'INBOX.Sent', 'Sent Items', 'Sent Messages'];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createClient(array $config, bool $quick = false): Client
    {
        $encryption = strtolower((string) ($config['imap_encryption'] ?? 'ssl'));
        $manager = new ClientManager;
        $timeout = $quick
            ? (int) config('crm_email.imap_client_timeout_seconds', 20)
            : 30;

        return $manager->make([
            'host' => (string) $config['imap_host'],
            'port' => (int) $config['imap_port'],
            'encryption' => in_array($encryption, ['tls', 'starttls'], true) ? 'tls' : 'ssl',
            'validate_cert' => true,
            'username' => (string) $config['imap_username'],
            'password' => (string) $config['imap_password'],
            'protocol' => 'imap',
            'timeout' => $timeout,
            'options' => [
                'soft_fail' => true,
            ],
            'security' => [
                'detect_spoofing' => false,
            ],
        ]);
    }

    private function resolveFolder(Client $client, string $folder): ?Folder
    {
        $candidates = [$folder];
        if (strcasecmp($folder, 'Sent') === 0) {
            $candidates = array_merge($candidates, $this->sentFolderCandidates());
        }

        foreach (array_unique($candidates) as $candidate) {
            try {
                $resolved = $client->getFolder($candidate);
                if ($resolved) {
                    return $resolved;
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function messageToArray(Message $message, string $folder, bool $includeAttachmentContent = false): ?array
    {
        try {
            $messageId = trim((string) $message->getMessageId());
            if ($messageId === '') {
                $messageId = 'uid:'.$message->getUid().'@'.$folder;
            }

            $from = $message->getFrom()->first();
            $to = $message->getTo()->first();

            $attachments = [];
            foreach ($message->getAttachments() as $attachment) {
                $size = (int) ($attachment->size ?? 0);
                $entry = [
                    'filename' => (string) ($attachment->name ?? 'attachment'),
                    'mime_type' => (string) ($attachment->content_type ?? 'application/octet-stream'),
                    'size_bytes' => $size,
                ];
                if ($includeAttachmentContent && $size > 0 && $size <= 10 * 1024 * 1024 && method_exists($attachment, 'getContent')) {
                    $entry['content'] = $attachment->getContent();
                }
                $attachments[] = $entry;
            }

            $date = $message->getDate();
            $receivedAt = null;
            if ($date) {
                if (is_object($date) && method_exists($date, 'toDateTimeString')) {
                    $receivedAt = $date->toDateTimeString();
                } elseif (is_object($date) && method_exists($date, 'toString')) {
                    $timestamp = strtotime($date->toString());
                    $receivedAt = $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
                }
            }

            $flags = array_map('strtolower', $message->getFlags()->toArray());

            return [
                'uid' => $message->getUid(),
                'message_id' => $messageId,
                'in_reply_to' => $this->attributeToString($message->getInReplyTo()),
                'references' => $this->attributeToString($message->getReferences()),
                'from_email' => strtolower(trim((string) ($from?->mail ?? ''))),
                'to_email' => strtolower(trim((string) ($to?->mail ?? ''))),
                'subject' => (string) $message->getSubject(),
                'received_at' => $receivedAt,
                'body_text' => $message->getTextBody() ?: null,
                'body_html' => $message->getHTMLBody() ?: null,
                'is_seen' => in_array('seen', $flags, true) || in_array('\\seen', $flags, true),
                'attachments' => $attachments,
                'raw_headers' => [
                    'folder' => $folder,
                    'flags' => $message->getFlags()->toArray(),
                ],
            ];
        } catch (Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('IMAP message parse failed', [
                'folder' => $folder,
                'uid' => method_exists($message, 'getUid') ? $message->getUid() : null,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function attributeToString(mixed $attribute): ?string
    {
        if ($attribute === null) {
            return null;
        }

        if (is_object($attribute) && method_exists($attribute, 'toString')) {
            $value = trim($attribute->toString());

            return $value !== '' ? $value : null;
        }

        $value = trim((string) $attribute);

        return $value !== '' ? $value : null;
    }

    private function errorCode(Throwable $exception): string
    {
        if ($exception instanceof AuthFailedException) {
            return 'authentication_failed';
        }

        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'certificate') || str_contains($message, 'ssl') || str_contains($message, 'tls')) {
            return 'ssl_certificate_error';
        }

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'network_timeout';
        }

        if (str_contains($message, 'could not connect') || str_contains($message, 'connection refused')) {
            return 'connection_failed';
        }

        if (str_contains($message, 'getaddrinfo') || str_contains($message, 'name or service not known')) {
            return 'invalid_host';
        }

        if ($exception instanceof ConnectionFailedException) {
            return 'connection_failed';
        }

        return 'imap_error';
    }

    private function humanizeError(Throwable $exception, string $username, string $host, int $port): string
    {
        $message = $exception->getMessage();
        $lower = strtolower($message);
        $isGmail = str_ends_with(strtolower($username), '@gmail.com')
            || str_contains(strtolower($host), 'gmail.com');

        if ($exception instanceof AuthFailedException
            || str_contains($lower, 'authentication failed')
            || str_contains($lower, 'invalid credentials')
            || str_contains($lower, '[auth]')) {
            if ($isGmail) {
                return 'Authentication Failed — Gmail rejected the login. Use your full Gmail address and a 16-character Google App Password (not your normal password). Enable IMAP at Gmail Settings → See all settings → Forwarding and POP/IMAP.';
            }

            return 'Authentication Failed — check your email address and app password.';
        }

        if (str_contains($lower, 'certificate') || str_contains($lower, 'ssl') || str_contains($lower, 'tls handshake')) {
            return 'SSL Certificate Error — could not verify the IMAP server certificate for '.$host.'.';
        }

        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return 'Network Timeout — could not reach '.$host.':'.$port.' within 30 seconds. Check firewall and network.';
        }

        if (str_contains($lower, 'getaddrinfo') || str_contains($lower, 'name or service not known') || str_contains($lower, 'nodename nor servname')) {
            return 'Invalid Host — "'.$host.'" could not be resolved. Check the IMAP host name.';
        }

        if (str_contains($lower, 'connection refused')) {
            return 'Invalid Port — connection refused on '.$host.':'.$port.'. For Gmail use port 993 with SSL.';
        }

        if (str_contains($lower, 'connection failed') || str_contains($lower, 'could not connect')) {
            return 'Could not connect to IMAP server at '.$host.':'.$port.'. Verify host, port, and encryption.';
        }

        if ($isGmail && str_contains($lower, 'web login required')) {
            return 'Gmail IMAP Disabled — enable IMAP in Gmail settings and use an App Password with 2-Step Verification.';
        }

        return 'IMAP connection failed: '.$message;
    }
}
