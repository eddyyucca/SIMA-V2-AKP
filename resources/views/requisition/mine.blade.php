<x-app-layout>
    @php
        $typeLabels = ['TICKET_SISTEM' => 'Ticket Sistem', 'ITR' => 'IT Request', 'BAK' => 'BAK', 'WO' => 'Work Order', 'PEMINJAMAN_ASET' => 'Peminjaman Aset', 'SURAT_JALAN' => 'Surat Jalan'];
        $statusLabels = ['Awaiting Supervisor' => 'Menunggu Atasan', 'Pending' => 'Menunggu Tim IT', 'Approved' => 'Disetujui', 'In Progress' => 'Diproses', 'Completed' => 'Selesai', 'Rejected' => 'Ditolak'];
        $tone = ['Awaiting Supervisor' => 'bg-violet-100 text-violet-700', 'Pending' => 'bg-amber-100 text-amber-700', 'Approved' => 'bg-teal-100 text-teal-700', 'In Progress' => 'bg-blue-100 text-blue-700', 'Completed' => 'bg-emerald-100 text-emerald-700', 'Rejected' => 'bg-rose-100 text-rose-700'];
    @endphp
    <div class="py-8 bg-slate-950 min-h-screen">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
                <div>
                    <h1 class="text-2xl font-black text-white">Request Saya</h1>
                    <p class="text-sm text-slate-400">Semua request milik Anda{{ auth()->user()->employee && app(\App\Services\RequestSupervisor::class)->subordinates(auth()->user()->employee)->isNotEmpty() ? ' dan anak buah Anda' : '' }}.</p>
                </div>
                <a href="{{ route('requests.create') }}" class="px-4 py-2 rounded-xl bg-gradient-to-r from-fuchsia-600 to-purple-600 text-white text-sm font-bold">+ Buat Request</a>
            </div>

            <form method="GET" class="flex flex-wrap gap-2 mb-4" id="mineFilter">
                <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Cari judul atau nama pemohon" class="flex-1 min-w-[200px] bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                <select name="type" class="bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" onchange="this.form.submit()">
                    <option value="All">Semua jenis</option>
                    @foreach ($typeLabels as $k => $v)<option value="{{ $k }}" @selected(($filters['type'] ?? '') === $k)>{{ $v }}</option>@endforeach
                </select>
                <select name="status" class="bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" onchange="this.form.submit()">
                    <option value="All">Semua status</option>
                    @foreach ($statusLabels as $k => $v)<option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ $v }}</option>@endforeach
                </select>
                <button class="px-4 py-2 rounded-lg bg-slate-700 text-white text-sm font-semibold">Cari</button>
                @if (array_filter($filters ?? []))<a href="{{ route('requests.mine') }}" class="px-3 py-2 text-sm text-slate-400 underline">Reset</a>@endif
            </form>

            <div class="bg-[#111827] border border-slate-800 rounded-2xl overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs uppercase text-slate-400 border-b border-slate-800">
                        <tr><th class="py-3 px-4">No. Tiket</th><th class="py-3 px-4">Jenis</th><th class="py-3 px-4">Pemohon</th><th class="py-3 px-4">Judul</th><th class="py-3 px-4">Status</th><th class="py-3 px-4">Dokumen</th><th class="py-3 px-4">Diajukan</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse ($requests as $r)
                            <tr class="hover:bg-slate-800/40">
                                <td class="py-3 px-4 font-mono font-bold text-slate-200 whitespace-nowrap">{{ $r->ticket_number ?? 'Belum bernomor' }}</td>
                                <td class="py-3 px-4 text-slate-300">{{ $typeLabels[$r->type] ?? $r->type }}</td>
                                <td class="py-3 px-4 text-slate-300">{{ $r->requester_name }}</td>
                                <td class="py-3 px-4 text-slate-200">{{ $r->title }}</td>
                                <td class="py-3 px-4 whitespace-nowrap">
                                    <span class="px-2.5 py-1 rounded-full text-[11px] font-bold {{ $tone[$r->status] ?? 'bg-slate-200 text-slate-700' }}">{{ $statusLabels[$r->status] ?? $r->status }}</span>
                                    @if ($r->isAwaitingSupervisor() && $r->supervisor)<div class="text-[11px] text-slate-400 mt-1">Menunggu {{ $r->supervisor->name }}</div>@endif
                                    @if ($r->status === 'Rejected' && ($r->update_note || $r->supervisor_note))<div class="text-[11px] text-rose-300 mt-1 max-w-xs">{{ $r->supervisor_decision === 'rejected' ? $r->supervisor_note : $r->update_note }}</div>@endif
                                    @if ($r->supervisor_decision === 'escalated')<div class="text-[11px] text-slate-400 mt-1">Diteruskan otomatis ke Tim IT</div>@endif
                                </td>
                                <td class="py-3 px-4 text-xs whitespace-nowrap">
                                    @foreach ($r->accessoryHandovers as $h)<a class="block text-sky-300 underline" target="_blank" href="{{ route('accessory-bast.view', $h) }}">BAST {{ $h->bast_number }}</a>@endforeach
                                    @foreach ($r->links->whereNotNull('assignment_id') as $l)@if ($l->assignment)<a class="block text-sky-300 underline" target="_blank" href="{{ route('bast.view', $l->assignment) }}">BAST {{ $l->assignment->bast_number }}</a>@endif @endforeach
                                    @if ($r->returned_at)<span class="block text-slate-400">Kembali {{ $r->returned_at->format('d M Y') }}</span>@endif
                                    @if ($r->accessoryHandovers->isEmpty() && $r->links->whereNotNull('assignment_id')->isEmpty() && ! $r->returned_at)<span class="text-slate-600">—</span>@endif
                                </td>
                                <td class="py-3 px-4 text-slate-400 whitespace-nowrap">{{ $r->created_at->format('d M Y, H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-10 text-center text-slate-500">Belum ada request{{ array_filter($filters ?? []) ? ' yang cocok dengan filter' : '' }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4 text-slate-300">{{ $requests->links() }}</div>
            <p class="mt-3 text-xs text-slate-500">Request yang ditolak dapat diperbaiki dan dikirim ulang dari halaman <a class="underline" href="{{ route('requests.create') }}">Buat Request</a> (tabel History Request Terbaru).</p>
        </div>
    </div>
</x-app-layout>
