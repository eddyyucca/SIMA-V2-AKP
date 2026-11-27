<x-app-layout>
    @php $typeLabels = ['ITR' => 'IT Request', 'PEMINJAMAN_ASET' => 'Peminjaman Aset']; @endphp
    <div class="py-8 bg-slate-950 min-h-screen">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            @if (session('success'))<div class="mb-4 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-sm">{{ session('success') }}</div>@endif
            @if (session('error'))<div class="mb-4 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm">{{ session('error') }}</div>@endif

            <h1 class="text-2xl font-black text-white">Persetujuan Atasan</h1>
            <p class="text-sm text-slate-400 mb-5">Request IT Request dan Peminjaman Aset dari {{ $subordinates->count() }} anak buah langsung Anda. Setelah Anda setujui, request masuk ke Tim IT; bila belum dijawab {{ \App\Services\RequestSupervisor::ESCALATE_AFTER_DAYS }} hari, request diteruskan otomatis ke Tim IT.</p>

            <h2 class="text-sm font-bold uppercase tracking-wide text-violet-300 mb-2">Menunggu keputusan Anda ({{ $waiting->count() }})</h2>
            <div class="space-y-3 mb-8">
                @forelse ($waiting as $r)
                    <div class="bg-[#111827] border border-violet-500/30 rounded-2xl p-4">
                        <div class="flex flex-wrap justify-between gap-2">
                            <div>
                                <div class="text-xs text-slate-400">{{ $typeLabels[$r->type] ?? $r->type }} · diajukan {{ $r->created_at->format('d M Y, H:i') }}</div>
                                <div class="text-white font-bold">{{ $r->title }}</div>
                                <div class="text-sm text-slate-300">{{ $r->requester_name }} · {{ $r->department }}{{ $r->position ? ' · ' . $r->position : '' }}</div>
                            </div>
                            @if ($r->type === 'PEMINJAMAN_ASET' && $r->loan_start_date)
                                <div class="text-sm text-slate-300">Pinjam {{ $r->loan_start_date->format('d M Y') }} → {{ $r->loan_end_date?->format('d M Y') }} ({{ $r->loan_duration_label }})</div>
                            @endif
                        </div>
                        @if ($r->description)<p class="text-sm text-slate-300 mt-2 whitespace-pre-line">{{ $r->description }}</p>@endif
                        @if (! empty($r->items))
                            <ul class="mt-2 text-sm text-slate-300 list-disc list-inside">
                                @foreach ($r->items as $it)<li>{{ $it['name'] ?? '-' }} × {{ $it['qty'] ?? 1 }}{{ ! empty($it['keterangan']) ? ' — ' . $it['keterangan'] : '' }}</li>@endforeach
                            </ul>
                        @endif
                        <form method="POST" action="{{ route('requests.supervisor.decide', $r->id) }}" class="mt-3 flex flex-wrap gap-2 items-start">
                            @csrf
                            <textarea name="note" rows="1" placeholder="Catatan (wajib bila menolak)" class="flex-1 min-w-[220px] bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-2 text-sm text-white"></textarea>
                            <button name="decision" value="approve" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold">Setujui</button>
                            <button name="decision" value="reject" class="px-4 py-2 rounded-lg bg-rose-600 hover:bg-rose-500 text-white text-sm font-bold">Tolak</button>
                        </form>
                    </div>
                @empty
                    <div class="bg-[#111827] border border-slate-800 rounded-2xl p-6 text-center text-slate-500">Tidak ada request yang menunggu Anda.</div>
                @endforelse
            </div>

            <h2 class="text-sm font-bold uppercase tracking-wide text-slate-400 mb-2">Keputusan terakhir</h2>
            <div class="bg-[#111827] border border-slate-800 rounded-2xl overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs uppercase text-slate-400 border-b border-slate-800"><tr><th class="py-2 px-4">Tanggal</th><th class="py-2 px-4">Pemohon</th><th class="py-2 px-4">Judul</th><th class="py-2 px-4">Keputusan</th><th class="py-2 px-4">Status kini</th></tr></thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse ($decided as $r)
                            <tr>
                                <td class="py-2 px-4 text-slate-400 whitespace-nowrap">{{ $r->supervisor_decided_at->format('d M Y, H:i') }}</td>
                                <td class="py-2 px-4 text-slate-300">{{ $r->requester_name }}</td>
                                <td class="py-2 px-4 text-slate-200">{{ $r->title }}</td>
                                <td class="py-2 px-4 text-slate-300">{{ ['approved' => 'Disetujui', 'approved_by_it' => 'Disetujui Tim IT', 'rejected' => 'Ditolak', 'escalated' => 'Diteruskan otomatis'][$r->supervisor_decision] ?? '-' }}{{ $r->supervisor_note && in_array($r->supervisor_decision, ['rejected', 'approved_by_it'], true) ? ': ' . $r->supervisor_note : '' }}</td>
                                <td class="py-2 px-4 text-slate-400">{{ $r->status }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-6 text-center text-slate-500">Belum ada keputusan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
