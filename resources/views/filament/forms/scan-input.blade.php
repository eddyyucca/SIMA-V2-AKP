<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div x-data="{
        state: $wire.$entangle(@js($getStatePath())),
        on: false, s: null, err: '',
        async toggle() {
            if (this.on) { await this.s.stop(); this.on = false; return; }
            try {
                this.s = new Html5Qrcode($refs.r);
                await this.s.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 250, height: 120 } },
                    async (t) => { this.state = t; await this.s.stop(); this.on = false; }, () => {});
                this.on = true; this.err = '';
            } catch (e) { this.err = 'Kamera tidak bisa dibuka (butuh HTTPS & izin kamera). Ketik manual saja.'; }
        }
    }" wire:ignore>
        <div style="display:flex;gap:8px">
            <input type="text" x-model="state" style="flex:1;border:1px solid #d1d5db;border-radius:8px;padding:6px 10px;background:transparent;color:inherit" />
            <button type="button" x-on:click="toggle()" style="padding:6px 12px;border-radius:8px;background:#1f78b0;color:#fff" x-text="on ? 'Stop' : 'Scan'"></button>
        </div>
        <div x-ref="r" x-show="on" style="width:100%;max-width:420px;margin-top:8px"></div>
        <p x-show="err" x-text="err" style="color:#dc2626;font-size:12px;margin-top:4px"></p>
    </div>
</x-dynamic-component>
