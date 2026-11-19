<p style="font-size:13px;color:#888;margin-bottom:12px">Atur isian spesifikasi pada form aset Laptop dan PC Desktop: ubah nama, tambah, hapus, atau ubah urutan. Kolom berjenis <b>Pilihan</b> tampil sebagai dropdown (bisa dicari) dengan daftar di bawah; saat mengisi aset tetap bisa <b>isi manual</b>. Menghapus kolom hanya menyembunyikannya; nilai lama baru hilang jika aset itu disimpan ulang.</p>
@foreach($specFields as $i => $f)
    <div wire:key="spec-{{ $i }}-{{ $f['key'] }}" style="border:1px solid rgba(127,127,127,.25);border-radius:10px;padding:10px;margin-bottom:10px">
        <div style="display:flex;gap:6px;align-items:flex-start;flex-wrap:wrap">
            <div style="display:flex;flex-direction:column">
                <button type="button" wire:click="moveSpecField({{ $i }}, -1)" title="Naik" style="line-height:1">▲</button>
                <button type="button" wire:click="moveSpecField({{ $i }}, 1)" title="Turun" style="line-height:1">▼</button>
            </div>
            <div>
                <input type="text" wire:model="specFields.{{ $i }}.label" placeholder="Nama kolom" maxlength="30" style="border:1px solid #d1d5db;border-radius:8px;padding:6px 10px;background:transparent;color:inherit;width:140px">
                @error("specFields.$i.label")<div style="color:#dc2626;font-size:12px">{{ $message }}</div>@enderror
            </div>
            <div>
                <select wire:model.live="specFields.{{ $i }}.type" title="Jenis isian" style="border:1px solid #d1d5db;border-radius:8px;padding:6px 8px;background:transparent;color:inherit">
                    <option value="text">Teks</option>
                    <option value="select">Pilihan</option>
                </select>
            </div>
            <div style="flex:1;min-width:160px">
                <input type="text" wire:model="specFields.{{ $i }}.hint" placeholder="Petunjuk isian (opsional)" maxlength="80" style="border:1px solid #d1d5db;border-radius:8px;padding:6px 10px;background:transparent;color:inherit;width:100%">
                @error("specFields.$i.hint")<div style="color:#dc2626;font-size:12px">{{ $message }}</div>@enderror
            </div>
            <button type="button" wire:click="removeSpecField({{ $i }})" wire:confirm="Hapus kolom ini?" style="color:#dc2626;padding:6px 8px">Hapus</button>
        </div>
        @if(($f['type'] ?? 'text') === 'select')
            <div style="margin-top:8px">
                <label style="font-size:12px;font-weight:600">Daftar pilihan <span style="font-weight:400;color:#888">(satu per baris — {{ count(array_filter(array_map('trim', preg_split('/\R/', (string) ($f['options'] ?? ''))))) }} pilihan)</span></label>
                <textarea wire:model="specFields.{{ $i }}.options" rows="6" placeholder="Satu pilihan per baris, mis.&#10;8 GB DDR4&#10;16 GB DDR4" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;background:transparent;color:inherit;font-size:13px;margin-top:2px"></textarea>
                @error("specFields.$i.options")<div style="color:#dc2626;font-size:12px">{{ $message }}</div>@enderror
            </div>
        @endif
    </div>
@endforeach
@error('specFields')<div style="color:#dc2626;font-size:13px;margin-bottom:6px">{{ $message }}</div>@enderror
<button type="button" wire:click="addSpecField" style="margin-top:4px;padding:8px 14px;border-radius:8px;border:1px solid #1f78b0;color:#1f78b0;font-weight:600">+ Tambah kolom</button>
