{{-- Memuat skrip kotak cari karyawan / saran barang + alamat pencarian (formulir request ITERA). Formulir TIDAK diisi dari akun login:
     data baru terisi setelah pemohon memilih namanya dari daftar karyawan. --}}
<script>
    window.SR_LOOKUP = { employees: @json(route('lookup.employees')), items: @json(route('lookup.items')) };
</script>
<script src="{{ asset('js/employee-picker.js') }}?v={{ @filemtime(public_path('js/employee-picker.js')) }}" defer></script>
