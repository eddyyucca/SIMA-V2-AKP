<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('images/logo-akp.png') }}">
        <link rel="manifest" href="{{ asset('requisition-manifest.json') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/icon-192.png') }}">
        <meta name="theme-color" content="#f2f6fa">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        <script src="https://cdn.tailwindcss.com"></script>
        @include('requisition.partials.theme-head')
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100">
            @include('requisition.layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>

        @include('requisition.partials.auto-logout')

        <script>
            // Kalau halaman ini direstore dari bfcache (mis. user klik tombol Back browser) setelah
            // sempat submit form, overlay loading/status yang sudah kepencet sebelumnya ikut ke-restore
            // dalam kondisi masih tampil/muter terus - reset paksa begitu halaman muncul lagi.
            window.addEventListener('pageshow', function (event) {
                if (!event.persisted) return;
                document.querySelectorAll('[id$="Overlay"]').forEach(function (el) { el.classList.add('hidden'); });
            });
        </script>
</body>
</html>
