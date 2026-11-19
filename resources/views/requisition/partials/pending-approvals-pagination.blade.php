@if ($pendingApprovals->hasPages())
    <div class="flex items-center justify-between mt-3 text-xs text-slate-400">
        <span>
            Menampilkan {{ $pendingApprovals->firstItem() }}-{{ $pendingApprovals->lastItem() }} dari {{ $pendingApprovals->total() }} request
        </span>
        <div class="flex items-center gap-2">
            <button type="button"
                    onclick="loadPendingApprovals({{ $pendingApprovals->currentPage() - 1 }})"
                    @if ($pendingApprovals->onFirstPage()) disabled @endif
                    class="px-3 py-1.5 rounded-lg bg-slate-800 border border-slate-700 font-semibold disabled:opacity-40 disabled:cursor-not-allowed enabled:hover:bg-slate-700 enabled:text-slate-200 transition">
                ‹ Prev
            </button>
            <span class="px-2">Hal. {{ $pendingApprovals->currentPage() }} / {{ $pendingApprovals->lastPage() }}</span>
            <button type="button"
                    onclick="loadPendingApprovals({{ $pendingApprovals->currentPage() + 1 }})"
                    @if (! $pendingApprovals->hasMorePages()) disabled @endif
                    class="px-3 py-1.5 rounded-lg bg-slate-800 border border-slate-700 font-semibold disabled:opacity-40 disabled:cursor-not-allowed enabled:hover:bg-slate-700 enabled:text-slate-200 transition">
                Next ›
            </button>
        </div>
    </div>
@else
    <div class="mt-3 text-xs text-slate-500">
        Menampilkan {{ $pendingApprovals->total() }} request
    </div>
@endif
