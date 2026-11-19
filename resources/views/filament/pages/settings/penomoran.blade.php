<p style="font-size:13px;color:#888;margin-bottom:12px">Format kode: <b>AWALAN-NOMOR</b>, mis. <b>ACC-0007</b>. Kode dipakai pada label dan scan QR.</p>
@foreach(\App\Support\CodePrefix::LABELS as $k => $label)
    <div style="margin-bottom:10px">
        <label style="font-weight:600;font-size:13px">Awalan {{ $label }}</label><br>
        <input type="text" wire:model.live="codes.{{ $k }}" maxlength="10" style="border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;background:transparent;color:inherit;width:100%;max-width:260px;text-transform:uppercase">
        <span style="font-size:13px;margin-left:8px">Contoh: <b>{{ $this->codeExample($k) }}</b></span>
        @error("codes.$k")<div style="color:#dc2626;font-size:13px;margin-top:4px">{{ $message }}</div>@enderror
    </div>
@endforeach
