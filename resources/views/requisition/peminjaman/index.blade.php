<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peminjaman Aset - {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logo-akp.png') }}">
    <meta name="theme-color" content="#f2f6fa">
    @include('requisition.partials.theme-head')
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }</style>
</head>
<body class="text-slate-200 h-screen flex overflow-hidden">

    <div id="toastContainer" class="fixed top-4 right-4 z-[100] flex flex-col gap-3 w-[calc(100%-2rem)] max-w-sm pointer-events-none"></div>

    <!-- Backdrop mobile - tap di luar sidebar untuk menutup drawer -->
    <div id="sidebarBackdrop" onclick="closeSidebar()" class="hidden fixed inset-0 z-30 bg-black/60 lg:hidden"></div>

    <!-- Sidebar Left (versi ringkas - navigasi saja, tanpa filter/stat) -->
    <aside id="sidebar" class="w-64 glass-card p-6 flex flex-col justify-between fixed lg:static inset-y-0 left-0 z-40 h-screen overflow-y-auto -translate-x-full lg:translate-x-0 transition-transform duration-300">
        <div>
            <div class="mb-8 flex items-start justify-between">
                <div>
                    <img src="{{ asset('images/logo-akp.png') }}" alt="AKP Logo" class="h-10 w-auto mb-2">
                    <p class="text-sm text-white font-medium">PT Adhi Kartiko Pratama Tbk</p>
                </div>
                <button type="button" onclick="closeSidebar()" title="Tutup Menu" class="md:hidden text-slate-400 hover:text-white text-2xl font-bold leading-none">
                    &times;
                </button>
            </div>

            <nav class="space-y-2">
                <a href="{{ route('dashboard') }}" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl bg-slate-900/80 hover:bg-slate-800 text-slate-300 font-semibold text-sm border border-slate-800/80 transition-all duration-200">
                    <span><svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.12C3 12.5 3.5 12 4.13 12h2.25c.62 0 1.12.5 1.12 1.13v6.75c0 .62-.5 1.12-1.12 1.12H4.13C3.5 21 3 20.5 3 19.88v-6.75ZM9.75 8.62c0-.62.5-1.12 1.13-1.12h2.25c.62 0 1.12.5 1.12 1.12v11.25c0 .62-.5 1.13-1.12 1.13h-2.25c-.62 0-1.13-.5-1.13-1.12V8.63ZM16.5 4.13c0-.62.5-1.13 1.13-1.13h2.25c.62 0 1.12.5 1.12 1.13v15.75c0 .62-.5 1.12-1.12 1.12h-2.25c-.62 0-1.13-.5-1.13-1.12V4.13Z"/></svg> Dashboard</span>
                </a>
                <a href="{{ route('requests.create') }}" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl bg-gradient-to-r from-fuchsia-600 to-purple-600 text-white font-bold text-sm shadow-lg shadow-fuchsia-600/30 transition-all duration-200">
                    <span><svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg> Buat Request Baru</span>
                </a>
                <a href="{{ route('peminjaman.index') }}" class="flex items-center space-x-3 px-3 py-2 rounded-xl bg-gradient-to-r from-fuchsia-600/30 to-purple-600/10 text-fuchsia-300 border-l-4 border-fuchsia-500 font-medium text-sm shadow-md">
                    <span><svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/></svg> Peminjaman</span>
                </a>
            </nav>
        </div>

        <div class="pt-4 border-t border-slate-800 space-y-3">
            <div class="flex items-center gap-2">
                @if (auth()->user()->isAdmin())
                    <a href="{{ \App\Filament\Resources\Employees\EmployeeResource::getUrl() }}" title="Kelola Akun &amp; akses login (IT Asset › Karyawan)" class="flex-1 flex items-center justify-center gap-2 px-3 py-2 rounded-xl bg-slate-900/80 hover:bg-slate-800 text-slate-300 text-xs font-semibold border border-slate-800/80 transition-all duration-200">
                        <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.59 3.94c.09-.54.56-.94 1.11-.94h2.59c.55 0 1.02.4 1.11.94l.21 1.28c.07.37.32.68.65.87.07.04.15.08.22.12.35.18.77.2 1.12.05l1.2-.48c.51-.2 1.09 0 1.37.48l1.3 2.24c.28.48.17 1.09-.25 1.45l-1.01.83c-.29.24-.44.6-.42.97a6.6 6.6 0 0 1 0 .25c-.02.37.13.73.42.97l1.01.83c.42.36.53.97.25 1.45l-1.3 2.24c-.28.48-.86.68-1.37.48l-1.2-.48a1.28 1.28 0 0 0-1.12.05c-.07.04-.15.08-.22.12-.33.19-.58.5-.65.87l-.21 1.28c-.09.54-.56.94-1.11.94H10.7c-.55 0-1.02-.4-1.11-.94l-.21-1.28a1.28 1.28 0 0 0-.65-.87 5.5 5.5 0 0 1-.22-.12c-.35-.18-.77-.2-1.12-.05l-1.2.48c-.51.2-1.09 0-1.37-.48l-1.3-2.24a1.13 1.13 0 0 1 .25-1.45l1.01-.83c.29-.24.44-.6.42-.97a6.6 6.6 0 0 1 0-.25c.02-.37-.13-.73-.42-.97l-1.01-.83a1.13 1.13 0 0 1-.25-1.45l1.3-2.24c.28-.48.86-.68 1.37-.48l1.2.48c.35.14.77.13 1.12-.05.07-.04.15-.08.22-.12.33-.19.58-.5.65-.87l.21-1.28ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg> Kelola Akun
                    </a>
                    <a href="{{ \Filament\Facades\Filament::getUrl() }}" title="Kembali ke IT Asset" class="flex-1 flex items-center justify-center gap-2 px-3 py-2 rounded-xl bg-slate-900/80 hover:bg-slate-800 text-slate-300 text-xs font-semibold border border-slate-800/80 transition-all duration-200">
                        <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.06-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.38a1.5 1.5 0 0 1-1.06-.44Z"/></svg> IT Asset
                    </a>
                @endif
            </div>
            @include('requisition.partials.account-menu')
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 h-screen p-6 md:p-8 space-y-6 overflow-y-auto">
        <div class="shrink-0 flex items-start gap-3">
            <button type="button" onclick="openSidebar()" title="Menu" class="lg:hidden shrink-0 flex items-center justify-center w-10 h-10 rounded-xl bg-slate-900/80 hover:bg-slate-800 text-white border border-slate-800/80 transition-all duration-200">
                ☰
            </button>
            <div>
                <h1 class="text-2xl font-black text-white tracking-wide">Peminjaman Aset</h1>
                <p class="text-xs text-slate-500 mt-1">Semua pengajuan peminjaman aset yang sudah diproses - terpisah dari List All Form. Yang masih Pending ada di panel "Incoming Requests" Dashboard.</p>
            </div>
        </div>

        <div class="glass-card p-6 rounded-2xl shadow-xl">
            <div class="flex flex-col md:flex-row justify-between items-center gap-4 mb-4 shrink-0">
                <div class="relative w-full md:w-64">
                    <input type="text" id="peminjamanSearchInput" placeholder="Cari peminjam / keperluan..." class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-2 text-xs text-slate-200 focus:outline-none focus:border-fuchsia-500 transition">
                </div>
                <select id="peminjamanStatusFilter" class="bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-xs text-slate-300 focus:outline-none focus:border-fuchsia-500">
                    <option value="All">Semua Status</option>
                    <option value="Approved">Approved</option>
                    <option value="In Progress">In Progress</option>
                    <option value="Completed">Completed</option>
                    <option value="Rejected">Rejected</option>
                </select>
            </div>

            <div class="overflow-auto max-h-[65vh]">
                <table class="rtable w-full min-w-[960px] text-left text-xs text-slate-300">
                    <thead class="text-[10px] uppercase tracking-wider text-slate-400 border-b border-slate-800 bg-[#151b2c] sticky top-0 z-10">
                        <tr>
                            <th class="py-3 px-4 whitespace-nowrap">No. Peminjaman</th>
                            <th class="py-3 px-4 whitespace-nowrap">Peminjam / Username</th>
                            <th class="py-3 px-4 whitespace-nowrap">Aset / Keperluan</th>
                            <th class="py-3 px-4 whitespace-nowrap">Tgl Pinjam - Kembali</th>
                            <th class="py-3 px-4 whitespace-nowrap">Durasi</th>
                            <th class="py-3 px-4 whitespace-nowrap">Status</th>
                            <th class="py-3 px-4 whitespace-nowrap">Disetujui Oleh</th>
                            <th class="py-3 px-4 whitespace-nowrap text-center md:sticky md:right-0 md:bg-[#151b2c] md:shadow-[-8px_0_8px_-8px_rgba(0,0,0,0.6)]">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="peminjamanBody" class="divide-y divide-slate-800">
                        @include('requisition.partials.peminjaman-rows', ['items' => $items])
                    </tbody>
                </table>
            </div>

            <div id="peminjamanPagination">
                @include('requisition.partials.peminjaman-pagination', ['items' => $items])
            </div>
        </div>
    </main>

    <!-- Modal Detail (lihat rincian peminjaman) -->
    <div id="detailModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/80 backdrop-blur-sm p-4">
        <div class="glass-card w-full max-w-2xl rounded-2xl p-6 shadow-2xl relative border border-slate-700 max-h-[90vh] overflow-y-auto">
            <button onclick="closeDetailModal()" class="absolute top-4 right-4 text-slate-400 hover:text-white text-2xl font-bold">&times;</button>
            <h2 class="text-lg font-black text-white uppercase tracking-wide mb-4">Detail Peminjaman</h2>
            <div id="detailModalBody" class="space-y-2 text-sm text-slate-300"></div>
        </div>
    </div>

    <!-- Modal Edit Peminjaman (juga dipakai IT untuk melengkapi Spesifikasi & Serial Number aset) -->
    <div id="editPeminjamanModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/80 backdrop-blur-sm p-4">
        <div class="bg-[#111827] w-full max-w-3xl rounded-2xl shadow-2xl border border-slate-800 p-6 md:p-8 max-h-[90vh] overflow-y-auto relative">
            <div id="editPeminjamanStatusOverlay" class="hidden absolute inset-0 z-20 flex flex-col items-center justify-center bg-[#111827]/95 backdrop-blur-sm rounded-2xl">
                <div id="editPeminjamanStatusSpinner" class="text-center">
                    <svg class="animate-spin h-12 w-12 text-white mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p class="text-white text-sm font-semibold mt-4">Menyimpan...</p>
                </div>
                <div id="editPeminjamanStatusSuccess" class="hidden text-center">
                    <svg class="h-14 w-14 text-emerald-400 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <p id="editPeminjamanStatusSuccessText" class="text-white text-base font-semibold mt-4"></p>
                </div>
            </div>

            <div class="flex items-center justify-between pb-4 border-b border-slate-800 mb-6">
                <h2 id="editPeminjamanModalTitle" class="text-xl font-black text-white uppercase tracking-wide">EDIT PEMINJAMAN</h2>
                <button type="button" onclick="closeEditPeminjamanModal()" class="text-slate-400 hover:text-white text-2xl font-bold">&times;</button>
            </div>

            <div id="editPeminjamanFormErrors" class="mb-4 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs space-y-0.5 hidden"></div>

            <form id="editPeminjamanForm" method="POST" class="space-y-4">
                @csrf
                @method('PATCH')
                <div>
                    <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Nama Peminjam</label>
                    @include('requisition.partials.employee-name-input', ['ring' => 'fuchsia', 'id' => 'editPjEmployeeName', 'strict' => false, 'placeholder' => 'Ketik nama untuk memilih ulang dari daftar'])
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-300 uppercase mb-2">NIK Karyawan</label>
                        <input type="text" name="employee_nik" id="editPjEmployeeNik" required pattern="[0-9]*" title="NIK hanya angka" inputmode="numeric" autocomplete="off" oninput="this.value = this.value.replace(/[^0-9]/g, '')" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Department</label>
                        <select name="department" id="editPjDepartment" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                            <option value="">Pilih Dept</option>
                            @foreach ($departmentOptions as $dept)
                                <option value="{{ $dept }}">{{ $dept }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Jabatan</label>
                        <input type="text" name="position" id="editPjPosition" required autocomplete="off" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Keperluan Peminjaman</label>
                    <input type="text" name="title" id="editPjTitle" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Tanggal Pinjam</label>
                        <input type="date" name="loan_start_date" id="editPjLoanStartDate" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Tanggal Kembali</label>
                        <input type="date" name="loan_end_date" id="editPjLoanEndDate" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                    </div>
                </div>
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-xs font-bold text-slate-300 uppercase">Aset yang Dipinjam (Maks. 5)</label>
                        <button type="button" onclick="addEditPjItem()" class="text-xs font-bold text-fuchsia-400 hover:text-fuchsia-300">+ Tambah Aset</button>
                    </div>
                    <div id="editPjItemsContainer" class="space-y-2"></div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Catatan Tambahan</label>
                    <textarea name="description" id="editPjDescription" rows="3" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500"></textarea>
                </div>

                <div class="flex items-center justify-end space-x-3 pt-4 border-t border-slate-800">
                    <button type="button" onclick="closeEditPeminjamanModal()" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-white">Batal</button>
                    <button type="submit" class="bg-gradient-to-r from-fuchsia-600 to-purple-600 text-white font-bold py-2.5 px-6 rounded-xl shadow-lg">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Upload Dokumen -->
    <div id="documentModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="glass-card w-full max-w-2xl max-h-[92vh] overflow-y-auto rounded-2xl shadow-2xl relative border border-slate-700 p-6">
            <div class="flex items-center justify-between pb-4 border-b border-slate-800 mb-4">
                <h2 class="text-sm font-bold text-white uppercase tracking-wide">Upload Dokumen</h2>
                <button type="button" onclick="closeDocumentModal()" class="text-slate-400 hover:text-white text-2xl font-bold">&times;</button>
            </div>
            <div id="documentCurrent" class="hidden mb-4 p-3 rounded-xl bg-slate-800/60 border border-slate-700 text-xs text-slate-300">
                Dokumen saat ini:
                <a id="documentCurrentLink" href="#" target="_blank" class="text-fuchsia-400 hover:underline font-bold break-all"></a>
                <p class="text-[11px] text-slate-500 mt-1">Upload file baru untuk menggantinya.</p>
                <iframe id="documentPreviewFrame" src="" class="hidden w-full h-[50vh] mt-3 rounded-lg border border-slate-700 bg-slate-950"></iframe>
                <p id="documentPreviewNote" class="hidden mt-3 text-[11px] text-amber-400">Preview tidak tersedia untuk file Word/Excel. Klik nama file di atas untuk mengunduh dan membukanya.</p>
            </div>
            <form id="documentForm" method="POST" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-bold text-slate-300 uppercase mb-2">File Dokumen</label>
                    <input type="file" id="documentInput" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-300">
                    <p class="text-[11px] text-slate-500 mt-1">PDF, Word, Excel, atau gambar (JPG/PNG). Maksimal 5 MB.</p>
                </div>
                <div class="flex items-center justify-end space-x-3 pt-2">
                    <button type="button" onclick="closeDocumentModal()" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-white">Batal</button>
                    <button type="submit" class="bg-gradient-to-r from-fuchsia-600 to-purple-600 text-white font-bold py-2.5 px-6 rounded-xl shadow-lg">Upload</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Preview PDF -->
    <div id="pdfModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/80 backdrop-blur-sm p-4">
        <div class="bg-white w-full max-w-3xl h-[90vh] rounded-2xl shadow-2xl relative flex flex-col overflow-hidden">
            <div class="flex items-center justify-between p-3 bg-slate-900">
                <span class="text-white text-sm font-bold">Preview PDF Peminjaman</span>
                <div class="flex items-center gap-3">
                    <a id="pdfModalDownloadLink" href="#" class="text-xs font-bold text-fuchsia-400 hover:text-fuchsia-300">⬇ Download</a>
                    <button onclick="closePdfModal()" class="text-slate-300 hover:text-white text-xl font-bold leading-none">&times;</button>
                </div>
            </div>
            <iframe id="pdfModalFrame" class="flex-1 w-full" src=""></iframe>
        </div>
    </div>

    <script>
        const csrfToken = '{{ csrf_token() }}';

        // Sidebar drawer (mobile) - sidebar-nya "hidden md:flex" di desktop, jadi di mobile satu-
        // satunya jalan masuk (Dashboard, Buat Request Baru, Kelola Akun, dst.) lewat tombol hamburger.
        function openSidebar() {
            document.getElementById('sidebar').classList.remove('-translate-x-full');
            document.getElementById('sidebarBackdrop').classList.remove('hidden');
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.add('-translate-x-full');
            document.getElementById('sidebarBackdrop').classList.add('hidden');
        }

        function submitDeleteForm(action, ids) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = action;
            form.style.display = 'none';

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_token';
            csrfInput.value = csrfToken;
            form.appendChild(csrfInput);

            const methodInput = document.createElement('input');
            methodInput.type = 'hidden';
            methodInput.name = '_method';
            methodInput.value = 'DELETE';
            form.appendChild(methodInput);

            document.body.appendChild(form);
            form.submit();
        }

        function deletePeminjaman(id, ticketNumber) {
            if (!confirm('Yakin ingin menghapus peminjaman ' + (ticketNumber || 'ini') + '? Tindakan ini tidak bisa dibatalkan.')) {
                return;
            }
            submitDeleteForm('{{ url('/requisition/requests') }}/' + id, [id]);
        }

        // Format ISO datetime dari backend ("2026-09-03T00:00:00.000000Z") jadi "03 Sep 2026"
        function formatPjDateTime(iso) {
            if (!iso) return '-';
            const d = new Date(iso);
            if (isNaN(d.getTime())) return iso;
            const day = String(d.getDate()).padStart(2, '0');
            const month = d.toLocaleString('id-ID', { month: 'short' });
            return `${day} ${month} ${d.getFullYear()}`;
        }

        function openDetailModal(item) {
            const rows = [
                ['Peminjam', item.requester_name],
                ['Username', (item.user && item.user.username) || '-'],
                ['NIK', item.nik],
                ['Departemen/Jabatan', (item.department || '-') + (item.position ? ' / ' + item.position : '')],
                ['Keperluan', item.title],
                ['Tanggal Pinjam', formatPjDateTime(item.loan_start_date)],
                ['Tanggal Kembali', formatPjDateTime(item.loan_end_date)],
                ['Durasi', item.loan_duration_label || '-'],
                ['Catatan', item.description],
            ];
            document.getElementById('detailModalBody').innerHTML = rows.map(function (r) {
                return '<div><span class="text-slate-500">' + r[0] + ':</span> <span class="text-white font-medium">' + (r[1] || '-') + '</span></div>';
            }).join('');

            if (Array.isArray(item.items) && item.items.length) {
                const list = item.items.map(function (it) {
                    return '<li>' + (it.name || '-') + (it.spesifikasi ? ' - ' + it.spesifikasi : '') + (it.serial_number ? ' (SN: ' + it.serial_number + ')' : '') + '</li>';
                }).join('');
                document.getElementById('detailModalBody').innerHTML += '<div class="pt-2"><span class="text-slate-500">Aset:</span><ul class="list-disc list-inside text-white">' + list + '</ul></div>';
            }

            const modal = document.getElementById('detailModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeDetailModal() {
            const modal = document.getElementById('detailModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function openPdfModal(pdfUrl) {
            document.getElementById('pdfModalFrame').src = pdfUrl + '?preview=1';
            document.getElementById('pdfModalDownloadLink').href = pdfUrl;
            const modal = document.getElementById('pdfModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closePdfModal() {
            const modal = document.getElementById('pdfModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.getElementById('pdfModalFrame').src = '';
        }

        // Modal Upload Dokumen
        function openDocumentModal(uploadUrl, currentName, viewUrl) {
            document.getElementById('documentForm').action = uploadUrl;
            document.getElementById('documentInput').value = '';
            const current = document.getElementById('documentCurrent');
            const frame = document.getElementById('documentPreviewFrame');
            if (viewUrl) {
                const link = document.getElementById('documentCurrentLink');
                link.href = viewUrl;
                link.textContent = currentName || 'Lihat dokumen';
                current.classList.remove('hidden');
                const canPreview = /\.(pdf|jpe?g|png)$/i.test(currentName || '');
                frame.src = canPreview ? viewUrl : '';
                frame.classList.toggle('hidden', !canPreview);
                document.getElementById('documentPreviewNote').classList.toggle('hidden', canPreview);
            } else {
                current.classList.add('hidden');
                frame.src = '';
            }
            const modal = document.getElementById('documentModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeDocumentModal() {
            const modal = document.getElementById('documentModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.getElementById('documentPreviewFrame').src = '';
        }

        // Notifikasi hasil upload (halaman ini reload penuh setelah submit form biasa)
        function flashToast(title, body, ok) {
            const container = document.getElementById('toastContainer');
            if (!container) return;
            const toast = document.createElement('div');
            toast.className = 'pointer-events-auto p-4 rounded-2xl border-2 shadow-2xl bg-slate-800/95 ' + (ok ? 'border-emerald-400' : 'border-rose-400');
            const t = document.createElement('p'); t.className = 'text-sm font-bold text-white'; t.textContent = (ok ? '✓ ' : '⚠ ') + title;
            const b = document.createElement('p'); b.className = 'text-xs text-slate-300 mt-0.5'; b.textContent = body;
            toast.append(t, b);
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 5000);
        }
        @if (session('success'))
            flashToast('Berhasil', @json(session('success')), true);
        @endif
        @if (session('error'))
            flashToast('Aksi tidak bisa dilakukan', @json(session('error')), false);
        @endif
        @if ($errors->has('document'))
            flashToast('Upload dokumen gagal', @json($errors->first('document')), false);
        @endif

        let peminjamanPage = 1;
        function loadPeminjamanList(page) {
            peminjamanPage = page || 1;
            const params = new URLSearchParams({
                page: peminjamanPage,
                search: document.getElementById('peminjamanSearchInput').value,
                status: document.getElementById('peminjamanStatusFilter').value,
            });

            fetch('{{ route('peminjaman.list') }}?' + params.toString(), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(res => res.json())
                .then(data => {
                    document.getElementById('peminjamanBody').innerHTML = data.rows_html;
                    document.getElementById('peminjamanPagination').innerHTML = data.pagination_html;
                })
                .catch(() => {});
        }

        let peminjamanSearchTimer;
        document.getElementById('peminjamanSearchInput').addEventListener('input', function () {
            clearTimeout(peminjamanSearchTimer);
            peminjamanSearchTimer = setTimeout(function () { loadPeminjamanList(1); }, 350);
        });
        document.getElementById('peminjamanStatusFilter').addEventListener('change', function () {
            loadPeminjamanList(1);
        });

        // ===== Edit Peminjaman (juga dipakai IT melengkapi Spesifikasi & Serial Number aset) =====
        function escapePjAttr(str) {
            const div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }

        function buildEditPjItemRow(idx, it) {
            it = it || {};
            return `
                <div class="edit-pj-item-row grid grid-cols-[24px_1fr_1fr_1fr_auto] gap-2 items-start">
                    <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">${idx + 1}</div>
                    <input type="text" name="items[${idx}][name]" value="${escapePjAttr(it.name)}" placeholder="Nama Aset" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                    <input type="text" name="items[${idx}][spesifikasi]" value="${escapePjAttr(it.spesifikasi)}" placeholder="Spesifikasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                    <input type="text" name="items[${idx}][serial_number]" value="${escapePjAttr(it.serial_number)}" placeholder="Serial Number" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                    <button type="button" onclick="removeEditPjItem(this)" class="text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
                </div>`;
        }

        function updateEditPjRemoveButtons() {
            const rows = document.querySelectorAll('#editPjItemsContainer .edit-pj-item-row');
            rows.forEach((row) => {
                row.querySelector('button').classList.toggle('hidden', rows.length <= 1);
            });
        }

        function reindexEditPjItems() {
            document.querySelectorAll('#editPjItemsContainer .edit-pj-item-row').forEach((row, idx) => {
                row.querySelector('.item-no').textContent = idx + 1;
                row.querySelectorAll('[name^="items["]').forEach((input) => {
                    input.name = input.name.replace(/items\[\d+\]/, `items[${idx}]`);
                });
            });
        }

        function addEditPjItem() {
            const container = document.getElementById('editPjItemsContainer');
            const rows = container.querySelectorAll('.edit-pj-item-row');
            if (rows.length >= 5) return;
            container.insertAdjacentHTML('beforeend', buildEditPjItemRow(rows.length, {}));
            updateEditPjRemoveButtons();
        }

        function removeEditPjItem(btn) {
            btn.closest('.edit-pj-item-row').remove();
            reindexEditPjItems();
            updateEditPjRemoveButtons();
        }

        function openEditPeminjamanModal(item) {
            document.getElementById('editPeminjamanFormErrors').classList.add('hidden');
            document.getElementById('editPeminjamanStatusOverlay').classList.add('hidden');
            document.getElementById('editPeminjamanForm').action = `{{ url('/requisition/requests') }}/${item.id}`;
            document.getElementById('editPeminjamanModalTitle').innerText = 'EDIT PEMINJAMAN — ' + (item.ticket_number || 'Menunggu Approval');
            document.getElementById('editPjEmployeeName').value = item.requester_name || '';
            document.getElementById('editPjEmployeeNik').value = (item.nik && item.nik !== '-') ? item.nik : '';
            document.getElementById('editPjDepartment').value = (item.department && item.department !== '-') ? item.department : '';
            document.getElementById('editPjPosition').value = item.position || '';
            if (window.EmployeePicker) EmployeePicker.hydrate(document.getElementById('editPeminjamanForm'), item);
            document.getElementById('editPjTitle').value = item.title || '';
            document.getElementById('editPjLoanStartDate').value = item.loan_start_date ? item.loan_start_date.slice(0, 10) : '';
            document.getElementById('editPjLoanEndDate').value = item.loan_end_date ? item.loan_end_date.slice(0, 10) : '';
            document.getElementById('editPjDescription').value = item.description || '';

            const container = document.getElementById('editPjItemsContainer');
            container.innerHTML = '';
            const items = (Array.isArray(item.items) && item.items.length) ? item.items : [{}];
            items.forEach((it, idx) => container.insertAdjacentHTML('beforeend', buildEditPjItemRow(idx, it)));
            updateEditPjRemoveButtons();

            const modal = document.getElementById('editPeminjamanModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeEditPeminjamanModal() {
            const modal = document.getElementById('editPeminjamanModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function showEditPjFormErrors(errors) {
            const box = document.getElementById('editPeminjamanFormErrors');
            const messages = Object.values(errors || {}).flat();
            box.innerHTML = messages.map(function (m) { return '<div>• ' + m + '</div>'; }).join('');
            box.classList.remove('hidden');
            box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        document.getElementById('editPeminjamanForm').addEventListener('submit', function (e) {
            e.preventDefault();
            document.getElementById('editPeminjamanFormErrors').classList.add('hidden');
            document.getElementById('editPeminjamanStatusSpinner').classList.remove('hidden');
            document.getElementById('editPeminjamanStatusSuccess').classList.add('hidden');
            document.getElementById('editPeminjamanStatusOverlay').classList.remove('hidden');

            const form = this;
            const formData = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
            })
                .then(function (res) {
                    return res.json().catch(function () { return {}; }).then(function (data) {
                        return { ok: res.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (result.ok && result.data.success) {
                        document.getElementById('editPeminjamanStatusSpinner').classList.add('hidden');
                        document.getElementById('editPeminjamanStatusSuccessText').textContent = result.data.message || 'Berhasil disimpan.';
                        document.getElementById('editPeminjamanStatusSuccess').classList.remove('hidden');
                        setTimeout(function () { window.location.reload(); }, 1500);
                    } else {
                        document.getElementById('editPeminjamanStatusOverlay').classList.add('hidden');
                        const errors = (result.data && result.data.errors) ? result.data.errors : {
                            _: [(result.data && result.data.message) || 'Gagal menyimpan, silakan coba lagi.'],
                        };
                        showEditPjFormErrors(errors);
                    }
                })
                .catch(function () {
                    document.getElementById('editPeminjamanStatusOverlay').classList.add('hidden');
                    showEditPjFormErrors({ _: ['Gagal terhubung ke server. Periksa koneksi internet Anda.'] });
                });
        });
    </script>

    @include('requisition.partials.auto-logout')

    <script>
        // Kalau halaman ini direstore dari bfcache (mis. admin klik tombol Back browser) setelah
        // sempat submit form, overlay loading/status yang sudah kepencet sebelumnya ikut ke-restore
        // dalam kondisi masih tampil/muter terus - reset paksa begitu halaman muncul lagi.
        window.addEventListener('pageshow', function (event) {
            if (!event.persisted) return;
            document.querySelectorAll('[id$="Overlay"]').forEach(function (el) { el.classList.add('hidden'); });
        });
    </script>
    @include('requisition.partials.employee-picker-assets')
</body>
</html>
