<x-filament-panels::page>
    <div style="max-width:560px">
        <x-scanner />
        <div style="margin-top:16px;display:flex;gap:8px">
            <input type="text" wire:model="code" wire:keydown.enter="submitManual" placeholder="Atau ketik / scan kode aset atau serial number" autofocus
                   style="flex:1;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;background:transparent;color:inherit">
            <button type="button" wire:click="submitManual" style="padding:8px 16px;border-radius:8px;background:#374151;color:#fff">Cari</button>
        </div>

        @if($card)
            <div style="margin-top:20px;border:1px solid rgba(127,127,127,.35);border-radius:12px;padding:16px">
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">
                    <div>
                        <div style="font-size:12px;color:#888">{{ $card['category'] }}</div>
                        <div style="font-size:20px;font-weight:800">{{ $card['tag'] }}</div>
                        <div>{{ $card['name'] }}</div>
                        <div style="font-size:13px;color:#888">SN: {{ $card['serial'] }} · Lokasi: {{ $card['location'] }}</div>
                    </div>
                    <span style="font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;background:rgba(127,127,127,.18)">{{ $card['status'] }}</span>
                </div>

                <hr style="margin:12px 0;border:0;border-top:1px solid rgba(127,127,127,.25)">
                <div style="font-size:12px;font-weight:700;letter-spacing:.04em;color:#888;margin-bottom:6px">PEMEGANG SAAT INI</div>
                @if($card['holder'])
                    @php $h = $card['holder']; @endphp
                    <div style="font-size:18px;font-weight:700">{{ $h['name'] }}@if($h['resigned']) <span style="color:#dc2626;font-size:13px">(resigned)</span>@endif</div>
                    <table style="margin-top:6px;font-size:14px;border-collapse:collapse">
                        <tr><td style="color:#888;padding:2px 14px 2px 0">NIK</td><td><b>{{ $h['nik'] }}</b></td></tr>
                        <tr><td style="color:#888;padding:2px 14px 2px 0">Department</td><td><b>{{ $h['department'] }}</b></td></tr>
                        <tr><td style="color:#888;padding:2px 14px 2px 0">Jabatan</td><td><b>{{ $h['position'] }}</b></td></tr>
                        @if($h['since'])<tr><td style="color:#888;padding:2px 14px 2px 0">Sejak</td><td>{{ $h['since'] }}@if($h['bast']) · {{ $h['bast'] }}@endif</td></tr>@endif
                    </table>
                @else
                    <div style="font-size:15px;font-weight:600">Belum ada pemegang (stok IT)</div>
                @endif

                <div style="margin-top:14px"><a href="{{ $card['url'] }}" style="color:#1f78b0;font-weight:600">Buka detail aset →</a></div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
