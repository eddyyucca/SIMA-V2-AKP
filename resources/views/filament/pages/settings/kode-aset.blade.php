<p style="font-size:13px;color:#888;margin-bottom:12px">Format: <b>AWALAN-KATEGORI-URUT</b>, mis. <b>{{ \App\Models\Asset::tagPrefix() }}-NB-{{ str_pad('1', \App\Models\Asset::tagDigits(), '0', STR_PAD_LEFT) }}</b>. Nomor urut {{ \App\Models\Asset::tagDigits() }} digit, berjalan per kategori. Mengubah awalan hanya berlaku untuk aset baru.</p>
<label style="font-weight:600">Awalan kode aset</label><br>
<input type="text" wire:model.live="prefix" maxlength="20" style="border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;background:transparent;color:inherit;min-width:260px;max-width:100%;text-transform:uppercase">
@error('prefix')<div style="color:#dc2626;font-size:13px;margin-top:4px">{{ $message }}</div>@enderror
<p style="font-size:13px;margin-top:10px">Contoh kode berikutnya: <b>{{ $this->example() }}</b></p>
