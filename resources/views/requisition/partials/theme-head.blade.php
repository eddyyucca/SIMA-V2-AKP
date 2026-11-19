{{-- Tema terpusat (mode terang "Ivory"): font + satu stylesheet untuk semua halaman. --}}
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/requisition-theme.css') }}?v={{ @filemtime(public_path('css/requisition-theme.css')) }}">
