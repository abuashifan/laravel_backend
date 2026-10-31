<?php

namespace App\Mail;

use App\Shared\Models\CompanyInvitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CompanyInvitationMail extends Mailable
{
    public function __construct(
        public readonly CompanyInvitation $invitation,
        public readonly string $companyName,
        public readonly ?string $inviterName,
    ) {}

    public static function acceptUrl(CompanyInvitation $invitation): string
    {
        return config('app.frontend_url').'/invitations/'.$invitation->token.'/accept';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Undangan bergabung ke '.$this->companyName.' di Finlite');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.company-invitation', with: [
            'companyName' => $this->companyName,
            'inviterName' => $this->inviterName,
            'acceptUrl' => self::acceptUrl($this->invitation),
            'expiresAt' => $this->invitation->expires_at?->timezone(config('app.timezone'))->format('d-m-Y H:i'),
        ]);
    }
}
