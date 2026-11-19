<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Email selamat datang untuk karyawan yang diberi akses login: berisi tautan untuk mengatur password sendiri. */
class WelcomeAccountMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $resetUrl, public string $loginUrl, public int $expireMinutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Selamat datang di ' . \App\Support\Branding::NAME);
    }

    public function content(): Content
    {
        $app = e(\App\Support\Branding::NAME);
        $hours = $this->expireMinutes >= 120 ? round($this->expireMinutes / 60) . ' jam' : $this->expireMinutes . ' menit';
        return new Content(htmlString:
            '<p>Halo <b>' . e($this->user->name) . '</b>,</p>'
            . "<p>Akun Anda untuk <b>{$app}</b> sudah dibuat oleh tim IT. Email login Anda: <b>" . e($this->user->email) . '</b>.</p>'
            . '<p>Silakan atur password Anda lewat tautan berikut (berlaku ' . $hours . '):</p>'
            . '<p><a href="' . e($this->resetUrl) . '" style="display:inline-block;padding:10px 18px;background:#1f78b0;color:#fff;border-radius:8px;text-decoration:none;font-weight:bold">Atur password</a></p>'
            . '<p style="font-size:12px;color:#666">Jika tombol tidak berfungsi, salin alamat ini ke browser:<br>' . e($this->resetUrl) . '</p>'
            . '<p>Setelah itu masuk di <a href="' . e($this->loginUrl) . '">' . e($this->loginUrl) . '</a>.</p>'
            . '<p style="font-size:12px;color:#666">Jika tautan sudah kedaluwarsa, minta tim IT mengirim ulang, atau gunakan "Lupa password" di halaman login. Abaikan email ini jika Anda tidak merasa memintanya.</p>'
        );
    }
}
