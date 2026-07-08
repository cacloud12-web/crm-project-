<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

class CrmHtmlMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $ccRecipients
     * @param  list<string>  $bccRecipients
     */
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $htmlBody,
        public readonly ?string $fromEmail = null,
        public readonly ?string $fromName = null,
        public readonly ?string $replyToEmail = null,
        public readonly array $ccRecipients = [],
        public readonly array $bccRecipients = [],
        public readonly ?string $messageId = null,
    ) {}

    public function envelope(): Envelope
    {
        $envelope = new Envelope(
            subject: $this->mailSubject,
        );

        if ($this->fromEmail) {
            $envelope = $envelope->from(new Address($this->fromEmail, (string) ($this->fromName ?? '')));
        }

        if ($this->replyToEmail) {
            $envelope = $envelope->replyTo($this->replyToEmail);
        }

        if ($this->ccRecipients !== []) {
            $envelope = $envelope->cc($this->ccRecipients);
        }

        if ($this->bccRecipients !== []) {
            $envelope = $envelope->bcc($this->bccRecipients);
        }

        if ($this->messageId) {
            $messageId = $this->messageId;
            $envelope = $envelope->using(function (Email $email) use ($messageId) {
                $email->getHeaders()->addIdHeader('Message-ID', $messageId);
            });
        }

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlBody,
        );
    }
}
