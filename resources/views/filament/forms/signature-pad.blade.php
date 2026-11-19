<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            state: $wire.$entangle(@js($getStatePath())),
            drawing: false, ctx: null, dirty: false,
            init() {
                const c = this.$refs.c; this.ctx = c.getContext('2d');
                // Ukuran kanvas mengikuti lebar tampilnya. Saat modal masih tersembunyi lebarnya 0, jadi dihitung ulang begitu terlihat
                // (ResizeObserver) dan sebelum menggambar; tanda tangan yang sudah ada digambar ulang supaya tidak hilang.
                const size = () => {
                    const w = Math.floor(c.parentElement.clientWidth);
                    if (w <= 0 || c.width === w) return;
                    const keep = this.dirty ? c.toDataURL('image/png') : null;
                    c.width = w; c.height = 160; this.ctx.lineWidth = 2; this.ctx.lineCap = 'round'; this.ctx.strokeStyle = '#111';
                    if (keep) { const im = new Image(); im.onload = () => this.ctx.drawImage(im, 0, 0); im.src = keep; }
                };
                size();
                if (window.ResizeObserver) new ResizeObserver(size).observe(c.parentElement);
                const pos = e => { const b = c.getBoundingClientRect(); const t = e.touches ? e.touches[0] : e; return [t.clientX - b.left, t.clientY - b.top]; };
                const start = e => { e.preventDefault(); size(); this.drawing = true; const [x, y] = pos(e); this.ctx.beginPath(); this.ctx.moveTo(x, y); };
                const move = e => { if (!this.drawing) return; e.preventDefault(); const [x, y] = pos(e); this.ctx.lineTo(x, y); this.ctx.stroke(); this.dirty = true; };
                const end = () => { if (!this.drawing) return; this.drawing = false; if (this.dirty) this.state = c.toDataURL('image/png'); };
                c.addEventListener('mousedown', start); c.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
                c.addEventListener('touchstart', start, { passive: false }); c.addEventListener('touchmove', move, { passive: false }); c.addEventListener('touchend', end);
            },
            clear() { this.ctx.clearRect(0, 0, this.$refs.c.width, this.$refs.c.height); this.state = null; this.dirty = false; }
        }"
        wire:ignore
    >
        <div style="border:1px dashed #9ca3af;border-radius:8px;background:#fff;touch-action:none">
            <canvas x-ref="c" style="display:block;width:100%;height:160px"></canvas>
        </div>
        <button type="button" x-on:click="clear()" class="fi-link text-sm" style="margin-top:4px;color:#1f78b0">Hapus tanda tangan</button>
    </div>
</x-dynamic-component>
