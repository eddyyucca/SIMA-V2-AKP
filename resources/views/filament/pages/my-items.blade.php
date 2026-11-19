<x-filament-panels::page>
    @php $emp = $this->employee(); @endphp
    @if(! $emp)
        <p style="color:#888">Akun Anda belum dihubungkan ke data karyawan. Hubungi tim IT.</p>
    @else
        <p>Halo, <b>{{ $emp->name }}</b>.</p>
        {{ $this->table }}
        <h3 style="font-weight:700;margin-top:20px">Accessories</h3>
        <ul style="list-style:disc;padding-left:20px;font-size:14px">
            @forelse($emp->heldAccessories() as $h)<li>{{ $h['accessory']?->name }} × {{ $h['qty'] }}</li>@empty<li style="list-style:none;color:#888">Tidak ada</li>@endforelse
        </ul>
        @include('filament.partials-handover-history', ['handovers' => $emp->accessoryHandovers()->with('items.stockable')->withCount('files')->limit(30)->get()])
        <h3 style="font-weight:700;margin-top:20px">Lisensi</h3>
        <ul style="list-style:disc;padding-left:20px;font-size:14px">
            @forelse($emp->licenseSeats()->with('license')->get() as $s)<li>{{ $s->license?->name }}</li>@empty<li style="list-style:none;color:#888">Tidak ada</li>@endforelse
        </ul>
    @endif
</x-filament-panels::page>
