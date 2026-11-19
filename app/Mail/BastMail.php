<?php

namespace App\Mail;

use App\Models\AssetAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BastMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public AssetAssignment $assignment) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'BAST ' . $this->assignment->bast_number);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Terlampir BAST nomor <b>' . e($this->assignment->bast_number) . '</b> untuk aset ' . e($this->assignment->asset->asset_tag) . '.</p><p style="color:#888;font-size:12px">' . e(\App\Support\Branding::NAME) . '</p>');
    }

    public function attachments(): array
    {
        return $this->assignment->bast_pdf_path
            ? [Attachment::fromStorageDisk('local', $this->assignment->bast_pdf_path)->as(str_replace('/', '-', $this->assignment->bast_number) . '.pdf')]
            : [];
    }
}
