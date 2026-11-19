<p style="font-size:13px;color:#888;margin-bottom:12px">Umur ekonomis dipakai untuk penyusutan garis lurus. Nilai ini <b>terisi otomatis</b> di form aset saat kategori dipilih (mis. Laptop 4 tahun), jadi tidak perlu diisi lagi; tetap bisa diubah untuk aset tertentu. Mengubahnya di sini berlaku untuk aset yang mengikuti kategori.</p>
<div style="margin-bottom:12px">
    <label style="font-weight:600;font-size:13px">Umur bawaan (tahun) <span style="font-weight:400;color:#888">— untuk kategori yang dikosongkan</span></label><br>
    <input type="number" wire:model="lifeDefault" min="1" max="50" style="border:1px solid #d1d5db;border-radius:8px;padding:6px 10px;background:transparent;color:inherit;width:110px">
    @error('lifeDefault')<div style="color:#dc2626;font-size:12px">{{ $message }}</div>@enderror
</div>
<table style="width:100%;font-size:13px;border-collapse:collapse">
    <thead><tr style="text-align:left;color:#888"><th style="padding:4px 6px 4px 0">Kategori</th><th style="padding:4px 6px">Umur (tahun)</th><th style="padding:4px 6px">Residu (%)</th></tr></thead>
    <tbody>
    @foreach($lifeRows as $i => $r)
        <tr wire:key="life-{{ $r['id'] }}" style="border-top:1px solid rgba(127,127,127,.2)">
            <td style="padding:6px 6px 6px 0;font-weight:600">{{ $r['name'] }}</td>
            <td style="padding:6px">
                <input type="number" wire:model="lifeRows.{{ $i }}.years" min="1" max="50" placeholder="{{ $lifeDefault }}" style="border:1px solid #d1d5db;border-radius:8px;padding:5px 8px;background:transparent;color:inherit;width:100px">
                @error("lifeRows.$i.years")<div style="color:#dc2626;font-size:12px">{{ $message }}</div>@enderror
            </td>
            <td style="padding:6px">
                <input type="number" wire:model="lifeRows.{{ $i }}.residual" min="0" max="100" step="0.01" style="border:1px solid #d1d5db;border-radius:8px;padding:5px 8px;background:transparent;color:inherit;width:90px">
                @error("lifeRows.$i.residual")<div style="color:#dc2626;font-size:12px">{{ $message }}</div>@enderror
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
@if(empty($lifeRows))<p style="color:#888;font-size:13px">Belum ada kategori aset.</p>@endif
