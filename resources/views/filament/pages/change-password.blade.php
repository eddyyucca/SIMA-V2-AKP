<x-filament-panels::page>
    @php
        $in = 'border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;background:transparent;color:inherit;width:100%';
        $lbl = 'font-weight:600;font-size:13px;display:block;margin-bottom:3px';
        $err = fn ($k) => $errors->has($k) ? '<div style="color:#dc2626;font-size:12px;margin-top:3px">' . e($errors->first($k)) . '</div>' : '';
    @endphp
    <div style="max-width:520px">
        @if($this->forced())
            <div style="background:#fff7ed;border:1px solid #fdba74;color:#9a3412;border-radius:10px;padding:12px 14px;margin-bottom:14px;font-size:14px">
                <b>Anda wajib mengganti password.</b> Password awal Anda adalah NIK, sehingga harus diganti sebelum memakai aplikasi.
            </div>
        @endif
        <div style="background:rgba(127,127,127,.08);border-radius:12px;padding:18px">
            <form wire:submit="save" style="display:grid;gap:14px">
                <div><label style="{{ $lbl }}">Password saat ini @if($this->forced())<span style="font-weight:400;color:#888">(isi dengan NIK Anda)</span>@endif</label>
                    <input type="password" wire:model="current_password" autocomplete="current-password" style="{{ $in }}">{!! $err('current_password') !!}</div>
                <div><label style="{{ $lbl }}">Password baru</label>
                    <input type="password" wire:model="password" autocomplete="new-password" style="{{ $in }}">{!! $err('password') !!}
                    <div style="font-size:12px;color:#888;margin-top:3px">Minimal 8 karakter, mengandung huruf dan angka, dan tidak sama dengan NIK.</div></div>
                <div><label style="{{ $lbl }}">Ulangi password baru</label>
                    <input type="password" wire:model="password_confirmation" autocomplete="new-password" style="{{ $in }}"></div>
                <div><button type="submit" style="padding:9px 18px;border-radius:8px;background:#1f78b0;color:#fff;font-weight:600">Simpan Password</button></div>
            </form>
        </div>
    </div>
</x-filament-panels::page>
