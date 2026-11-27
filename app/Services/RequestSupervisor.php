<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ITRequest;
use App\Models\User;
use App\Notifications\NewRequestSubmitted;
use App\Notifications\RequestStatusUpdated;
use Illuminate\Support\Collection;

/**
 * Persetujuan atasan untuk request ITERA. Atasan = karyawan HRIS yang NIK-nya tercantum di supervisor_nik pemohon dan punya akun aktif.
 * Hanya ITR dan Peminjaman Aset yang lewat atasan, dan hanya bila yang mengajukan adalah akun peran User (bukan Tim IT).
 * Tanpa atasan (atau atasan tak punya akun) request langsung ke Tim IT. Request yang menggantung diteruskan otomatis (lihat escalate()).
 */
class RequestSupervisor
{
    public const ESCALATE_AFTER_DAYS = 2;

    /** Atasan langsung pemohon yang bisa login, atau null. */
    public function supervisorOf(?Employee $e): ?Employee
    {
        if (! $e || blank($e->supervisor_nik)) return null;
        $s = Employee::with('user')->where('nik', $e->supervisor_nik)->where('status', 'active')->whereNotNull('user_id')->first();

        return $s && $s->id !== $e->id && $s->user?->is_active ? $s : null;
    }

    /** Anak buah langsung (aktif) dari karyawan ini. */
    public function subordinates(?Employee $e): Collection
    {
        if (! $e || blank($e->nik)) return collect();

        return Employee::with('department')->where('supervisor_nik', $e->nik)->where('status', 'active')->where('id', '!=', $e->id)->orderBy('name')->get();
    }

    /** Karyawan yang boleh dipilih akun peran User saat mengajukan: dirinya + anak buahnya. Null = tidak dibatasi (akun belum tertaut karyawan). */
    public function allowedRequesters(User $u): ?Collection
    {
        if ($u->role !== 'user' || ! $u->employee) return null;

        return collect([$u->employee])->merge($this->subordinates($u->employee));
    }

    /** Atasan yang harus menyetujui lebih dulu, atau null bila request boleh langsung ke Tim IT. */
    public function approverFor(string $type, ?Employee $requester, User $submitter): ?Employee
    {
        if (! in_array($type, ITRequest::SUPERVISOR_TYPES, true) || $submitter->role !== 'user') return null;
        $s = $this->supervisorOf($requester);
        if (! $s || $s->user_id === $submitter->id) return null;     // atasan yang mengajukan sendiri tidak minta persetujuan dirinya

        return $s;
    }

    /** Tandai request menunggu atasan dan kabari atasannya. */
    public function ask(ITRequest $item, Employee $supervisor): void
    {
        $item->forceFill([
            'status' => ITRequest::AWAITING_SUPERVISOR, 'supervisor_employee_id' => $supervisor->id, 'supervisor_asked_at' => now(),
            'supervisor_decided_at' => null, 'supervisor_decision' => null, 'supervisor_note' => null,
        ])->save();
        $this->inApp($supervisor->user, 'Persetujuan atasan diperlukan', ($item->requester_name ?: 'Karyawan') . ' mengajukan ' . $this->label($item) . ': ' . ($item->title ?: '-'), 'warning', $item);
    }

    /** Atasan menyetujui (true) atau menolak (false). */
    public function decide(ITRequest $item, User $by, bool $approve, ?string $note): void
    {
        $item->forceFill([
            'supervisor_decided_at' => now(), 'supervisor_decision' => $approve ? 'approved' : 'rejected', 'supervisor_note' => $note,
            'status' => $approve ? 'Pending' : 'Rejected',
        ]);
        if (! $approve) $item->update_note = trim('Ditolak atasan (' . $by->name . ')' . ($note ? ': ' . $note : ''));
        $item->save();

        if ($approve) $this->forwardToIt($item);
        if ($item->user) {
            $item->user->notify(new RequestStatusUpdated($item));
            $this->inApp($item->user, $approve ? 'Atasan menyetujui request Anda' : 'Atasan menolak request Anda',
                $this->label($item) . ': ' . ($item->title ?: '-') . ($approve ? ' — kini diproses Tim IT.' : ($note ? ' — ' . $note : '') . ' Perbaiki lalu kirim ulang.'), $approve ? 'success' : 'danger', $item);
        }
    }

    /** Kabari Tim IT (push + Telegram + notifikasi dalam aplikasi) bahwa request siap diproses. */
    public function forwardToIt(ITRequest $item): void
    {
        User::requisitionAdmins()->get()->each(fn ($it) => $it->notify(new NewRequestSubmitted($item)));
        \App\Services\TelegramNotifier::notifyNewRequest($item);
        app(NotificationService::class)->notify(['Admin', 'Staff IT'], 'Request baru perlu diproses', ($item->requester_name ?: 'Karyawan') . ' — ' . $this->label($item) . ': ' . ($item->title ?: '-'), 'info');
    }

    /** Request yang menunggu atasan lebih dari ESCALATE_AFTER_DAYS diteruskan ke Tim IT agar tidak menggantung. */
    public function escalate(): int
    {
        $n = 0;
        ITRequest::where('status', ITRequest::AWAITING_SUPERVISOR)->where('supervisor_asked_at', '<=', now()->subDays(self::ESCALATE_AFTER_DAYS))->get()->each(function (ITRequest $item) use (&$n) {
            $item->forceFill(['status' => 'Pending', 'supervisor_decision' => 'escalated', 'supervisor_decided_at' => now(),
                'supervisor_note' => 'Diteruskan otomatis ke Tim IT: atasan belum merespons ' . self::ESCALATE_AFTER_DAYS . ' hari.'])->save();
            $this->forwardToIt($item);
            if ($item->user) $this->inApp($item->user, 'Request Anda diteruskan ke Tim IT', $this->label($item) . ': atasan belum merespons, kini diproses Tim IT.', 'info', $item);
            $n++;
        });

        return $n;
    }

    public function label(ITRequest $item): string
    {
        return ['ITR' => 'IT Request', 'PEMINJAMAN_ASET' => 'Peminjaman Aset', 'TICKET_SISTEM' => 'Ticket Sistem', 'BAK' => 'BAK', 'WO' => 'Work Order'][$item->type] ?? (string) $item->type;
    }

    private function inApp(?User $to, string $title, string $body, string $status, ITRequest $item): void
    {
        if (! $to) return;
        \Filament\Notifications\Notification::make()->title($title)->body($body)->status($status)->sendToDatabase($to);
    }
}
