<div x-data="{
    on: false, s: null, err: '',
    async toggle() {
        if (this.on) { await this.s.stop(); this.on = false; return; }
        try {
            this.s = new Html5Qrcode($refs.r);
            let last = ''; let t0 = 0;
            await this.s.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 250, height: 250 } },
                (text) => { const n = Date.now(); if (text === last && n - t0 < 3000) return; last = text; t0 = n; $wire.onScan(text); }, () => {});
            this.on = true; this.err = '';
        } catch (e) { this.err = 'Kamera tidak bisa dibuka (butuh HTTPS & izin kamera). Gunakan input kode manual di bawah.'; }
    }
}" wire:ignore>
    <button type="button" x-on:click="toggle()" style="padding:8px 16px;border-radius:8px;background:#1f78b0;color:#fff;font-weight:600" x-text="on ? 'Matikan kamera' : 'Buka kamera & scan'"></button>
    <div x-ref="r" x-show="on" style="width:100%;max-width:420px;margin-top:8px"></div>
    <p x-show="err" x-text="err" style="color:#dc2626;font-size:13px;margin-top:6px"></p>
</div>
