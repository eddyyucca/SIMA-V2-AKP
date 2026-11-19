<div class="text-sm">
    @forelse($rows as $r)
        <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid rgba(127,127,127,.2)">
            <span>{{ $r['name'] }}</span><b>{{ $r['detail'] }}</b>
        </div>
    @empty
        <p style="color:#888">Tidak ada.</p>
    @endforelse
</div>
