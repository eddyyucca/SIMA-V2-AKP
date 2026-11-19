<x-filament-panels::page>
    @php
        $sel = 'border:1px solid #d1d5db;border-radius:8px;padding:6px 8px;background:transparent;color:inherit;width:100%';
        $q = $this->query();
    @endphp
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:16px">
        <label>Kategori<select wire:model.live="category_id" style="{{ $sel }}"><option value="">Semua</option>@foreach(\App\Models\Category::where('type','asset')->get() as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
        <label>Lokasi<select wire:model.live="location_id" style="{{ $sel }}"><option value="">Semua</option>@foreach(\App\Models\Location::all() as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
        <label>Department<select wire:model.live="department_id" style="{{ $sel }}"><option value="">Semua</option>@foreach(\App\Models\Department::all() as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
        <label>Status<select wire:model.live="status" style="{{ $sel }}"><option value="">Semua</option>@foreach(\App\Models\Asset::STATUSES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
        <label>Kondisi<select wire:model.live="condition" style="{{ $sel }}"><option value="">Semua</option>@foreach(\App\Models\Asset::CONDITIONS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
        <label>Dari tanggal<input type="date" wire:model.live="from" style="{{ $sel }}"></label>
        <label>Sampai tanggal<input type="date" wire:model.live="to" style="{{ $sel }}"></label>
        <label>Tahun (nilai aset)<select wire:model.live="year" style="{{ $sel }}"><option value="">{{ now()->year }} (sekarang)</option>@foreach(range(now()->year - 6, now()->year + 8) as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach</select></label>
        <label style="display:flex;align-items:end;gap:6px"><input type="checkbox" wire:model.live="low"> Stok saja ≤ minimum (daftar belanja)</label>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px">
        @foreach($this->catalog() as $key => $info)
            <div style="background:rgba(127,127,127,.08);border-radius:12px;padding:14px">
                <div style="font-weight:700">{{ $info['title'] }}</div>
                <div style="font-size:12px;color:#888;margin:4px 0 8px">{{ $info['desc'] }}</div>
                <a href="{{ route('reports.download', ['key' => $key, 'format' => 'xlsx'] + $q) }}" style="color:#1f78b0;margin-right:12px">⬇ Excel</a>
                <a href="{{ route('reports.download', ['key' => $key, 'format' => 'pdf'] + $q) }}" style="color:#1f78b0">⬇ PDF</a>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
