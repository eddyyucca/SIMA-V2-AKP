<x-filament-panels::page>
    @php
        $emps = \App\Models\Employee::where('status', 'active')->orderBy('name')->get();
        $emp = $employeeId ? \App\Models\Employee::find($employeeId) : null;
        $C = \App\Models\Asset::CONDITIONS;
        $sel = 'border:1px solid #d1d5db;border-radius:8px;padding:6px 8px;background:transparent;color:inherit';
    @endphp
    <div style="max-width:1100px">
        <label style="font-weight:600">Karyawan</label><br>
        <select wire:model.live="employeeId" style="{{ $sel }};min-width:280px">
            <option value="">— pilih karyawan aktif —</option>
            @foreach($emps as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach
        </select>

        @if($emp)
            <h3 style="font-weight:700;margin-top:20px">Aset</h3>
            @forelse(\App\Models\Asset::where('employee_id', $emp->id)->get() as $a)
                <div style="display:flex;gap:10px;align-items:center;padding:6px 0;border-bottom:1px solid rgba(127,127,127,.2)">
                    <input type="checkbox" wire:model="returnAsset.{{ $a->id }}">
                    <span style="flex:1">{{ $a->asset_tag }} — {{ $a->brand }} {{ $a->model }}</span>
                    <select wire:model="assetCond.{{ $a->id }}" style="{{ $sel }}">@foreach($C as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                </div>
            @empty<p style="color:#888">Tidak ada aset.</p>@endforelse

            <h3 style="font-weight:700;margin-top:20px">Accessories</h3>
            @forelse($emp->heldAccessories() as $h)
                <div style="display:flex;gap:10px;align-items:center;padding:6px 0;border-bottom:1px solid rgba(127,127,127,.2)">
                    <input type="checkbox" wire:model="returnAcc.{{ $h['accessory']->id }}">
                    <span style="flex:1">{{ $h['accessory']->name }} × {{ $h['qty'] }}</span>
                    <select wire:model="accCond.{{ $h['accessory']->id }}" style="{{ $sel }}">@foreach($C as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                </div>
            @empty<p style="color:#888">Tidak ada accessories.</p>@endforelse

            <h3 style="font-weight:700;margin-top:20px">Seat lisensi (akan dicabut otomatis)</h3>
            @forelse($emp->licenseSeats()->with('license')->get() as $s)<div>• {{ $s->license?->name }}</div>@empty<p style="color:#888">Tidak ada.</p>@endforelse

            <button type="button" wire:click="process" wire:confirm="Proses offboarding {{ $emp->name }}? Status karyawan menjadi Resigned."
                    style="margin-top:20px;padding:10px 20px;border-radius:8px;background:#1f78b0;color:#fff;font-weight:700">Proses Offboarding</button>
        @endif
    </div>
</x-filament-panels::page>
