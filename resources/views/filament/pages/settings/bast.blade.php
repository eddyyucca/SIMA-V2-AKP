<p style="font-size:13px;color:#888;margin-bottom:12px">Kop dan nama penandatangan pada PDF BAST (serah terima, mutasi, pengembalian) dan formulir cetak. Berlaku untuk BAST yang dibuat berikutnya.</p>
@foreach(\App\Support\BastDocument::SETTINGS as $key => [$default, $label])
    <div style="margin-bottom:10px">
        <label style="font-weight:600;font-size:13px">{{ $label }}</label><br>
        <input type="text" wire:model="bast.{{ $key }}" maxlength="100" style="border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;background:transparent;color:inherit;width:100%">
        @error("bast.$key")<div style="color:#dc2626;font-size:13px;margin-top:4px">{{ $message }}</div>@enderror
    </div>
@endforeach
