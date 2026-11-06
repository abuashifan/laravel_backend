<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Verifikasi email pendaftaran Finlite Kasir; tautannya ke aplikasi kasir. */
class PosVerifyEmailMail extends Mailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $verifyUrl,
        public readonly int $validHours,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verifikasi email Finlite Kasir');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.pos-verify-email', with: [
            'name' => $this->name,
            'verifyUrl' => $this->verifyUrl,
            'validHours' => $this->validHours,
        ]);
    }
}
