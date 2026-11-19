@forelse ($pendingApprovals as $item)
    @php
        // Dihitung ulang tiap baris (variabel loop tidak boleh terbawa ke baris berikutnya).
        $bakIncomplete = $item->type === 'BAK'
            && (trim((string) $item->analisa) === '' || trim((string) $item->rekomendasi) === '');
    @endphp
    <tr class="hover:bg-slate-800/50 transition-colors" data-request-id="{{ $item->id }}">
        <td data-label="Nama / Dept" class="py-3 px-4">
            <p class="font-bold text-white">{{ $item->requester_name ?? ($item->user->name ?? 'N/A') }}</p>
            <p class="text-slate-500">{{ $item->department ?? ($item->user->department ?? '-') }}</p>
            <p class="text-slate-600 text-[10px]">{{ optional($item->user)->username ?? '-' }}</p>
        </td>
        <td data-label="Tipe / Judul" class="py-3 px-4">
            <p class="font-mono text-fuchsia-400 font-bold">
                [{{ $item->type === 'TICKET_SISTEM' ? 'TICKET' : ($item->type ?? 'ITR') }}]
            </p>
            <p class="text-slate-300">{{ $item->title }}</p>
        </td>
        <td data-label="Jam Masuk" class="py-3 px-4 text-slate-400 whitespace-nowrap">
            {{ $item->created_at->format('d M, H:i') }}
        </td>
        <td data-label="Approval" class="py-3 px-4">
            <div class="flex flex-wrap items-center justify-end gap-2 min-w-[250px]">
                <!-- Tombol View: ITR & WO buka form yang bisa diedit, tipe lain pakai modal detail.
                     Wajib diklik dulu sebelum Keterangan/Reject/Approve terbuka (lihat markViewed() +
                     applyViewGate() di JS) - supaya Tim IT tidak asal approve/reject tanpa lihat detail. -->
                @if (in_array($item->type, ['ITR', 'WO']))
                    <button type="button" onclick='markViewed({{ $item->id }}); openEditModal(@json($item))' class="px-3 py-1.5 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-[11px] font-bold transition">
                        <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.04 12.32a1 1 0 0 1 0-.64C3.42 7.51 7.36 4.5 12 4.5c4.64 0 8.57 3.01 9.96 7.18.07.21.07.43 0 .64C20.58 16.49 16.64 19.5 12 19.5c-4.64 0-8.57-3.01-9.96-7.18ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg> View
                    </button>
                @else
                    <button type="button" onclick='markViewed({{ $item->id }}); openDetailModal(@json($item))' class="px-3 py-1.5 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-[11px] font-bold transition">
                        <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.04 12.32a1 1 0 0 1 0-.64C3.42 7.51 7.36 4.5 12 4.5c4.64 0 8.57 3.01 9.96 7.18.07.21.07.43 0 .64C20.58 16.49 16.64 19.5 12 19.5c-4.64 0-8.57-3.01-9.96-7.18ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg> View
                    </button>
                @endif
                @if ($canManage)
                    <a href="{{ \App\Filament\Pages\ProcessRequest::urlFor($item) }}" title="Cocokkan dengan data IT Asset (karyawan & barang)" class="px-3 py-1.5 rounded-lg bg-teal-600/80 hover:bg-teal-500 text-white text-[11px] font-bold transition"><svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.69a4.5 4.5 0 0 1 1.24 7.24l-4.5 4.5a4.5 4.5 0 0 1-6.36-6.36l1.76-1.76m13.35-.62 1.76-1.76a4.5 4.5 0 0 0-6.36-6.36l-4.5 4.5a4.5 4.5 0 0 0 1.24 7.24"/></svg> Proses IT</a>
                    @if (in_array($item->type, ['BAK', 'PEMINJAMAN_ASET'], true))
                        <!-- Tombol Edit: ubah isi form yang sudah diisi user (untuk Peminjaman Aset,
                             ini juga dipakai IT untuk melengkapi Spesifikasi & Serial Number aset) -->
                        <button type="button" onclick='openEditModal(@json($item))' class="px-3 py-1.5 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-[11px] font-bold transition">
                            <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.86 4.49 1.69-1.69a1.87 1.87 0 1 1 2.65 2.65L6.83 19.82a4.5 4.5 0 0 1-1.9 1.13L2.25 21.75l.8-2.69a4.5 4.5 0 0 1 1.13-1.9L16.86 4.49Zm0 0 2.65 2.65"/></svg> Edit
                        </button>
                    @endif
                    <!-- Tombol Keterangan: tulis catatan untuk user (misal alasan sebelum menolak) -->
                    <button type="button" onclick="openNoteModal({{ json_encode(route('requests.update-note', $item->id)) }}, {{ json_encode($item->update_note) }})" class="view-gated px-3 py-1.5 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-[11px] font-bold transition disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-slate-700" title="Klik View dulu sebelum bisa Keterangan" data-locked-title="Klik View dulu sebelum bisa Keterangan" data-ready-title="Tulis keterangan untuk pemohon" disabled>
                        <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.86 4.49 1.69-1.69a1.87 1.87 0 1 1 2.65 2.65L6.83 19.82a4.5 4.5 0 0 1-1.9 1.13L2.25 21.75l.8-2.69a4.5 4.5 0 0 1 1.13-1.9L16.86 4.49Zm0 0 2.65 2.65"/></svg> Keterangan
                    </button>
                    <!-- Tombol Reject: sesuaikan status "Rejected" dengan enum status di database -->
                    <form action="{{ route('requests.update-status', $item->id) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="Rejected">
                        <button type="submit" class="view-gated px-3 py-1.5 rounded-lg bg-rose-500/90 hover:bg-rose-500 text-white text-[11px] font-bold transition disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-rose-500/90" title="Klik View dulu sebelum bisa Reject" data-locked-title="Klik View dulu sebelum bisa Reject" data-ready-title="Tolak request ini" disabled>
                            ✕ Reject
                        </button>
                    </form>
                    @if ($bakIncomplete)
                        <!-- BAK wajib Analisa & Rekomendasi (diisi tim IT lewat tombol Edit) sebelum bisa di-approve.
                             Sengaja BUKAN .view-gated supaya applyViewGate() tidak menyalakannya lagi setelah View. -->
                        <button type="button" disabled class="btn-approve px-3 py-1.5 rounded-lg bg-emerald-500/90 text-white text-[11px] font-bold cursor-not-allowed" title="Isi Analisa & Rekomendasi dulu lewat tombol Edit">
                            ✓ Approve
                        </button>
                    @else
                        <!-- Tombol Approve: set status ke In Progress -->
                        <form action="{{ route('requests.update-status', $item->id) }}" method="POST">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="In Progress">
                            <button type="submit" class="btn-approve view-gated px-3 py-1.5 rounded-lg bg-emerald-500/90 hover:bg-emerald-500 text-white text-[11px] font-bold transition disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-emerald-500/90" title="Klik View dulu sebelum bisa Approve" data-locked-title="Klik View dulu sebelum bisa Approve" data-ready-title="Setujui request ini (status menjadi In Progress)" disabled>
                                ✓ Approve
                            </button>
                        </form>
                    @endif
                @endif
            </div>
            @if ($canManage && $bakIncomplete)
                <p class="mt-1 text-[10px] text-amber-400 text-right">Isi Analisa &amp; Rekomendasi lewat <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.86 4.49 1.69-1.69a1.87 1.87 0 1 1 2.65 2.65L6.83 19.82a4.5 4.5 0 0 1-1.9 1.13L2.25 21.75l.8-2.69a4.5 4.5 0 0 1 1.13-1.9L16.86 4.49Zm0 0 2.65 2.65"/></svg> Edit dulu sebelum Approve.</p>
            @endif
        </td>
    </tr>
@empty
    <tr>
        <td colspan="4" class="py-6 text-center text-slate-500 italic">
            Tidak ada request yang menunggu approval.
        </td>
    </tr>
@endforelse
