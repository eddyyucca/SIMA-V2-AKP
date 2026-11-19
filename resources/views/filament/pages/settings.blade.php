<x-filament-panels::page>
    <style>
        .set-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
        .set-card{background:rgba(127,127,127,.08);border:1px solid rgba(127,127,127,.18);border-radius:14px;padding:18px;display:flex;flex-direction:column;gap:10px;transition:border-color .15s,box-shadow .15s}
        .set-card:hover{border-color:#1f78b0;box-shadow:0 2px 10px rgba(31,120,176,.15)}
        .set-ico{width:38px;height:38px;border-radius:10px;background:rgba(31,120,176,.12);color:#1f78b0;display:flex;align-items:center;justify-content:center;flex:none}
        .set-ico svg{width:22px;height:22px}
        .set-sum{font-size:12px;background:rgba(127,127,127,.12);border-radius:8px;padding:6px 10px;word-break:break-word}
        .set-btn{padding:8px 16px;border-radius:8px;background:#1f78b0;color:#fff;font-weight:600;font-size:14px}
        .set-btn.ghost{background:transparent;border:1px solid #1f78b0;color:#1f78b0}
    </style>

    <div x-data x-on:modal-closed.window="$wire.reloadSettings()">
        <div class="set-grid">
            @foreach($this->sections() as $s)
                <div class="set-card">
                    <div style="display:flex;gap:12px;align-items:center">
                        <div class="set-ico"><x-filament::icon :icon="$s['icon']" /></div>
                        <h3 style="font-weight:700;font-size:15px;line-height:1.25">{{ $s['title'] }}</h3>
                    </div>
                    <p style="font-size:13px;color:#888;flex:1">{{ $s['desc'] }}</p>
                    <div class="set-sum">{{ $s['summary'] }}</div>
                    <div><button type="button" class="set-btn" x-on:click="$dispatch('open-modal', { id: 'set-{{ $s['id'] }}' })">Atur</button></div>
                </div>
            @endforeach
        </div>

        @foreach($this->sections() as $s)
            <x-filament::modal :id="'set-' . $s['id']" width="2xl" :close-by-clicking-away="false" :sticky-header="true" :sticky-footer="true" wire:key="modal-{{ $s['id'] }}">
                <x-slot name="heading">{{ $s['title'] }}</x-slot>
                @include('filament.pages.settings.' . $s['view'])
                <x-slot name="footer">
                    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
                        @if(! empty($s['reset']))
                            <button type="button" wire:click="{{ $s['reset'] }}" wire:confirm="Kembalikan ke bawaan?" style="padding:8px 14px;border-radius:8px;color:#888;margin-right:auto">Kembalikan bawaan</button>
                        @endif
                        <button type="button" class="set-btn ghost" x-on:click="$dispatch('close-modal', { id: 'set-{{ $s['id'] }}' })">Batal</button>
                        <button type="button" class="set-btn" wire:click="{{ $s['save'] }}">Simpan</button>
                    </div>
                </x-slot>
            </x-filament::modal>
        @endforeach
    </div>
</x-filament-panels::page>
