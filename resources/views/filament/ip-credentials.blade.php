<div class="text-sm" x-data="{
    copied: null,
    copy(text, key) {
        const done = () => { this.copied = key; setTimeout(() => this.copied = null, 1500); };
        if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done); return; }
        const t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
        document.body.appendChild(t); t.select(); try { document.execCommand('copy'); done(); } catch (e) {} document.body.removeChild(t);
    }
}">
    @foreach([['Perangkat', $record->device_name, null], ['IP Address', $record->ip_address, 'ip'], ['Username', $record->username, 'user'], ['Password', $record->password, 'pass']] as [$label, $value, $key])
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid rgba(127,127,127,.2)">
            <span style="color:#888;min-width:90px">{{ $label }}</span>
            <code style="flex:1;word-break:break-all;font-size:13px">{{ filled($value) ? $value : '-' }}</code>
            @if($key && filled($value))
                <button type="button" x-on:click="copy(@js((string) $value), '{{ $key }}')" style="padding:3px 10px;border-radius:6px;border:1px solid #d1d5db;font-size:12px">
                    <span x-show="copied !== '{{ $key }}'">Salin</span><span x-show="copied === '{{ $key }}'" x-cloak>Tersalin ✓</span>
                </button>
            @endif
        </div>
    @endforeach
    <p style="color:#888;font-size:12px;margin-top:10px">Pembukaan password dicatat di audit log. Jangan dibagikan lewat chat atau email.</p>
</div>
