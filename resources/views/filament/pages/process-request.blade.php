<x-filament-panels::page>
    @php
        $r = $this->record();
        $in = 'border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;background:transparent;color:inherit;width:100%';
        $lbl = 'font-weight:600;font-size:13px;display:block;margin-bottom:3px';
        $err = fn ($k) => $errors->has($k) ? '<div style="color:#dc2626;font-size:12px;margin-top:3px">' . e($errors->first($k)) . '</div>' : '';
        $isLoan = $r->type === 'PEMINJAMAN_ASET';
        $isDamage = in_array($r->type, ['BAK', 'TICKET_SISTEM'], true);
        $assetOnly = $isLoan || $isDamage;
        $typeLabel = ['ITR' => 'IT Request', 'BAK' => 'Berita Acara Kerusakan', 'SURAT_JALAN' => 'Surat Jalan', 'TICKET_SISTEM' => 'Ticket Sistem', 'WO' => 'Work Order Instalasi Infrastruktur', 'PEMINJAMAN_ASET' => 'Peminjaman Aset'][$r->type] ?? $r->type;
        $card = 'background:rgba(127,127,127,.08);border-radius:12px;padding:18px;margin-bottom:16px';
    @endphp
    <div style="max-width:1000px">
        <div style="{{ $card }}">
            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:6px">
                <b>{{ $typeLabel }}</b>
                <span style="padding:2px 10px;border-radius:99px;background:#1f78b0;color:#fff;font-size:12px">{{ $r->ticket_number ?? 'Belum bernomor' }}</span>
                <span style="padding:2px 10px;border-radius:99px;background:rgba(127,127,127,.25);font-size:12px">{{ $r->status }}</span>
                <a href="{{ route('dashboard') }}" style="margin-left:auto;font-size:13px;color:#1f78b0">← Kembali ke ITERA</a>
            </div>
            <p style="font-size:13px;color:#888">Pemohon mengisi form secara manual. Di sini tim IT mencocokkan dengan database IT Asset, menyesuaikan data, lalu memproses.</p>
            @if($r->items)
                <p style="font-size:13px;margin-top:8px"><b>Barang yang ditulis pemohon:</b>
                @foreach((array) $r->items as $it){{ $loop->first ? '' : ' · ' }}{{ $it['name'] ?? '-' }}@if(! empty($it['qty'])) ×{{ $it['qty'] }}@endif @if(! empty($it['spesifikasi'])) ({{ $it['spesifikasi'] }})@endif @endforeach</p>
            @endif
        </div>

        <div style="{{ $card }}">
            <h3 style="font-weight:700;margin-bottom:10px">1. Data pemohon &amp; karyawan di database</h3>
            <div style="margin-bottom:12px">
                <label style="{{ $lbl }}">Karyawan di IT Asset</label>
                <select wire:model="employeeId" style="{{ $in }};max-width:420px">
                    <option value="">— pilih karyawan —</option>
                    @foreach($this->employees() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <div style="font-size:12px;color:#888;margin-top:3px">Dicocokkan otomatis dari akun, NIK, atau nama pemohon; ubah bila kurang tepat.</div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px">
                <div><label style="{{ $lbl }}">Nama pemohon</label><input type="text" wire:model="fields.requester_name" style="{{ $in }}">{!! $err('fields.requester_name') !!}</div>
                <div><label style="{{ $lbl }}">NIK</label><input type="text" wire:model="fields.nik" style="{{ $in }}">{!! $err('fields.nik') !!}</div>
                <div><label style="{{ $lbl }}">Department</label>
                    <select wire:model="fields.department" style="{{ $in }}"><option value="">—</option>@foreach($this->departments() as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach</select></div>
                <div><label style="{{ $lbl }}">Jabatan</label><input type="text" wire:model="fields.position" style="{{ $in }}">{!! $err('fields.position') !!}</div>
                <div style="grid-column:1/-1"><label style="{{ $lbl }}">Judul request</label><input type="text" wire:model="fields.title" style="{{ $in }}">{!! $err('fields.title') !!}</div>
                <div style="grid-column:1/-1"><label style="{{ $lbl }}">Keterangan</label><textarea wire:model="fields.description" rows="2" style="{{ $in }}"></textarea>{!! $err('fields.description') !!}</div>
                @if($isLoan)
                    <div><label style="{{ $lbl }}">Tanggal pinjam</label><input type="date" wire:model="fields.loan_start_date" style="{{ $in }}"></div>
                    <div><label style="{{ $lbl }}">Tanggal kembali</label><input type="date" wire:model="fields.loan_end_date" style="{{ $in }}">{!! $err('fields.loan_end_date') !!}</div>
                @endif
            </div>
        </div>

        <div style="{{ $card }}">
            <h3 style="font-weight:700;margin-bottom:4px">2. Barang dari database</h3>
            <p style="font-size:13px;color:#888;margin-bottom:10px">
                @if($isLoan) Pilih aset yang Tersedia untuk dipinjamkan. @elseif($isDamage) Pilih aset yang rusak (aset yang dipegang pelapor); Tim IT membuatkan tiket perbaikan dari sini. @else Pilih padanan barang yang diminta: aset, Accessories, Consumable, atau Komponen. @endif
            </p>
            @foreach($links as $i => $l)
                <div wire:key="link-{{ $i }}" style="display:grid;grid-template-columns:150px minmax(220px,1fr) 90px minmax(140px,1fr) auto;gap:8px;align-items:start;margin-bottom:8px">
                    <select wire:model.live="links.{{ $i }}.kind" @disabled($l['processed']) style="{{ $in }}">
                        <option value="">— jenis —</option>
                        @foreach(\App\Models\RequestItemLink::KINDS as $k => $name)@if(! $assetOnly || $k === 'asset')<option value="{{ $k }}">{{ $name }}</option>@endif @endforeach
                    </select>
                    <select wire:model="links.{{ $i }}.item_id" @disabled($l['processed']) style="{{ $in }}">
                        <option value="">— pilih barang —</option>
                        @foreach($this->options($l['kind'] ?? '') as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                    </select>
                    <div><input type="number" step="any" min="0" wire:model="links.{{ $i }}.qty" @disabled($l['processed'] || ($l['kind'] ?? '') === 'asset') style="{{ $in }}" placeholder="Jumlah">{!! $err("links.$i.qty") !!}</div>
                    <input type="text" wire:model="links.{{ $i }}.note" placeholder="Catatan IT" style="{{ $in }}">
                    @if($l['processed'])
                        <span style="font-size:12px;color:#16a34a;padding-top:8px">{{ $isDamage ? '✓ Tiket perbaikan' : '✓ Dipinjamkan' }}</span>
                    @else
                        <button type="button" wire:click="removeLink({{ $i }})" title="Hapus baris" style="color:#dc2626;padding:6px 10px">✕</button>
                    @endif
                </div>
            @endforeach
            <button type="button" wire:click="addLink" style="margin-top:6px;padding:6px 14px;border-radius:8px;border:1px solid #1f78b0;color:#1f78b0;font-weight:600">+ Tambah barang</button>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:10px">
            <button type="button" wire:click="save" style="padding:9px 18px;border-radius:8px;background:#1f78b0;color:#fff;font-weight:600">Simpan</button>
            @if($isLoan)
                <button type="button" wire:click="saveAndBorrow" wire:confirm="Aset yang ditautkan akan berstatus Dipinjam dan BAST dibuat. Lanjutkan?" style="padding:9px 18px;border-radius:8px;background:#37baab;color:#fff;font-weight:600">Simpan &amp; Pinjamkan Aset</button>
            @elseif($isDamage)
                <button type="button" wire:click="saveAndRepair" wire:confirm="Aset yang ditautkan akan berstatus Perbaikan dan tiket perbaikan dibuat. Lanjutkan?" style="padding:9px 18px;border-radius:8px;background:#37baab;color:#fff;font-weight:600">Simpan &amp; Buat Tiket Perbaikan</button>
            @elseif($r->type === 'ITR')
                <button type="button" wire:click="toHandover" style="padding:9px 18px;border-radius:8px;background:#37baab;color:#fff;font-weight:600">Simpan &amp; Buat BAST Serah Terima</button>
            @endif
        </div>

        @if($r->accessoryHandovers->isNotEmpty())
            <p style="font-size:13px;margin-top:14px">BAST terkait:
                @foreach($r->accessoryHandovers as $h)<a href="{{ route('accessory-bast.view', $h) }}" target="_blank" style="color:#1f78b0">{{ $h->bast_number }}</a>{{ $loop->last ? '' : ', ' }}@endforeach</p>
        @endif
    </div>
</x-filament-panels::page>
