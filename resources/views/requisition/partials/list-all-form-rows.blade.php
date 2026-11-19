@forelse ($requests as $item)
    <!-- Atribut data-status & class request-row ditambahkan di sini -->
    <tr class="request-row hover:bg-slate-800/50 transition-colors" data-status="{{ $item->status }}" data-type="{{ $item->type }}">
        @if ($isSuperAdmin)
            <td data-label="Pilih" class="py-3 px-4">
                <input type="checkbox" class="row-select-checkbox rounded border-slate-600 bg-slate-800 text-fuchsia-600 focus:ring-fuchsia-500" value="{{ $item->id }}" onchange="updateBulkDeleteState()">
            </td>
        @endif
        <td data-label="No. Ticket" class="py-3 px-4 ticket-no font-mono font-bold whitespace-nowrap">
            <!-- Tombol Klik untuk Modal -->
            <button type="button"
                    onclick='openDetailModal(@json($item))'
                    class="hover:underline hover:text-fuchsia-300 focus:outline-none text-left">
                {{ $item->ticket_number }}
            </button>
        </td>
        <td data-label="Requester" class="py-3 px-4 font-medium text-white">
            {{ $item->requester_name ?? ($item->user->name ?? 'N/A') }}
            <p class="text-slate-500 font-normal text-[11px]">{{ optional($item->user)->username ?? '-' }}</p>
        </td>
        <td data-label="Judul Request" class="py-3 px-4 text-slate-300 min-w-[160px]">
            {{ $item->title ?? $item->subject }}
        </td>
        <td data-label="Status" class="py-3 px-4">
            @if ($canManage && $item->status !== 'Completed')
                <!-- Form Update Status Dropdown -->
                <form action="{{ route('requests.update-status', $item->id) }}" method="POST">
                    @csrf
                    @method('PATCH')
                    <select name="status" onchange="this.form.submit()" class="px-2.5 py-1 rounded-full text-[10px] font-bold border focus:outline-none cursor-pointer transition-colors duration-200
                        @if($item->status == 'Completed') bg-emerald-500/20 text-emerald-400 border-emerald-500/30
                        @elseif($item->status == 'In Progress') bg-blue-500/20 text-blue-400 border-blue-500/30
                        @elseif($item->status == 'Approved') bg-teal-500/20 text-teal-400 border-teal-500/30
                        @elseif($item->status == 'Rejected') bg-rose-500/20 text-rose-400 border-rose-500/30
                        @else bg-amber-500/20 text-amber-400 border-amber-500/30 @endif">
                        <option value="Pending" class="bg-slate-900 text-amber-400" {{ $item->status == 'Pending' ? 'selected' : '' }}>Pending</option>
                        <option value="Approved" class="bg-slate-900 text-teal-400" {{ $item->status == 'Approved' ? 'selected' : '' }}>Approved</option>
                        <option value="In Progress" class="bg-slate-900 text-blue-400" {{ $item->status == 'In Progress' ? 'selected' : '' }}>In Progress</option>
                        <option value="Rejected" class="bg-slate-900 text-rose-400" {{ $item->status == 'Rejected' ? 'selected' : '' }}>Rejected</option>
                        <option value="Completed" class="bg-slate-900 text-emerald-400" {{ $item->status == 'Completed' ? 'selected' : '' }}>Completed</option>
                    </select>
                </form>
            @else
                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold whitespace-nowrap inline-block border
                    @if($item->status == 'Completed') bg-emerald-500/20 text-emerald-400 border-emerald-500/30
                    @elseif($item->status == 'In Progress') bg-blue-500/20 text-blue-400 border-blue-500/30
                    @elseif($item->status == 'Approved') bg-teal-500/20 text-teal-400 border-teal-500/30
                    @elseif($item->status == 'Rejected') bg-rose-500/20 text-rose-400 border-rose-500/30
                    @else bg-amber-500/20 text-amber-400 border-amber-500/30 @endif">
                    {{ $item->status }}
                </span>
            @endif
        </td>
        <td data-label="Disetujui Oleh" class="py-3 px-4 text-slate-400 whitespace-nowrap">
            {{ $item->approvedBy->name ?? '-' }}
        </td>
        <td data-label="Tanggal" class="py-3 px-4 text-slate-400 whitespace-nowrap">
            {{ $item->created_at->format('d M Y, H:i') }}
        </td>
        <td data-label="Durasi Pengerjaan" class="py-3 px-4 text-slate-400 whitespace-nowrap">
            @if ($item->processing_duration_label)
                {{ $item->processing_duration_label }}
                @if ($item->status !== 'Completed')
                    <span class="text-[10px] text-amber-400">(berjalan)</span>
                @endif
            @else
                <span class="text-slate-600">-</span>
            @endif
        </td>
        <td data-label="Aksi" class="py-3 px-4 text-center md:sticky md:right-0 md:bg-[#111827] md:shadow-[-8px_0_8px_-8px_rgba(0,0,0,0.6)]">
            <div class="grid grid-cols-3 auto-rows-[32px] gap-1.5 w-[330px] mx-auto">
                @if ($item->type !== 'TICKET_SISTEM')
                    <button type="button" onclick="openPdfModal('{{ route('requests.pdf', $item->id) }}')" title="Lihat PDF" class="inline-flex items-center justify-center w-full whitespace-nowrap px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-fuchsia-600 text-slate-300 hover:text-white text-[11px] font-bold transition">
                        <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.63a3.38 3.38 0 0 0-3.38-3.37h-1.5A1.13 1.13 0 0 1 13.5 7.13v-1.5a3.38 3.38 0 0 0-3.38-3.38H8.25m2.25 0H5.63c-.62 0-1.13.5-1.13 1.13v17.25c0 .62.5 1.12 1.13 1.12h12.75c.62 0 1.12-.5 1.12-1.12V11.25a9 9 0 0 0-9-9Z"/></svg> PDF
                    </button>
                @else
                    <span></span>
                @endif
                @if ($canManage && $item->type !== 'TICKET_SISTEM')
                    <button type="button" onclick='openDocumentModal({{ json_encode(route('requests.document.upload', $item->id)) }}, {{ json_encode($item->document_name) }}, {{ json_encode($item->document_path ? route('requests.document.view', $item->id) : null) }})' title="Upload Dokumen" class="inline-flex items-center justify-center w-full whitespace-nowrap px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-emerald-600 text-slate-300 hover:text-white text-[11px] font-bold transition">
                        <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m18.38 11.69-7.8 7.8a4.5 4.5 0 0 1-6.36-6.36l9.2-9.2a3 3 0 0 1 4.24 4.24l-9.2 9.2a1.5 1.5 0 0 1-2.12-2.12l7.8-7.8"/></svg> {{ $item->document_path ? 'Dokumen' : 'Upload' }}
                    </button>
                @else
                    <span></span>
                @endif
                @if ($canManage && $item->type === 'BAK')
                    <button type="button" onclick='openEditModal(@json($item))' title="Edit Form Request & Analisa" class="inline-flex items-center justify-center w-full whitespace-nowrap px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-fuchsia-600 text-slate-300 hover:text-white text-[11px] font-bold transition">
                        <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.86 4.49 1.69-1.69a1.87 1.87 0 1 1 2.65 2.65L6.83 19.82a4.5 4.5 0 0 1-1.9 1.13L2.25 21.75l.8-2.69a4.5 4.5 0 0 1 1.13-1.9L16.86 4.49Zm0 0 2.65 2.65"/></svg> Edit
                    </button>
                @else
                    <span></span>
                @endif
                @if ($canManage)
                    <a href="{{ \App\Filament\Pages\ProcessRequest::urlFor($item) }}" title="Cocokkan dengan data IT Asset, sesuaikan data, dan proses{{ $item->type === 'ITR' ? ' serah terima (BAST)' : '' }}" class="col-span-3 inline-flex items-center justify-center w-full whitespace-nowrap px-2.5 py-1.5 rounded-lg bg-teal-600/20 hover:bg-teal-600 text-teal-300 hover:text-white border border-teal-500/30 text-[11px] font-bold transition"><svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.69a4.5 4.5 0 0 1 1.24 7.24l-4.5 4.5a4.5 4.5 0 0 1-6.36-6.36l1.76-1.76m13.35-.62 1.76-1.76a4.5 4.5 0 0 0-6.36-6.36l-4.5 4.5a4.5 4.5 0 0 0 1.24 7.24"/></svg> Proses IT</a>
                @endif
                @if ($item->type === 'ITR' && $item->accessoryHandovers->isNotEmpty())
                    <div class="col-span-3 flex flex-wrap items-center justify-center gap-x-2 gap-y-0.5 text-[10px] leading-4 text-slate-400">
                        <span>BAST:</span>
                        @foreach ($item->accessoryHandovers as $ah)
                            <a href="{{ route('accessory-bast.view', $ah) }}" target="_blank" rel="noopener" class="font-bold text-fuchsia-400 hover:text-fuchsia-300 underline underline-offset-2">{{ $ah->bast_number }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
        </td>
        @if ($isSuperAdmin)
            <td data-label="Hapus" class="py-3 px-4 text-center">
                <button type="button" onclick="deleteRequest({{ $item->id }}, '{{ $item->ticket_number }}')" class="inline-flex items-center px-2.5 py-1.5 rounded-lg bg-rose-600/20 text-rose-400 border border-rose-500/30 hover:bg-rose-600 hover:text-white text-[11px] font-bold transition">
                    <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.35 9m-4.78 0L9.26 9m9.97-3.21c.34.05.68.11 1.02.17m-1.02-.17-1.07 13.9a2.25 2.25 0 0 1-2.24 2.08H8.08a2.25 2.25 0 0 1-2.24-2.08L4.77 5.79m14.46 0a48.1 48.1 0 0 0-3.48-.4m-12 .57c.34-.06.68-.12 1.02-.17m0 0a48.1 48.1 0 0 1 3.48-.4m7.5 0v-.92c0-1.18-.91-2.16-2.09-2.2a51.96 51.96 0 0 0-3.32 0c-1.18.04-2.09 1.02-2.09 2.2v.92m7.5 0a48.67 48.67 0 0 0-7.5 0"/></svg>
                </button>
            </td>
        @endif
    </tr>
@empty
    <tr>
        <td colspan="{{ 8 + ($isSuperAdmin ? 2 : 0) }}" class="py-6 text-center text-slate-500 italic">
            Belum ada data history request.
        </td>
    </tr>
@endforelse
