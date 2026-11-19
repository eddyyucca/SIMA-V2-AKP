<x-filament-panels::page>
    <div style="max-width:1100px;font-size:14px">
        <p>Unduh <a href="{{ route('template.assets') }}" style="color:#1f78b0">template CSV</a>, isi, lalu unggah. Kolom <b>kategori</b> harus sama dengan nama kategori aset. Serial number yang duplikat ditolak. Kode aset dibuat otomatis. Kolom spesifikasi (<b>{{ implode(', ', array_keys(\App\Models\Asset::specFields())) }}</b>) boleh diisi untuk laptop / PC; pada kolom berjenis Pilihan, nilai baru otomatis masuk ke daftar pilihan.</p>
        <input type="file" wire:model="file" accept=".csv,.xlsx,.xls,.txt" style="margin:12px 0">
        @error('file')<div style="color:#dc2626">{{ $message }}</div>@enderror
        <div><button type="button" wire:click="import" wire:loading.attr="disabled" style="padding:8px 16px;border-radius:8px;background:#1f78b0;color:#fff;font-weight:600">Import</button></div>
        @if($imported !== null)
            <p style="margin-top:12px"><b>{{ $imported }}</b> aset berhasil diimpor.</p>
            @if($errors_list)<ul style="color:#dc2626;list-style:disc;padding-left:20px">@foreach($errors_list as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
        @endif
    </div>
</x-filament-panels::page>
