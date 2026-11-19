@if ($requests->hasPages())
    <div class="flex items-center justify-between mt-3 text-xs text-slate-400">
        <span>
            Menampilkan {{ $requests->firstItem() }}-{{ $requests->lastItem() }} dari {{ $requests->total() }} request
        </span>
        <div class="flex items-center gap-2">
            <button type="button"
                    onclick="loadListAllForm({{ $requests->currentPage() - 1 }})"
                    @if ($requests->onFirstPage()) disabled @endif
                    class="px-3 py-1.5 rounded-lg bg-slate-800 border border-slate-700 font-semibold disabled:opacity-40 disabled:cursor-not-allowed enabled:hover:bg-slate-700 enabled:text-slate-200 transition">
                ‹ Prev
            </button>
            <span class="px-2">Hal. {{ $requests->currentPage() }} / {{ $requests->lastPage() }}</span>
            <button type="button"
                    onclick="loadListAllForm({{ $requests->currentPage() + 1 }})"
                    @if (! $requests->hasMorePages()) disabled @endif
                    class="px-3 py-1.5 rounded-lg bg-slate-800 border border-slate-700 font-semibold disabled:opacity-40 disabled:cursor-not-allowed enabled:hover:bg-slate-700 enabled:text-slate-200 transition">
                Next ›
            </button>
        </div>
    </div>
@else
    <div class="mt-3 text-xs text-slate-500">
        Menampilkan {{ $requests->total() }} request
    </div>
@endif
