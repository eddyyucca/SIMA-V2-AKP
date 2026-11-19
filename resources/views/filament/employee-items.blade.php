<div class="space-y-3 text-sm">
    <div>
        <b>Aset</b>
        <ul class="list-disc pl-5">
            @forelse($employee->assets as $a)
                <li>{{ $a->asset_tag }} — {{ $a->brand }} {{ $a->model }} ({{ \App\Models\Asset::STATUSES[$a->status] }})</li>
            @empty <li>-</li> @endforelse
        </ul>
    </div>
    <div>
        <b>Accessories</b>
        <ul class="list-disc pl-5">
            @forelse($employee->heldAccessories() as $h)
                <li>{{ $h['accessory']?->name }} × {{ $h['qty'] }}</li>
            @empty <li>-</li> @endforelse
        </ul>
    </div>
    <div>
        <b>Seat lisensi</b>
        <ul class="list-disc pl-5">
            @forelse($employee->licenseSeats()->with('license')->get() as $s)
                <li>{{ $s->license?->name }}</li>
            @empty <li>-</li> @endforelse
        </ul>
    </div>
    @include('filament.partials-handover-history', ['handovers' => $employee->accessoryHandovers()->with('items.stockable')->withCount('files')->limit(30)->get()])
</div>
