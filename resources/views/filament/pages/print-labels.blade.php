<x-filament-panels::page>
    @php
        $sel = 'border:1px solid #d1d5db;border-radius:8px;padding:6px 8px;background:transparent;color:inherit;width:100%';
        $rows = $this->rows();
        $url = $this->url();
        $K = \App\Services\LabelService::KINDS;
    @endphp
    <div style="max-width:100%">
        <p style="font-size:13px;color:#888;margin-bottom:12px">
            PDF dibuat dengan ukuran halaman = lebar pita × panjang label, satu label per halaman, tanpa margin.
            Cetak dari dialog cetak dengan printer <b>Brother PT-D610BT</b> (atau P-touch Editor), pilih ukuran kertas yang sama dengan pita yang terpasang, skala 100%.
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-bottom:14px">
            <label>Jenis barang
                <select wire:model.live="kind" style="{{ $sel }}">@foreach($K as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
            <label>Lebar pita (mm)
                <select wire:model.live="tape" style="{{ $sel }}">@foreach(config('labels.tapes') as $t)<option value="{{ $t }}">{{ $t }} mm</option>@endforeach</select></label>
            <label>Panjang label (mm)
                <input type="number" min="20" max="300" wire:model.live.debounce.400ms="length" style="{{ $sel }}"></label>
            <label>Salinan per barang
                <input type="number" min="1" max="20" wire:model.live.debounce.400ms="copies" style="{{ $sel }}"></label>
            <label>Orientasi halaman
                <select wire:model.live="orient" style="{{ $sel }}">@foreach(\App\Services\LabelService::ORIENTATIONS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
            <label>Cari
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="kode / nama / serial" style="{{ $sel }}"></label>
        </div>
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:10px;flex-wrap:wrap">
            <button type="button" wire:click="selectAll" style="padding:6px 12px;border-radius:8px;background:#374151;color:#fff">Pilih semua ({{ $rows->count() }})</button>
            <button type="button" wire:click="clear" style="padding:6px 12px;border-radius:8px;border:1px solid #9ca3af">Kosongkan</button>
            @if($url)
                <a href="{{ $url }}" target="_blank" style="padding:8px 16px;border-radius:8px;background:#1f78b0;color:#fff;font-weight:700">Buat PDF label ({{ count($selected) }} barang × {{ $copies }})</a>
            @else
                <span style="color:#888">Centang barang untuk membuat label.</span>
            @endif
        </div>
        <div style="max-height:420px;overflow:auto;border:1px solid rgba(127,127,127,.25);border-radius:10px">
            @forelse($rows as $r)
                @php $code = \App\Services\LabelService::code($kind, $r); @endphp
                <label style="display:flex;gap:10px;align-items:center;padding:7px 12px;border-bottom:1px solid rgba(127,127,127,.15);cursor:pointer">
                    <input type="checkbox" value="{{ $r->id }}" wire:model.live="selected">
                    <b style="min-width:120px">{{ $code }}</b>
                    <span style="flex:1">{{ $kind === 'asset' ? trim($r->brand . ' ' . $r->model) : $r->name }}</span>
                    @if($kind === 'asset')<span style="color:#888;font-size:12px">{{ $r->serial_number }}</span>@endif
                </label>
            @empty
                <div style="padding:16px;color:#888">Tidak ada data.</div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
