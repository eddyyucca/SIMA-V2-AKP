<?php

namespace Database\Factories;

use App\Models\ITRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ITRequest>
 */
class ITRequestFactory extends Factory
{
    protected $model = ITRequest::class;

    public function definition(): array
    {
        return [
            'type' => 'ITR',
            'sequence_number' => fake()->unique()->numberBetween(1, 100000),
            'user_id' => null,
            'requester_name' => fake()->name(),
            'nik' => fake()->numerify('######'),
            'department' => 'IT',
            'position' => null,
            'recipient_name' => null,
            'recipient_phone' => null,
            'title' => fake()->sentence(3),
            'qty' => 1,
            'items' => [
                ['name' => fake()->word(), 'qty' => 1, 'spesifikasi' => '-', 'condition' => 'Baru', 'keterangan' => '-'],
            ],
            'description' => fake()->sentence(),
            'priority' => 'P3',
            'attachment' => null,
            'status' => 'Pending',
            'admin_notes' => null,
            'analisa' => null,
            'rekomendasi' => null,
            'update_note' => null,
        ];
    }

    public function type(string $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function ticketSistem(): static
    {
        return $this->state(fn () => [
            'type' => 'TICKET_SISTEM',
            'items' => null,
            'category' => 'Hardware',
            'admin_notes' => 'Ruang Server',
            'attachment' => 'attachments/dummy.png',
        ]);
    }

    public function bak(): static
    {
        return $this->state(fn () => [
            'type' => 'BAK',
            'items' => [
                ['name' => fake()->word(), 'spesifikasi' => '-', 'serial_number' => 'SN-1', 'keterangan' => '-'],
            ],
            'attachment' => 'attachments/dummy.png',
        ]);
    }

    public function suratJalan(): static
    {
        return $this->state(fn () => [
            'type' => 'SURAT_JALAN',
            'recipient_name' => fake()->name(),
            'recipient_phone' => fake()->phoneNumber(),
            'items' => [
                ['name' => fake()->word(), 'spesifikasi' => '-', 'qty' => 1, 'serial_number' => 'SN-1'],
            ],
            'attachment' => 'attachments/dummy.png',
        ]);
    }

    public function tt(): static
    {
        return $this->state(fn () => [
            'type' => 'TT',
            'items' => [
                ['name' => fake()->word(), 'spesifikasi' => '-', 'serial_number' => 'SN-1', 'condition' => 'Baru'],
            ],
        ]);
    }

    public function wo(): static
    {
        return $this->state(fn () => [
            'type' => 'WO',
            'title' => 'Work Requisition',
            'items' => [
                ['name' => fake()->word(), 'lokasi' => 'Site A', 'qty' => 1, 'keterangan' => '-'],
            ],
        ]);
    }

    // Default: peminjaman 3 hari (2 hari selisih, inklusif) - jauh di bawah ambang 7 hari, tidak butuh PDF/TTD
    public function peminjamanAset(): static
    {
        return $this->state(fn () => [
            'type' => 'PEMINJAMAN_ASET',
            'title' => 'Keperluan survey lapangan',
            'items' => [
                ['name' => 'Laptop Asus', 'spesifikasi' => 'i5/8GB', 'serial_number' => 'SN-1'],
            ],
            'loan_start_date' => now()->toDateString(),
            'loan_end_date' => now()->addDays(2)->toDateString(),
            'attachment' => null,
        ]);
    }
}
