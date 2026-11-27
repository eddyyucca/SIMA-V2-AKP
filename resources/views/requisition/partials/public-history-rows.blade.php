@forelse ($requests as $item)
    <tr class="public-request-row hover:bg-slate-800/50 transition-colors">
        <td data-label="No. Ticket" class="py-3 px-4 ticket-no font-mono font-bold whitespace-nowrap">
            @if (auth()->user()->canManageRequests() || $item->user_id === auth()->id())
                <button type="button" onclick='openMyFormModal(@json($item))' title="Klik untuk melihat form yang sudah diisi" class="hover:underline hover:text-fuchsia-300 focus:outline-none text-left">{{ $item->ticket_number ?? 'Menunggu Approval' }}</button>
            @else
                {{ $item->ticket_number ?? 'Menunggu Approval' }}
            @endif
        </td>
        <td data-label="Requester" class="py-3 px-4 font-medium text-white">{{ $item->requester_name ?? 'N/A' }}</td>
        <td data-label="Judul Request" class="py-3 px-4 text-slate-300">{{ $item->title }}</td>
        @php
            // "Menunggu Approval" cuma buat Pending yang BENAR-BENAR baru (belum pernah dapat nomor
            // tiket) - itu yang masih ada di panel "Incoming Requests", bukan di List All Form. Kalau
            // sequence_number sudah terisi (pernah diproses lalu direvert balik ke Pending, tampil di
            // List All Form), labelnya cukup "Pending" polos - sama seperti yang ditampilkan di List All
            // Form sendiri - supaya tidak seolah-olah masih menunggu approval pertama kali.
            $isNeverProcessedPending = $item->status === 'Pending' && $item->sequence_number === null;
        @endphp
        <td data-label="Status" class="py-3 px-4 whitespace-nowrap">
            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border whitespace-nowrap
                @if($item->status == 'Completed') bg-emerald-500/20 text-emerald-400 border-emerald-500/30
                @elseif($item->status == 'In Progress') bg-blue-500/20 text-blue-400 border-blue-500/30
                @elseif($item->status == 'Approved') bg-teal-500/20 text-teal-400 border-teal-500/30
                @elseif($item->status == 'Rejected') bg-rose-500/20 text-rose-400 border-rose-500/30
                @else bg-amber-500/20 text-amber-400 border-amber-500/30 @endif">
                {{ $isNeverProcessedPending ? 'Menunggu Approval' : $item->status }}
            </span>
        </td>
        <td data-label="Diproses Oleh" class="py-3 px-4 text-slate-400">{{ $item->approvedBy->name ?? '-' }}</td>
        <td data-label="Tanggal" class="py-3 px-4 text-slate-400">{{ $item->created_at->format('d M Y, H:i') }}</td>
        <td data-label="Aksi" class="py-3 px-4 text-center">
            @php
                $isEditableType = in_array($item->type, ['ITR', 'BAK', 'WO', 'TICKET_SISTEM', 'PEMINJAMAN_ASET'], true);
                $isOwnerOrManager = auth()->user()->canManageRequests() || $item->user_id === auth()->id();
                // Edit di sini = kirim ulang (resubmit) - cuma boleh PEMILIK request sendiri, bukan Tim
                // IT, supaya IT tidak bisa "mengirim ulang" request atas nama user (lihat juga
                // RequestController::update() - resubmit di backend sudah dikunci pemilik saja).
                $showEdit = $isEditableType && $item->status === 'Rejected' && $item->user_id === auth()->id();
                // Pending & Rejected belum/tidak punya nomor tiket resmi, jadi tombol PDF sengaja tidak
                // ditampilkan supaya tidak berujung error 403 (Rejected juga baru bisa dilihat lagi
                // setelah pemilik resubmit dan disetujui ulang). Peminjaman Aset juga cuma punya PDF
                // kalau durasinya >= 7 hari (lihat downloadPdf()).
                $canViewPdf = $item->type !== 'TICKET_SISTEM' && ! $showEdit && $isOwnerOrManager
                    && ! in_array($item->status, ['Pending', 'Rejected'], true)
                    && ($item->type !== 'PEMINJAMAN_ASET' || (int) $item->loan_duration_days >= 7);
                // Hapus tersedia untuk semua tipe form (termasuk Surat Jalan & TT yang tidak bisa diedit).
                // Pemilik request cuma boleh hapus miliknya sendiri yang sudah Rejected (sama seperti
                // destroy() di RequestController); Super Admin boleh hapus status apa pun, sama seperti
                // tombol Hapus di List All Form & list Peminjaman Aset - supaya konsisten di semua tempat.
                $showDelete = (auth()->user()->isSuperAdmin())
                    || ($item->status === 'Rejected' && $item->user_id === auth()->id());
            @endphp
            <div class="grid grid-cols-2 auto-rows-[32px] gap-1.5 w-[170px] mx-auto">
                @if ($showEdit)
                    <button type="button" onclick='openEditRequestModal(@json($item))' class="inline-flex items-center justify-center w-full whitespace-nowrap px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-amber-600 text-slate-300 hover:text-white text-[11px] font-bold transition">
                        <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.86 4.49 1.69-1.69a1.87 1.87 0 1 1 2.65 2.65L6.83 19.82a4.5 4.5 0 0 1-1.9 1.13L2.25 21.75l.8-2.69a4.5 4.5 0 0 1 1.13-1.9L16.86 4.49Zm0 0 2.65 2.65"/></svg> Edit
                    </button>
                @elseif ($canViewPdf)
                    <button type="button" onclick="openPdfModal('{{ route('requests.pdf', $item->id) }}')" class="inline-flex items-center justify-center w-full whitespace-nowrap px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-fuchsia-600 text-slate-300 hover:text-white text-[11px] font-bold transition">
                        <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.63a3.38 3.38 0 0 0-3.38-3.37h-1.5A1.13 1.13 0 0 1 13.5 7.13v-1.5a3.38 3.38 0 0 0-3.38-3.38H8.25m2.25 0H5.63c-.62 0-1.13.5-1.13 1.13v17.25c0 .62.5 1.12 1.13 1.12h12.75c.62 0 1.12-.5 1.12-1.12V11.25a9 9 0 0 0-9-9Z"/></svg> PDF
                    </button>
                @else
                    <span class="inline-flex items-center justify-center text-slate-600 text-[11px]">-</span>
                @endif
                @if ($showDelete)
                    <button type="button" onclick="deleteRequest({{ $item->id }}, '{{ $item->ticket_number }}')" class="inline-flex items-center justify-center w-full whitespace-nowrap px-2.5 py-1.5 rounded-lg bg-rose-600/20 text-rose-400 border border-rose-500/30 hover:bg-rose-600 hover:text-white text-[11px] font-bold transition">
                        <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.35 9m-4.78 0L9.26 9m9.97-3.21c.34.05.68.11 1.02.17m-1.02-.17-1.07 13.9a2.25 2.25 0 0 1-2.24 2.08H8.08a2.25 2.25 0 0 1-2.24-2.08L4.77 5.79m14.46 0a48.1 48.1 0 0 0-3.48-.4m-12 .57c.34-.06.68-.12 1.02-.17m0 0a48.1 48.1 0 0 1 3.48-.4m7.5 0v-.92c0-1.18-.91-2.16-2.09-2.2a51.96 51.96 0 0 0-3.32 0c-1.18.04-2.09 1.02-2.09 2.2v.92m7.5 0a48.67 48.67 0 0 0-7.5 0"/></svg>
                    </button>
                @else
                    <span></span>
                @endif
            </div>
        </td>
        <td data-label="Keterangan" class="py-3 px-4 text-center">
            @if (filled($item->update_note) && $isOwnerOrManager)
                <button type="button" onclick='openPublicNoteModal(@json($item->update_note))' class="inline-flex items-center px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-teal-600 text-slate-300 hover:text-white text-[11px] font-bold transition">
                    <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.04 12.32a1 1 0 0 1 0-.64C3.42 7.51 7.36 4.5 12 4.5c4.64 0 8.57 3.01 9.96 7.18.07.21.07.43 0 .64C20.58 16.49 16.64 19.5 12 19.5c-4.64 0-8.57-3.01-9.96-7.18ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg> View
                </button>
            @else
                <span class="text-slate-600 text-[11px]">-</span>
            @endif
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8" class="py-6 text-center text-slate-500 italic">Belum ada data history request.</td>
    </tr>
@endforelse
