{{-- Menu teks ITERA di topbar IT Asset (di samping logo AKP & nama aplikasi), gaya sama dengan menu navigasi ITERA.
     Tim IT ke dashboard approval, lainnya ke halaman kartu request. $mobile = salinan untuk layar kecil. --}}
<a class="sr-topbar-link{{ ($mobile ?? false) ? ' sr-topbar-link-mobile' : '' }}" href="{{ auth()->user()?->isAdmin() ? route('dashboard') : route('requests.create') }}" title="Buka ITERA" aria-label="ITERA">ITERA</a>
