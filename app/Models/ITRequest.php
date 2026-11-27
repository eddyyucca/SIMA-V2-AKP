<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ITRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'sequence_number',
        'user_id',
        'employee_id',
        'requester_name',
        'nik',
        'department',
        'position',
        'recipient_name',
        'recipient_phone',
        'title',
        'category',
        'loan_start_date',
        'loan_end_date',
        'returned_at',
        'pre_due_reminder_sent_at',
        'due_reminder_sent_at',
        'last_overdue_reminder_at',
        'qty',
        'items',
        'description',
        'attachment',
        'status',
        'approved_by',
        'approved_at',
        'completed_at',
        'admin_notes',
        'analisa',
        'rekomendasi',
        'update_note',
        'document_path',
        'document_name',
        'numbered_at',
    ];

    protected $casts = [
        'items' => 'array',
        'loan_start_date' => 'datetime',
        'loan_end_date' => 'datetime',
        'returned_at' => 'datetime',
        'pre_due_reminder_sent_at' => 'datetime',
        'due_reminder_sent_at' => 'datetime',
        'last_overdue_reminder_at' => 'datetime',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
        'numbered_at' => 'datetime',
    ];

    protected $appends = [
        'ticket_number',
        'loan_duration_days',
        'loan_duration_label',
        'processing_duration_label',
    ];

    /** BAST serah terima accessories yang dibuat dari request ini (ITR). */
    public function accessoryHandovers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AccessoryHandover::class, 'request_id');
    }

    /** Request baru yang menunggu keputusan Tim IT (panel "Incoming Requests"): Pending dan belum pernah bernomor. */
    public function scopeIncoming($q)
    {
        return $q->where('status', 'Pending')->whereNull('sequence_number')->whereIn('type', ['ITR', 'BAK', 'TICKET_SISTEM', 'WO', 'PEMINJAMAN_ASET']);
    }

    /** Request milik satu akun yang masih berjalan (belum Completed / Rejected). */
    public function scopeOpenFor($q, int $userId)
    {
        return $q->where('user_id', $userId)->whereIn('status', ['Pending', 'Approved', 'In Progress']);
    }


    /** Karyawan di IT Asset yang dicocokkan tim IT dengan pemohon request ini. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** Barang database yang dicocokkan tim IT. */
    public function links(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RequestItemLink::class, 'request_id')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ITR/TICKET_SISTEM/WO/BAK/PEMINJAMAN_ASET baru punya sequence_number setelah di-approve tim IT
    // (lihat RequestController::updateStatus()) — selama masih Pending/Rejected belum ada nomor tiket
    // sama sekali, supaya nomor tidak "kepakai" untuk request yang belum tentu jadi diproses.
    public function getTicketNumberAttribute(): ?string
    {
        if ($this->sequence_number === null) {
            return null;
        }

        $typeCode = match ($this->type) {
            'BAK' => 'BAK',
            'TT' => 'TT',   // jenis lama (sudah dihapus dari form), nomor lama tetap terbaca
            'SURAT_JALAN' => 'SJ',
            'TICKET_SISTEM' => 'TS',
            'WO' => 'WO',
            'PEMINJAMAN_ASET' => 'PJ',
            default => 'ITR',
        };

        // Bulan kode = bulan nomor diterbitkan (approval). Baris lama (numbered_at NULL) tetap pakai created_at.
        $yearMonth = ($this->numbered_at ?? $this->created_at)->format('ym');

        return sprintf('AKP-%s%s%03d', $typeCode, $yearMonth, $this->sequence_number);
    }

    // Hitungan hari inklusif (mis. pinjam & kembali di hari yang sama = 1 hari) — dipakai untuk ambang
    // 7 hari (PDF + kolom TTD User & IT Team) sekaligus untuk tampilan durasi ke user.
    public function getLoanDurationDaysAttribute(): ?int
    {
        if (! $this->loan_start_date || ! $this->loan_end_date) {
            return null;
        }

        return (int) $this->loan_start_date->diffInDays($this->loan_end_date) + 1;
    }

    // "X hari" — dipakai untuk semua tampilan durasi ke user (list, detail, PDF).
    public function getLoanDurationLabelAttribute(): ?string
    {
        if ($this->loan_duration_days === null) {
            return null;
        }

        return "{$this->loan_duration_days} hari";
    }

    // Durasi pengerjaan tim IT: dari saat pertama di-approve (approved_at) sampai Completed
    // (atau sampai sekarang kalau masih berjalan). Null selama masih Pending/belum pernah diproses.
    public function getProcessingDurationLabelAttribute(): ?string
    {
        if (! $this->approved_at) {
            return null;
        }

        $end = $this->completed_at ?? now();

        return $this->approved_at->diffForHumans($end, [
            'parts' => 2,
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
        ]);
    }

    // Aset dianggap terlambat begitu melewati Tanggal Kembali, belum ditandai "Sudah Kembali", dan
    // pinjamannya masih aktif (Approved/In Progress). loan_end_date dibandingkan presisi menit (bukan
    // dibulatkan ke hari) supaya jadwal reminder H-30 menit/siklus 6 jam akurat, meski form cuma
    // menampilkan tanggal (jam-nya selalu 00:00). Dipakai untuk badge di list & reminder (lihat
    // App\Console\Commands\SendPeminjamanReminders, jalan tiap 30 menit).
    public function getIsOverdueAttribute(): bool
    {
        return $this->type === 'PEMINJAMAN_ASET'
            && $this->returned_at === null
            && in_array($this->status, ['Approved', 'In Progress'], true)
            && $this->loan_end_date !== null
            && $this->loan_end_date->lt(now());
    }

    // "5 jam", "2 hari", dst — dipakai untuk badge "Terlambat ..." & isi reminder ke tim IT.
    // $minutes harus >= 0 (durasi absolut, bukan selisih bisa negatif).
    public static function formatMinutesAsDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return max($minutes, 0) . ' menit';
        }

        if ($minutes < 60 * 24) {
            return intdiv($minutes, 60) . ' jam';
        }

        return ((int) ceil($minutes / (60 * 24))) . ' hari';
    }
}
