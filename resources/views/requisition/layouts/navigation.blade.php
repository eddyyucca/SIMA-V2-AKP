<!-- Toast Notifikasi Dalam Aplikasi (custom, bukan popup bawaan OS) -->
<div id="toastContainer" class="fixed top-4 right-4 z-[100] flex flex-col gap-3 w-[calc(100%-2rem)] max-w-sm pointer-events-none"></div>

<nav x-data="{ open: false }" class="bg-white border-b border-gray-100">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('requests.create') }}">
                        <img src="{{ asset('images/logo-akp.png') }}" alt="PT Adhi Kartiko Pratama Tbk" class="h-9 w-auto">
                    </a>
                </div>

                <!-- Menu semua akun: buat request, request saya, dan (bagi atasan) persetujuan anak buah -->
                @auth
                    @php
                        $navEmp = auth()->user()->employee;
                        $navSubs = $navEmp ? app(\App\Services\RequestSupervisor::class)->subordinates($navEmp)->count() : 0;
                        $navWaiting = $navSubs > 0 ? \App\Models\ITRequest::where('supervisor_employee_id', $navEmp->id)->where('status', \App\Models\ITRequest::AWAITING_SUPERVISOR)->count() : 0;
                    @endphp
                    <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                        <x-nav-link :href="route('requests.create')" :active="request()->routeIs('requests.create')">Buat Request</x-nav-link>
                        <x-nav-link :href="route('requests.mine')" :active="request()->routeIs('requests.mine')">Request Saya</x-nav-link>
                        @if ($navSubs > 0)
                            <x-nav-link :href="route('requests.supervisor')" :active="request()->routeIs('requests.supervisor')">Persetujuan Atasan @if ($navWaiting) <span class="ms-1 inline-flex items-center justify-center rounded-full bg-rose-600 text-white text-[10px] font-bold px-1.5 min-w-[18px] h-[18px]">{{ $navWaiting }}</span> @endif</x-nav-link>
                        @endif
                    </div>
                @endauth

                <!-- 1. HANYA TAMPILKAN LINK DASHBOARD JIKA SUDAH LOGIN & PUNYA AKSES (TIM IT) -->
                @auth
                    @if (auth()->user()->isAdmin() || auth()->user()->can('view inventory'))
                        <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                            @if (auth()->user()->isAdmin())
                                <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                                    {{ __('Dashboard') }}
                                </x-nav-link>
                            @endif
                            @can('view inventory')
                                <x-nav-link :href="\Filament\Facades\Filament::getUrl()" :active="false">
                                    IT Asset
                                </x-nav-link>
                            @endcan
                        </div>
                    @endif
                @endauth
            </div>

            <!-- 2. HANYA TAMPILKAN DROPDOWN PROFIL & LOGOUT JIKA USER SUDAH LOGIN -->
            @auth
                <div class="hidden sm:flex sm:items-center sm:ms-6 sm:gap-3">
                    <button type="button" id="pushNotifBtn" onclick="togglePushNotification()" title="Klik untuk aktifkan notifikasi" class="inline-flex items-center justify-center w-9 h-9 rounded-md text-gray-500 hover:text-gray-700 hover:bg-gray-100 transition">
                        
                    </button>

                    @include('requisition.partials.account-menu', ['dir' => 'down', 'items' => [['href' => \App\Filament\Pages\MyItems::getUrl(), 'label' => 'Barang Saya (IT Asset)']]])

                </div>
            @endauth

            <!-- Hamburger (Mobile Menu) -->
            @auth
                <div class="-me-2 flex items-center sm:hidden">
                    <button type="button" id="navBurger" aria-label="Menu" aria-expanded="false" @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                        <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                            <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            @endauth
        </div>
    </div>

    <!-- Responsive Navigation Menu (Mobile) -->
    @auth
        <div id="navMobile" :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
            <div class="pt-2 pb-1 space-y-1">
                <x-responsive-nav-link :href="route('requests.create')" :active="request()->routeIs('requests.create')">Buat Request</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('requests.mine')" :active="request()->routeIs('requests.mine')">Request Saya</x-responsive-nav-link>
                @if (($navSubs ?? 0) > 0)
                    <x-responsive-nav-link :href="route('requests.supervisor')" :active="request()->routeIs('requests.supervisor')">Persetujuan Atasan{{ ($navWaiting ?? 0) ? ' (' . $navWaiting . ')' : '' }}</x-responsive-nav-link>
                @endif
            </div>
            @if (auth()->user()->isAdmin() || auth()->user()->can('view inventory'))
                <div class="pt-2 pb-3 space-y-1">
                    @if (auth()->user()->isAdmin())
                        <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                            {{ __('Dashboard') }}
                        </x-responsive-nav-link>
                    @endif
                    @can('view inventory')
                        <x-responsive-nav-link :href="\Filament\Facades\Filament::getUrl()">
                            IT Asset
                        </x-responsive-nav-link>
                    @endcan
                </div>
            @endif

            <!-- Responsive Settings Options -->
            <div class="pt-4 pb-1 border-t border-gray-200">
                <div class="px-4">
                    <div class="font-medium text-base text-gray-800">{{ Auth::user()->name }}</div>
                    <div class="font-medium text-sm text-gray-500">{{ Auth::user()->email }}</div>
                </div>

                <div class="mt-3 space-y-1">
                    <x-responsive-nav-link :href="\App\Filament\Pages\MyItems::getUrl()">
                        Barang Saya (IT Asset)
                    </x-responsive-nav-link>

                    <!-- Authentication -->
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf

                        <x-responsive-nav-link :href="route('logout')"
                                onclick="event.preventDefault();
                                            this.closest('form').submit();">
                            {{ __('Log Out') }}
                        </x-responsive-nav-link>
                    </form>
                </div>
            </div>
        </div>
    @endauth
</nav>

<script>
    // Menu HP: halaman ITERA tidak memuat Alpine, jadi tombol hamburger digerakkan di sini.
    (function () {
        var b = document.getElementById('navBurger'), m = document.getElementById('navMobile');
        if (!b || !m || window.Alpine) return;
        b.addEventListener('click', function () {
            var open = m.classList.toggle('block'); m.classList.toggle('hidden', !open);
            b.setAttribute('aria-expanded', open ? 'true' : 'false');
            var p = b.querySelectorAll('path'); if (p.length > 1) { p[0].classList.toggle('hidden', open); p[0].classList.toggle('inline-flex', !open); p[1].classList.toggle('hidden', !open); p[1].classList.toggle('inline-flex', open); }
        });
    })();
</script>

@auth

    <script>
        // ===== Push Notification (browser) =====
        // Tim IT (admin/super_admin) dapat notifikasi "Request Baru Masuk".
        // Role user dapat notifikasi "Status Request Berubah" untuk request miliknya sendiri.
        const vapidPublicKey = @json(config('webpush.vapid.public_key'));
        const pushNotifIsAdmin = @json(auth()->user()->isAdmin());

        function urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; i++) {
                outputArray[i] = rawData.charCodeAt(i);
            }
            return outputArray;
        }

        async function updatePushButtonState() {
            const btn = document.getElementById('pushNotifBtn');
            if (!btn || !('serviceWorker' in navigator) || !('PushManager' in window)) return;

            const registration = await navigator.serviceWorker.getRegistration();
            const subscription = registration ? await registration.pushManager.getSubscription() : null;
            const purpose = pushNotifIsAdmin ? 'request baru' : 'status request Anda';

            if (subscription && Notification.permission === 'granted') {
                btn.textContent = '';
                btn.title = 'Notifikasi ' + purpose + ' aktif (klik untuk matikan)';
            } else {
                btn.textContent = '';
                btn.title = 'Klik untuk aktifkan notifikasi ' + purpose;
            }
        }

        async function togglePushNotification() {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                alert('Browser ini tidak mendukung push notification.');
                return;
            }

            const registration = await navigator.serviceWorker.register('/service-worker.js');
            const existingSubscription = await registration.pushManager.getSubscription();

            if (existingSubscription) {
                await fetch('{{ route('push.unsubscribe') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ endpoint: existingSubscription.endpoint }),
                });
                await existingSubscription.unsubscribe();
                updatePushButtonState();
                return;
            }

            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                alert('Izin notifikasi ditolak. Anda bisa mengaktifkannya lagi lewat pengaturan browser.');
                return;
            }

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
            });

            await fetch('{{ route('push.subscribe') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify(subscription.toJSON()),
            });

            updatePushButtonState();
        }

        // Bunyi notifikasi disintesis langsung (2 nada pendek) - tidak perlu file audio
        function playNotificationSound() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                [880, 1174.66].forEach(function (freq, i) {
                    const o = ctx.createOscillator();
                    const g = ctx.createGain();
                    o.connect(g);
                    g.connect(ctx.destination);
                    o.type = 'sine';
                    o.frequency.value = freq;
                    const start = ctx.currentTime + i * 0.15;
                    g.gain.setValueAtTime(0.0001, start);
                    g.gain.exponentialRampToValueAtTime(0.25, start + 0.02);
                    g.gain.exponentialRampToValueAtTime(0.0001, start + 0.35);
                    o.start(start);
                    o.stop(start + 0.4);
                });
            } catch (e) {
                // Web Audio tidak didukung browser ini, abaikan saja
            }
        }

        // ===== Toast Notifikasi Dalam Aplikasi (custom, bukan popup bawaan OS) =====
        function showToast({ icon = '', title = '', body = '', accent = 'fuchsia' } = {}) {
            // Bg card sengaja lebih terang dari bg halaman (#0b0f19) supaya tetap kontras di mode gelap;
            // border pakai warna solid (bukan translucent) biar aksennya jelas kelihatan.
            const accentMap = {
                fuchsia: 'border-fuchsia-400 bg-fuchsia-500/20',
                emerald: 'border-emerald-400 bg-emerald-500/20',
                rose: 'border-rose-400 bg-rose-500/20',
                amber: 'border-amber-400 bg-amber-500/20',
            };
            const container = document.getElementById('toastContainer');
            if (!container) return;

            const toast = document.createElement('div');
            toast.className = 'pointer-events-auto flex items-start gap-3 p-4 rounded-2xl border-2 backdrop-blur-md shadow-2xl ring-1 ring-white/10 bg-slate-800/95 ' +
                (accentMap[accent] || accentMap.fuchsia) +
                ' transition-all duration-300 ease-out opacity-0 translate-x-6';
            // Judul/isi bisa berasal dari input user (misal judul request) — dirakit via textContent,
            // bukan innerHTML, supaya tidak jadi celah XSS kalau isinya mengandung tag HTML.
            toast.innerHTML = `
                <div class="toast-icon text-2xl leading-none shrink-0"></div>
                <div class="min-w-0 flex-1">
                    <p class="toast-title text-sm font-bold text-white truncate"></p>
                    <p class="toast-body text-xs text-slate-400 mt-0.5 line-clamp-2"></p>
                </div>
                <button type="button" class="shrink-0 text-slate-500 hover:text-white text-lg leading-none">&times;</button>
            `;
            toast.querySelector('.toast-icon').textContent = icon;
            toast.querySelector('.toast-title').textContent = title;
            toast.querySelector('.toast-body').textContent = body;

            function dismiss() {
                toast.classList.add('opacity-0', 'translate-x-6');
                setTimeout(() => toast.remove(), 300);
            }

            toast.querySelector('button').addEventListener('click', dismiss);
            container.appendChild(toast);

            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    toast.classList.remove('opacity-0', 'translate-x-6');
                });
            });

            setTimeout(dismiss, 6000);
        }

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/service-worker.js').then(function () {
                updatePushButtonState();
            });

            navigator.serviceWorker.addEventListener('message', function (event) {
                if (event.data && event.data.type === 'PLAY_NOTIFICATION_SOUND') {
                    playNotificationSound();
                }
            });
        }
    </script>
@endauth