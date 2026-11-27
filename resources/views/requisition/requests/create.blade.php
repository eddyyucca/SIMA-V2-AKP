<x-app-layout>
    <style>
        .rq-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; max-width: 980px; margin: 0 auto; }
        @media (min-width: 1100px) { .rq-grid { grid-template-columns: repeat(var(--n, 6), minmax(0, 1fr)); max-width: 1240px; } }
        @media (max-width: 560px) { .rq-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; } .rq-d { display: none !important; } }
        .rq-card { --c: #1f6fb5; --s: #e8f1fa; display: flex; flex-direction: column; gap: 10px; text-align: left; padding: 16px 16px 14px; border-radius: 16px; background: #fff; border: 1px solid #e1eaf5; border-top: 3px solid var(--c);
            box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 8px 24px -10px rgba(15,23,42,.12); color: #10264a; cursor: pointer; transition: transform .18s, box-shadow .18s, border-color .18s; font-family: inherit; }
        .rq-card:hover { transform: translateY(-3px); box-shadow: 0 18px 32px -14px rgba(15,23,42,.28); border-color: var(--c); }
        button.rq-card, button.rq-card:nth-child(even) { filter: none !important; }   /* tema lama memucatkan tombol openModalForm bernomor genap */
        .rq-card:focus-visible { outline: 3px solid #29aae1; outline-offset: 2px; }
        .rq-top { display: flex; align-items: center; justify-content: space-between; }
        .rq-ico { width: 40px; height: 40px; border-radius: 11px; background: var(--s); color: var(--c); display: grid; place-items: center; }
        .rq-ico svg { width: 21px; height: 21px; }
        .rq-go { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; color: #64748b; background: rgba(15,23,42,.04); transition: .18s; }
        .rq-go svg { width: 14px; height: 14px; }
        .rq-card:hover .rq-go { background: var(--c); color: #fff; transform: translateX(2px); }
        .rq-k { display: block; font-size: .68rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: #64748b; }
        .rq-n { display: block; font-size: 1.2rem; font-weight: 800; letter-spacing: -.01em; line-height: 1.15; margin-top: 1px; }
        .rq-d { font-size: .8rem; line-height: 1.4; color: #64748b; }
        .public-scroll {
            scrollbar-width: thin;
            scrollbar-color: #475569 #0b0f19;
        }
        .public-scroll::-webkit-scrollbar { width: 10px; height: 10px; }
        .public-scroll::-webkit-scrollbar-track { background: #0b0f19; border-radius: 8px; }
        .public-scroll::-webkit-scrollbar-thumb { background: #475569; border-radius: 8px; }
        .public-scroll::-webkit-scrollbar-thumb:hover { background: #64748b; }
    </style>
    <div class="py-12 bg-slate-950 min-h-screen text-slate-100">
        <div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8">

            <!-- ALERT ERROR VALIDASI -->
            @if ($errors->any())
                <div id="errorAlert" class="mb-6 p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 shadow-lg">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-bold text-sm">Request gagal disimpan, periksa kembali isian berikut:</span>
                        <button onclick="document.getElementById('errorAlert').remove()" class="text-rose-400 hover:text-white font-bold text-xl leading-none">&times;</button>
                    </div>
                    <ul class="text-sm list-disc list-inside space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @php
                $isLockedUser = auth()->check() && auth()->user()->role === 'user';
                $departmentOptions = \App\Models\Department::names();   // satu sumber: tabel departments (IT Asset)
                $ticketCategoryOptions = [
                    'Hardware', 'Software', 'Network', 'Account & Access Sharing Folder',
                ];
            @endphp

            <div class="text-center mb-10">
                <h1 class="text-3xl font-black tracking-wider text-white uppercase">Pilih Jenis Request</h1>
            </div>

            <!-- Pilihan jenis request: 5 kartu untuk role user (tanpa Surat Jalan), 6 untuk role lain -->
            @php
                $rqIc = [
                    'ticket' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z"/>',
                    'doc' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>',
                    'warn' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>',
                    'truck' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/>',
                    'wrench' => '<path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z"/>',
                    'loan' => '<path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/>',
                ];
                $rqCards = array_values(array_filter([
                    ['TICKET_SISTEM', 'Laporan Kendala', 'Ticket Sistem', 'Laporkan kendala aplikasi, jaringan, atau perangkat.', 'ticket', '#d97706', '#fdf3df'],
                    ['ITR', 'Request Form', 'ITR', 'Ajukan kebutuhan perangkat atau layanan IT.', 'doc', '#7c3aed', '#f1ebfd'],
                    ['BAK', 'Berita Acara', 'BAK', 'Laporkan perangkat rusak atau hilang beserta foto bukti.', 'warn', '#0891b2', '#e3f5f9'],
                    $isLockedUser ? null : ['SURAT_JALAN', 'Pengiriman', 'Surat Jalan', 'Buat surat jalan untuk pengiriman barang.', 'truck', '#6d28d9', '#efe9fb'],
                    ['WO', 'Work Order', 'WO', 'Minta pekerjaan atau perbaikan oleh Tim IT.', 'wrench', '#059669', '#e1f5ee'],
                    ['PEMINJAMAN_ASET', 'Peminjaman', 'Aset', 'Pinjam perangkat untuk sementara waktu.', 'loan', '#e11d48', '#fdecee'],
                ]));
            @endphp
            <div class="rq-grid" id="requestTypeGrid" style="--n:{{ count($rqCards) }}">
                @foreach ($rqCards as [$rqType, $rqKicker, $rqName, $rqDesc, $rqIcon, $rqC, $rqS])
                    <button type="button" class="rq-card" data-type="{{ $rqType }}" onclick="openModalForm('{{ $rqType }}')" style="--c:{{ $rqC }};--s:{{ $rqS }}">
                        <span class="rq-top">
                            <span class="rq-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">{!! $rqIc[$rqIcon] !!}</svg></span>
                            <span class="rq-go" aria-hidden="true"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.638L10.23 5.29a.75.75 0 1 1 1.04-1.08l5.5 5.25a.75.75 0 0 1 0 1.08l-5.5 5.25a.75.75 0 1 1-1.04-1.08l4.158-3.96H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd"/></svg></span>
                        </span>
                        <span><span class="rq-k">{{ $rqKicker }}</span><span class="rq-n">{{ $rqName }}</span></span>
                        <span class="rq-d">{{ $rqDesc }}</span>
                    </button>
                @endforeach
            </div>

            <!-- Aset & barang yang sedang dipakai pengguna (dari IT Asset) -->

            <!-- Penjelasan maksud & tujuan tiap jenis form -->
            <div class="mt-6 max-w-screen-2xl mx-auto">
                <div class="text-center">
                    <button type="button" id="formGuideToggle" onclick="toggleFormGuide()" aria-expanded="false" aria-controls="formGuidePanel"
                        class="text-xs font-bold text-fuchsia-400 hover:text-fuchsia-300 underline underline-offset-4 transition">
                        Baca selengkapnya: apa maksud &amp; tujuan tiap form? ▾
                    </button>
                </div>
                <div id="formGuidePanel" class="hidden mt-4 bg-[#111827] border border-slate-800 rounded-2xl p-5 sm:p-6">
                    <p class="text-xs text-slate-400 mb-4">Semua request wajib melalui approval Tim IT. Nomor tiket resmi baru terbit setelah request disetujui. ITR dan Peminjaman Aset dari akun karyawan lebih dulu disetujui atasan langsung (sesuai data HRIS); bila atasan belum menjawab {{ \App\Services\RequestSupervisor::ESCALATE_AFTER_DAYS }} hari, request diteruskan otomatis ke Tim IT.</p>
                    <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div>
                            <dt class="font-bold text-amber-400 uppercase tracking-wide">Ticket Sistem — Laporan Kendala</dt>
                            <dd class="text-slate-300 mt-1">Dipakai untuk melaporkan kendala atau masalah IT. Wajib memilih kategori: Hardware, Software, Network, atau Account &amp; Access / Sharing Folder.</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-purple-400 uppercase tracking-wide">ITR — IT Request</dt>
                            <dd class="text-slate-300 mt-1">Dipakai untuk mengajukan kebutuhan barang, perangkat, atau kebutuhan IT umum.</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-cyan-400 uppercase tracking-wide">BAK — Berita Acara</dt>
                            <dd class="text-slate-300 mt-1">Dipakai untuk melaporkan kerusakan atau kehilangan aset IT. Tim IT akan menganalisa dan memberikan rekomendasi, lalu menerbitkan PDF setelah disetujui.</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-emerald-400 uppercase tracking-wide">WO — Work Order</dt>
                            <dd class="text-slate-300 mt-1">Dipakai untuk meminta instalasi infrastruktur IT di lapangan: jaringan (kabel LAN, Access Point, switch), CCTV, rak server, UPS, dan sejenisnya. Tulis lokasi, jumlah titik, dan perangkat/material yang dibutuhkan.</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-rose-400 uppercase tracking-wide">Peminjaman Aset</dt>
                            <dd class="text-slate-300 mt-1">Dipakai untuk meminjam aset atau perangkat IT. Isi Tanggal Pinjam dan Tanggal Kembali. Peminjaman lebih dari 7 hari wajib dilengkapi PDF approval atasan dan Tim IT, yang nanti terlampir di History Request Terbaru.</dd>
                        </div>
                        @unless ($isLockedUser)
                            <div>
                                <dt class="font-bold text-purple-400 uppercase tracking-wide">Surat Jalan — Pengiriman</dt>
                                <dd class="text-slate-300 mt-1">Dipakai untuk dokumen pengiriman barang atau aset IT keluar. Khusus Tim IT.</dd>
                            </div>
                        @endunless
                    </dl>
                </div>
            </div>
            <script>
                function toggleFormGuide() {
                    const panel = document.getElementById('formGuidePanel');
                    const btn = document.getElementById('formGuideToggle');
                    const open = panel.classList.toggle('hidden') === false;
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    btn.innerHTML = open
                        ? 'Tutup penjelasan ▴'
                        : 'Baca selengkapnya: apa maksud &amp; tujuan tiap form? ▾';
                }
            </script>

            <!-- History Request Terbaru (read-only, tampil untuk semua orang) -->
            <div class="mt-10 bg-[#111827] border border-slate-800 rounded-2xl p-6 max-w-screen-2xl mx-auto">
                <div class="mb-4">
                    <h3 class="text-sm font-bold text-slate-200 uppercase tracking-wide">History Request Terbaru (ITR, Ticket Sistem, BAK, WO & Peminjaman Asset)</h3>
                    <p class="text-xs text-slate-500 mt-1">Status &amp; update terbaru dari request yang sudah masuk</p>
                </div>

                <div class="flex flex-col md:flex-row justify-between items-center gap-4 mb-4">
                    <div class="relative w-full md:w-64">
                        <input type="text" id="publicSearchInput" onkeyup="publicSearchTable()" placeholder="Cari requester / username / judul..." class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-2 text-xs text-slate-200 focus:outline-none focus:border-fuchsia-500 transition">
                    </div>
                </div>

                <div class="public-scroll overflow-auto max-h-[450px]">
                    <table class="rtable w-full text-left text-xs text-slate-300">
                        <thead class="text-[10px] uppercase tracking-wider text-slate-400 border-b border-slate-800 bg-[#151b2c] sticky top-0 z-10">
                            <tr>
                                <th class="py-3 px-4">No. Ticket</th>
                                <th class="py-3 px-4">Requester</th>
                                <th class="py-3 px-4">Judul Request</th>
                                <th class="py-3 px-4">Status</th>
                                <th class="py-3 px-4">Diproses Oleh</th>
                                <th class="py-3 px-4">Tanggal</th>
                                <th class="py-3 px-4 text-center">Aksi</th>
                                <th class="py-3 px-4 text-center">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody id="publicHistoryBody" class="divide-y divide-slate-800">
                            @include('requisition.partials.public-history-rows', ['requests' => $requests])
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal Lihat Form yang Sudah Diisi (khusus request milik sendiri) -->
            <div id="myFormModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
                <div class="bg-[#111827] border border-slate-800 w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl shadow-2xl p-6">
                    <div class="flex items-start justify-between pb-4 border-b border-slate-800">
                        <div>
                            <span id="myFormTicket" class="ticket-no font-mono text-xs font-bold bg-fuchsia-500/10 px-2.5 py-1 rounded-lg border border-fuchsia-500/20"></span>
                            <h2 id="myFormTitle" class="text-lg font-bold text-white mt-2"></h2>
                            <p id="myFormType" class="text-xs text-slate-400 mt-0.5"></p>
                        </div>
                        <button type="button" onclick="closeMyFormModal()" class="text-slate-400 hover:text-white p-1 text-2xl font-bold leading-none">&times;</button>
                    </div>
                    <div id="myFormBody" class="py-4 space-y-4 text-sm"></div>
                    <div class="pt-4 border-t border-slate-800 flex justify-end">
                        <button type="button" onclick="closeMyFormModal()" class="px-4 py-2 bg-fuchsia-600 hover:bg-fuchsia-500 text-white rounded-xl text-xs font-bold transition">Tutup</button>
                    </div>
                </div>
            </div>

            <!-- Modal Preview PDF (sama seperti di dashboard) -->
            <div id="pdfModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
                <div class="bg-[#111827] border border-slate-800 w-full max-w-3xl h-[90vh] rounded-2xl shadow-2xl relative flex flex-col overflow-hidden">

                    <!-- Header Modal -->
                    <div class="flex items-center justify-between px-5 py-3 border-b border-slate-800 shrink-0">
                        <h2 class="text-sm font-bold text-white uppercase tracking-wide">Preview PDF Request</h2>
                        <button onclick="closePdfModal()" class="text-slate-400 hover:text-white p-1 text-xl font-bold">&times;</button>
                    </div>

                    <!-- Body: iframe preview -->
                    <div class="flex-1 bg-slate-950">
                        <iframe id="pdfModalFrame" src="" class="w-full h-full border-0"></iframe>
                    </div>

                    <!-- Footer Modal (Tombol Download) -->
                    <div class="px-5 py-3 border-t border-slate-800 flex justify-between items-center shrink-0">
                        <button onclick="closePdfModal()" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold transition">
                            Tutup
                        </button>
                        <a id="pdfModalDownloadLink" href="#" class="px-4 py-2 bg-fuchsia-600 hover:bg-fuchsia-500 text-white rounded-xl text-xs font-bold transition flex items-center space-x-1">
                            <span>⬇️ Download PDF</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Modal View Keterangan (catatan dari tim IT, read-only untuk user) -->
            <div id="publicNoteModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
                <div class="bg-[#111827] w-full max-w-xl max-h-[80vh] rounded-2xl shadow-2xl border border-slate-800 relative flex flex-col overflow-hidden">
                    <div class="flex items-center justify-between px-6 pt-6 pb-4 border-b border-slate-800 shrink-0">
                        <h2 class="text-sm font-bold text-white uppercase tracking-wide">Keterangan dari Tim IT</h2>
                        <button type="button" onclick="closePublicNoteModal()" class="text-slate-400 hover:text-white text-2xl font-bold leading-none">&times;</button>
                    </div>
                    <div class="public-scroll flex-1 overflow-y-auto px-6 py-4">
                        <p id="publicNoteModalText" class="text-sm text-slate-300 whitespace-pre-line"></p>
                    </div>
                    <div class="flex items-center justify-end px-6 pb-6 pt-4 border-t border-slate-800 shrink-0">
                        <button type="button" onclick="closePublicNoteModal()" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-white">Tutup</button>
                    </div>
                </div>
            </div>

            <!-- Modal Edit Request yang ditolak (perbaiki lalu kirim ulang) -->
            <div id="editRequestModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/80 backdrop-blur-sm p-4">
                <div class="bg-[#111827] w-full max-w-4xl rounded-2xl shadow-2xl border border-slate-800 p-6 md:p-8 max-h-[90vh] overflow-y-auto relative">

                    <!-- Overlay Loading/Sukses di tengah kartu form ini (bukan layar penuh) -->
                    @include('requisition.partials.status-overlay', ['prefix' => 'edit', 'loading' => 'Menyimpan perubahan...', 'withOk' => true])

                    <div class="flex items-center justify-between pb-4 border-b border-slate-800 mb-6">
                        <h2 id="editModalTitle" class="text-xl font-black text-white uppercase tracking-wide">EDIT REQUEST</h2>
                        <button type="button" onclick="closeEditRequestModal()" class="text-slate-400 hover:text-white text-2xl font-bold">&times;</button>
                    </div>

                    <div id="editModalRejectNotice" class="mb-4 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs hidden">
                        Request ini sebelumnya <strong>ditolak</strong>. Perbaiki isian di bawah, lalu kirim ulang untuk diproses kembali oleh tim IT.
                    </div>

                    <div id="editFormErrors" class="mb-4 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs space-y-0.5 hidden"></div>

                    <form id="editRequestForm" method="POST" enctype="multipart/form-data" autocomplete="off" class="space-y-4">
                        @csrf
                        @method('PATCH')
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Nama Karyawan</label>
                            @include('requisition.partials.employee-name-input', ['ring' => 'amber', 'id' => 'editEmployeeName', 'strict' => false, 'placeholder' => 'Ketik nama untuk memilih ulang dari daftar'])
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">NIK Karyawan</label>
                                <input type="text" name="employee_nik" id="editEmployeeNik" required pattern="[0-9]*" title="NIK hanya angka" inputmode="numeric" autocomplete="off" oninput="this.value = this.value.replace(/[^0-9]/g, '')" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Department</label>
                                <select name="department" id="editDepartment" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                                    <option value="">Pilih Dept</option>
                                    @foreach ($departmentOptions as $dept)
                                        <option value="{{ $dept }}">{{ $dept }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Jabatan</label>
                                <input type="text" name="position" id="editPosition" required autocomplete="off" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                            </div>
                        </div>

                        <div id="editTitleWrapper">
                            <label id="editTitleLabel" class="block text-xs font-bold text-slate-300 uppercase mb-2">Judul Permintaan</label>
                            <input type="text" name="title" id="editTitle" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                        </div>

                        <div id="editCategoryWrapper" class="hidden">
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Kategori</label>
                            <select name="category" id="editCategory" disabled class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                                <option value="" disabled>Pilih Kategori</option>
                                @foreach ($ticketCategoryOptions as $category)
                                    <option value="{{ $category }}">{{ $category }}</option>
                                @endforeach
                            </select>
                        </div>


                        <div id="editLoanDatesWrapper" class="hidden grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Tanggal Pinjam</label>
                                <input type="date" name="loan_start_date" id="editLoanStartDate" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Tanggal Kembali</label>
                                <input type="date" name="loan_end_date" id="editLoanEndDate" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                            </div>
                        </div>

                        <div id="editItemsWrapper">
                            <div class="flex items-center justify-between mb-2">
                                <label id="editItemsLabel" class="block text-xs font-bold text-slate-300 uppercase">Device / Barang yang Diminta (Maks. 5)</label>
                                <button type="button" onclick="addEditItem()" class="text-xs font-bold text-amber-400 hover:text-amber-300">+ Tambah</button>
                            </div>
                            <div id="editItemsContainer" class="space-y-2"></div>
                        </div>

                        <div>
                            <label id="editDescriptionLabel" class="block text-xs font-bold text-slate-300 uppercase mb-2">Deskripsi / Alasan Permintaan</label>
                            <textarea name="description" id="editDescription" rows="3" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500"></textarea>
                        </div>

                        <div id="editAdminNotesWrapper" class="hidden">
                            <label id="editAdminNotesLabel" class="block text-xs font-bold text-slate-300 uppercase mb-2">Lokasi</label>
                            <input type="text" name="admin_notes" id="editAdminNotes" autocomplete="off" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                        </div>

                        <div id="editAttachmentWrapper">
                            <label id="editAttachmentLabel" class="block text-xs font-bold text-slate-300 uppercase mb-2">Upload Foto (Opsional)</label>
                            <div class="flex items-center gap-3">
                                <label for="editFotoInput" class="cursor-pointer shrink-0 px-4 py-2 rounded-md bg-amber-600 hover:bg-amber-700 text-white text-sm font-semibold transition">Foto</label>
                                <span id="editFotoFileName" class="text-sm text-slate-400 truncate">Kosongkan jika tidak ingin ganti foto</span>
                            </div>
                            <input type="file" id="editFotoInput" name="attachment" class="hidden" onchange="document.getElementById('editFotoFileName').textContent = this.files[0] ? this.files[0].name : 'Kosongkan jika tidak ingin ganti foto';">
                        </div>

                        <div class="flex items-center justify-end space-x-3 pt-4 border-t border-slate-800">
                            <button type="button" onclick="closeEditRequestModal()" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-white">Batal</button>
                            <button type="submit" class="bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold py-2.5 px-6 rounded-xl shadow-lg">Simpan &amp; Kirim Ulang</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- POP-UP / MODAL CONTAINER -->
            <div id="modalContainer" class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
                
                <div class="bg-[#111827] w-full max-w-4xl rounded-2xl shadow-2xl border border-slate-800 p-6 md:p-8 max-h-[90vh] overflow-y-auto relative">

                    <!-- Overlay Loading/Sukses di tengah kartu form ini (bukan layar penuh) -->
                    @include('requisition.partials.status-overlay', ['prefix' => 'modal', 'loading' => 'Mengirim request...', 'withOk' => true])

                    <!-- Header Modal -->
                    <div class="flex items-center justify-between pb-4 border-b border-slate-800 mb-6">
                        <div class="min-w-0">
                            <h2 id="modalTitle" class="text-xl font-black text-white uppercase tracking-wide">FORM REQUEST</h2>
                            <p id="modalSubtitle" class="modal-subtitle"></p>
                        </div>
                        <button onclick="closeModalForm()" class="text-slate-400 hover:text-white text-2xl font-bold">&times;</button>
                    </div>

                    <div id="modalFormErrors" class="mb-4 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs space-y-0.5 hidden"></div>

                    <!-- FORM 0: TICKET SISTEM (laporan kendala sistem/software) -->
                    <form id="formTICKET_SISTEM" action="{{ route('requests.store') }}" method="POST" enctype="multipart/form-data" autocomplete="off" class="space-y-4 hidden">
                        @csrf
                        <input type="hidden" name="type" value="TICKET_SISTEM">
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Nama Karyawan</label>
                            @include('requisition.partials.employee-name-input', ['ring' => 'amber', 'value' => (old('type') === 'TICKET_SISTEM' ? old('employee_name') : ''), 'employeeId' => (old('type') === 'TICKET_SISTEM' ? old('employee_id') : '')])
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">NIK Karyawan</label>
                                <input type="text" name="employee_nik" value="{{ old('type') === 'TICKET_SISTEM' ? old('employee_nik') : '' }}" placeholder="NIK" required pattern="[0-9]*" title="NIK hanya angka" inputmode="numeric" autocomplete="off" oninput="this.value = this.value.replace(/[^0-9]/g, '')" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Department</label>
                                <select name="department" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                                    <option value="" disabled {{ old('type') === 'TICKET_SISTEM' && old('department') ? '' : 'selected' }}>Pilih Dept</option>
                                    @foreach ($departmentOptions as $dept)
                                        <option value="{{ $dept }}" {{ old('type') === 'TICKET_SISTEM' && old('department') === $dept ? 'selected' : '' }}>{{ $dept }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Jabatan</label>
                                <input type="text" name="position" value="{{ old('type') === 'TICKET_SISTEM' ? old('position') : '' }}" placeholder="Contoh: Foreman IT" required autocomplete="off" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Judul Tiket</label>
                            <input type="text" name="title" value="{{ old('type') === 'TICKET_SISTEM' ? old('title') : '' }}" placeholder="Contoh: Printer Teknis" required autocomplete="off" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Kategori</label>
                            <select name="category" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                                <option value="" disabled {{ old('type') === 'TICKET_SISTEM' && old('category') ? '' : 'selected' }}>Pilih Kategori</option>
                                @foreach ($ticketCategoryOptions as $category)
                                    <option value="{{ $category }}" {{ old('type') === 'TICKET_SISTEM' && old('category') === $category ? 'selected' : '' }}>{{ $category }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Lokasi</label>
                            <input type="text" name="admin_notes" value="{{ old('type') === 'TICKET_SISTEM' ? old('admin_notes') : '' }}" placeholder="Contoh: Room Server" required autocomplete="off" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Deskripsi</label>
                            <textarea name="description" rows="3" placeholder="Jelaskan kendala (contoh : Toner black habis)" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">{{ old('type') === 'TICKET_SISTEM' ? old('description') : '' }}</textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Upload Foto (Opsional)</label>
                            <div class="flex items-center gap-3">
                                <label for="ticketFotoInput" class="cursor-pointer shrink-0 px-4 py-2 rounded-md bg-amber-600 hover:bg-amber-700 text-white text-sm font-semibold transition">Foto</label>
                                <span id="ticketFotoFileName" class="text-sm text-slate-400 truncate">Belum ada file dipilih</span>
                            </div>
                            <input type="file" id="ticketFotoInput" name="attachment" class="hidden" onchange="document.getElementById('ticketFotoFileName').textContent = this.files[0] ? this.files[0].name : 'Belum ada file dipilih';">
                        </div>

                        <div class="flex items-center justify-end space-x-3 pt-4 border-t border-slate-800">
                            <button type="button" onclick="closeModalForm()" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-white">Batal</button>
                            <button type="submit" class="bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold py-2.5 px-6 rounded-xl shadow-lg">Submit Ticket Sistem</button>
                        </div>
                    </form>

                    <!-- FORM 1: ITR -->
                    <form id="formITR" action="{{ route('requests.store') }}" method="POST" enctype="multipart/form-data" autocomplete="off" class="space-y-4 hidden">
                        @csrf
                        <input type="hidden" name="type" value="ITR">
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Nama Karyawan</label>
                            @include('requisition.partials.employee-name-input', ['ring' => 'purple'])
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">NIK Karyawan</label>
                                <input type="text" name="employee_nik" placeholder="NIK" required pattern="[0-9]*" title="NIK hanya angka" inputmode="numeric" autocomplete="off" oninput="this.value = this.value.replace(/[^0-9]/g, '')" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Department</label>
                                <select name="department" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                                    <option value="" disabled selected>Pilih Dept</option>
                                    @foreach ($departmentOptions as $dept)
                                        <option value="{{ $dept }}">{{ $dept }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Jabatan</label>
                                <input type="text" name="position" placeholder="Contoh: Foreman IT" required autocomplete="off" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Judul Permintaan</label>
                            <input type="text" name="title" placeholder="Silahkan isi item" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-xs font-bold text-slate-300 uppercase">Device / Barang yang Diminta (Maks. 5)</label>
                                <button type="button" id="addItrItemBtn" onclick="addItrItem()" class="text-xs font-bold text-fuchsia-400 hover:text-fuchsia-300">+ Tambah Device</button>
                            </div>
                            <div id="itrItemsContainer" class="space-y-2">
                                <div class="itr-item-row grid grid-cols-[24px_1fr_70px_1fr_1fr_auto] gap-2 items-start">
                                    <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">1</div>
                                    <input type="text" name="items[0][name]" placeholder="Nama Device / Perangkat" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                                    <input type="number" name="items[0][qty]" min="1" value="1" required placeholder="Qty" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                                    <input type="text" name="items[0][spesifikasi]" placeholder="Spesifikasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                                    <textarea name="items[0][keterangan]" placeholder="Keterangan" rows="2" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500 resize-y"></textarea>
                                    <button type="button" onclick="removeItrItem(this)" class="hidden text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Alasan Permintaan</label>
                            <textarea name="description" rows="3" placeholder="Jelaskan alasan / detail permintaan..." required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500"></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Upload Bukti Foto (Opsional)</label>
                            <div class="flex items-center gap-3">
                                <label for="itrFotoInput" class="cursor-pointer shrink-0 px-4 py-2 rounded-md bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold transition">Foto</label>
                                <span id="itrFotoFileName" class="text-sm text-slate-400 truncate">Belum ada file dipilih</span>
                            </div>
                            <input type="file" id="itrFotoInput" name="attachment" class="hidden" onchange="document.getElementById('itrFotoFileName').textContent = this.files[0] ? this.files[0].name : 'Belum ada file dipilih';">
                        </div>
                        <p class="text-[11px] text-slate-500"><svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.63a3.38 3.38 0 0 0-3.38-3.37h-1.5A1.13 1.13 0 0 1 13.5 7.13v-1.5a3.38 3.38 0 0 0-3.38-3.38H8.25m2.25 0H5.63c-.62 0-1.13.5-1.13 1.13v17.25c0 .62.5 1.12 1.13 1.12h12.75c.62 0 1.12-.5 1.12-1.12V11.25a9 9 0 0 0-9-9Z"/></svg> File PDF akan otomatis diterbitkan setelah permintaan disetujui oleh Tim IT.</p>

                        <div class="flex items-center justify-end space-x-3 pt-4 border-t border-slate-800">
                            <button type="button" onclick="closeModalForm()" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-white">Batal</button>
                            <button type="submit" class="bg-gradient-to-r from-purple-600 to-indigo-600 text-white font-bold py-2.5 px-6 rounded-xl shadow-lg">Submit ITR</button>
                        </div>
                    </form>

                    <!-- FORM 2: BAK -->
                    <form id="formBAK" action="{{ route('requests.store') }}" method="POST" enctype="multipart/form-data" autocomplete="off" class="space-y-4 hidden" onsubmit="return validateBakForm()">
                        @csrf
                        <input type="hidden" name="type" value="BAK">
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Nama Karyawan</label>
                            @include('requisition.partials.employee-name-input', ['ring' => 'cyan'])
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">NIK Karyawan</label>
                                <input type="text" name="employee_nik" placeholder="NIK" required pattern="[0-9]*" title="NIK hanya angka" inputmode="numeric" autocomplete="off" oninput="this.value = this.value.replace(/[^0-9]/g, '')" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-cyan-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Departement</label>
                                <select name="department" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-cyan-500">
                                    <option value="" disabled selected>Pilih Dept</option>
                                    @foreach ($departmentOptions as $dept)
                                        <option value="{{ $dept }}">{{ $dept }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Jabatan</label>
                                <input type="text" name="position" placeholder="Contoh: Foreman IT" required autocomplete="off" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-cyan-500">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Judul Laporan</label>
                            <input type="text" name="title" placeholder="Contoh : Lcd laptop pecah" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-cyan-500">
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-xs font-bold text-slate-300 uppercase">Nama Asset & type</label>
                                <button type="button" id="addBakItemBtn" onclick="addBakItem()" class="text-xs font-bold text-cyan-400 hover:text-cyan-300">+ Tambah</button>
                            </div>
                            <div id="bakItemsContainer" class="space-y-2">
                                <div class="bak-item-row grid grid-cols-[24px_1fr_1fr_1fr_1fr_auto] gap-2 items-start">
                                    <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">1</div>
                                    <input type="text" name="items[0][name]" placeholder="Contoh : Laptop Asus Tuf Gaming F16" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-cyan-500">
                                    <input type="text" name="items[0][spesifikasi]" placeholder="Spesifikasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-cyan-500">
                                    <input type="text" name="items[0][serial_number]" placeholder="Serial Number" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-cyan-500">
                                    <textarea name="items[0][keterangan]" placeholder="Keterangan" rows="2" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-cyan-500 resize-y"></textarea>
                                    <button type="button" onclick="removeBakItem(this)" class="hidden text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Kronologis</label>
                            <textarea name="description" rows="3" placeholder="Jelaskan kronologi kerusakan/kehilangan..." required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-cyan-500"></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Upload Foto Barang Rusak <span class="text-rose-400">*</span></label>
                            <div class="flex items-center gap-3">
                                <label for="bakFotoInput" class="cursor-pointer shrink-0 px-4 py-2 rounded-md bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-semibold transition">Foto</label>
                                <span id="bakFotoFileName" class="text-sm text-slate-400 truncate">Belum ada file dipilih</span>
                            </div>
                            <input type="file" id="bakFotoInput" name="attachment" class="hidden" onchange="document.getElementById('bakFotoFileName').textContent = this.files[0] ? this.files[0].name : 'Belum ada file dipilih'; document.getElementById('bakFotoError').classList.add('hidden');">
                            <p id="bakFotoError" class="hidden text-xs text-rose-400 mt-1">Foto barang rusak/hilang wajib diupload sebelum submit.</p>
                        </div>
                        <p class="text-[11px] text-slate-500"><svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.63a3.38 3.38 0 0 0-3.38-3.37h-1.5A1.13 1.13 0 0 1 13.5 7.13v-1.5a3.38 3.38 0 0 0-3.38-3.38H8.25m2.25 0H5.63c-.62 0-1.13.5-1.13 1.13v17.25c0 .62.5 1.12 1.13 1.12h12.75c.62 0 1.12-.5 1.12-1.12V11.25a9 9 0 0 0-9-9Z"/></svg> File PDF akan otomatis diterbitkan setelah permintaan disetujui oleh Tim IT.</p>

                        <div class="flex items-center justify-end space-x-3 pt-4 border-t border-slate-800">
                            <button type="button" onclick="closeModalForm()" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-white">Batal</button>
                            <button type="submit" class="bg-gradient-to-r from-cyan-500 to-blue-600 text-white font-bold py-2.5 px-6 rounded-xl shadow-lg">Submit BAK</button>
                        </div>
                    </form>

                    <!-- FORM 3: SURAT JALAN (SUDAH DIPERBAIKI NAMA INPUTNYA) -->
                    <form id="formSURAT_JALAN" action="{{ route('requests.store') }}" method="POST" enctype="multipart/form-data" autocomplete="off" class="space-y-4 hidden" onsubmit="return validateSjForm()">
                        @csrf
                        <input type="hidden" name="type" value="SURAT_JALAN">

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Nama Pengirim / Pembuat</label>
                                @include('requisition.partials.employee-name-input', ['ring' => 'purple'])
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Department (Opsional)</label>
                                <select name="department" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                                    <option value="" selected>Pilih Dept</option>
                                    @foreach ($departmentOptions as $dept)
                                        <option value="{{ $dept }}">{{ $dept }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Jabatan</label>
                                <input type="text" name="position" placeholder="Contoh: Foreman IT" required autocomplete="off" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Penerima</label>
                                <input type="text" name="recipient_name" placeholder="Nama Penerima" required autocomplete="off" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">No HP Penerima</label>
                                <input type="text" name="recipient_phone" placeholder="Contoh: 081234567890" pattern="[0-9]*" title="NIK hanya angka" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '')" autocomplete="off" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Tujuan Pengiriman / Lokasi</label>
                            <textarea name="title" rows="2" placeholder="Contoh: Cabang Surabaya / Service Center" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500"></textarea>
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-xs font-bold text-slate-300 uppercase">Daftar Barang Dikirim (Maks. 5)</label>
                                <button type="button" id="addSjItemBtn" onclick="addSjItem()" class="text-xs font-bold text-purple-400 hover:text-purple-300">+ Tambah Barang</button>
                            </div>
                            <div id="sjItemsContainer" class="space-y-2">
                                <div class="sj-item-row grid grid-cols-[24px_1fr_70px_1fr_1fr_auto] gap-2 items-center">
                                    <div class="item-no text-center text-xs font-bold text-slate-500">1</div>
                                    <input type="text" name="items[0][name]" placeholder="Nama Barang" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                                    <input type="number" name="items[0][qty]" min="1" value="1" required placeholder="Qty" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                                    <input type="text" name="items[0][spesifikasi]" placeholder="Spesifikasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                                    <input type="text" name="items[0][serial_number]" placeholder="Serial Number" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                                    <button type="button" onclick="removeSjItem(this)" class="hidden text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3">✕</button>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Catatan Pengiriman</label>
                            <textarea name="description" rows="3" placeholder="Catatan tambahan terkait pengiriman..." required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500"></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Upload Foto <span class="text-rose-400">*</span></label>
                            <div class="flex items-center gap-3">
                                <label for="sjFotoInput" class="cursor-pointer shrink-0 px-4 py-2 rounded-md bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold transition">Foto</label>
                                <span id="sjFotoFileName" class="text-sm text-slate-400 truncate">Belum ada file dipilih</span>
                            </div>
                            <input type="file" id="sjFotoInput" name="attachment" class="hidden" onchange="document.getElementById('sjFotoFileName').textContent = this.files[0] ? this.files[0].name : 'Belum ada file dipilih'; document.getElementById('sjFotoError').classList.add('hidden');">
                            <p id="sjFotoError" class="hidden text-xs text-rose-400 mt-1">Foto wajib diupload sebelum submit.</p>
                        </div>
                        <p class="text-[11px] text-slate-500"><svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.63a3.38 3.38 0 0 0-3.38-3.37h-1.5A1.13 1.13 0 0 1 13.5 7.13v-1.5a3.38 3.38 0 0 0-3.38-3.38H8.25m2.25 0H5.63c-.62 0-1.13.5-1.13 1.13v17.25c0 .62.5 1.12 1.13 1.12h12.75c.62 0 1.12-.5 1.12-1.12V11.25a9 9 0 0 0-9-9Z"/></svg> File PDF akan otomatis diterbitkan setelah permintaan disetujui oleh Tim IT.</p>

                        <div class="flex items-center justify-end space-x-3 pt-4 border-t border-slate-800">
                            <button type="button" onclick="closeModalForm()" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-white">Batal</button>
                            <button type="submit" class="bg-gradient-to-r from-purple-600 to-indigo-600 text-white font-bold py-2.5 px-6 rounded-xl shadow-lg">Submit Surat Jalan</button>
                        </div>
                    </form>

                    <!-- FORM 5: WO (WORK ORDER - INSTALASI INFRASTRUKTUR) -->
                    <form id="formWO" action="{{ route('requests.store') }}" method="POST" enctype="multipart/form-data" autocomplete="off" class="space-y-4 hidden">
                        @csrf
                        <input type="hidden" name="type" value="WO">
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Nama Karyawan</label>
                            @include('requisition.partials.employee-name-input', ['ring' => 'emerald'])
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">NIK Karyawan</label>
                                <input type="text" name="employee_nik" placeholder="NIK" required pattern="[0-9]*" title="NIK hanya angka" inputmode="numeric" autocomplete="off" oninput="this.value = this.value.replace(/[^0-9]/g, '')" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Department</label>
                                <select name="department" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-emerald-500">
                                    <option value="" disabled selected>Pilih Dept</option>
                                    @foreach ($departmentOptions as $dept)
                                        <option value="{{ $dept }}">{{ $dept }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Jabatan</label>
                                <input type="text" name="position" placeholder="Contoh: Foreman IT" required autocomplete="off" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-emerald-500">
                            </div>
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-xs font-bold text-slate-300 uppercase">Rincian Pekerjaan (Maks. 5)</label>
                                <button type="button" id="addWoItemBtn" onclick="addWoItem()" class="text-xs font-bold text-emerald-400 hover:text-emerald-300">+ Tambah</button>
                            </div>
                            <div id="woItemsContainer" class="space-y-2">
                                <div class="wo-item-row grid grid-cols-[24px_1fr_1fr_60px_1fr_auto] gap-2 items-start">
                                    <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">1</div>
                                    <input type="text" name="items[0][name]" placeholder="Nama Pekerjaan" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-emerald-500">
                                    <input type="text" name="items[0][lokasi]" placeholder="Lokasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-emerald-500">
                                    <input type="number" name="items[0][qty]" min="1" value="1" placeholder="Qty" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-emerald-500">
                                    <textarea name="items[0][keterangan]" placeholder="Keterangan" rows="2" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-emerald-500 resize-y"></textarea>
                                    <button type="button" onclick="removeWoItem(this)" class="hidden text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Deskripsi Pekerjaan</label>
                            <textarea name="description" rows="3" placeholder="Jelaskan detail pekerjaan yang diminta, termasuk lokasi/area..." required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-emerald-500"></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Upload Foto (Opsional)</label>
                            <div class="flex items-center gap-3">
                                <label for="woFotoInput" class="cursor-pointer shrink-0 px-4 py-2 rounded-md bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold transition">Foto</label>
                                <span id="woFotoFileName" class="text-sm text-slate-400 truncate">Belum ada file dipilih</span>
                            </div>
                            <input type="file" id="woFotoInput" name="attachment" class="hidden" onchange="document.getElementById('woFotoFileName').textContent = this.files[0] ? this.files[0].name : 'Belum ada file dipilih';">
                        </div>
                        <p class="text-[11px] text-slate-500"><svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.63a3.38 3.38 0 0 0-3.38-3.37h-1.5A1.13 1.13 0 0 1 13.5 7.13v-1.5a3.38 3.38 0 0 0-3.38-3.38H8.25m2.25 0H5.63c-.62 0-1.13.5-1.13 1.13v17.25c0 .62.5 1.12 1.13 1.12h12.75c.62 0 1.12-.5 1.12-1.12V11.25a9 9 0 0 0-9-9Z"/></svg> File PDF akan otomatis diterbitkan setelah permintaan disetujui oleh Tim IT.</p>

                        <div class="flex items-center justify-end space-x-3 pt-4 border-t border-slate-800">
                            <button type="button" onclick="closeModalForm()" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-white">Batal</button>
                            <button type="submit" class="bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-bold py-2.5 px-6 rounded-xl shadow-lg">Submit Work Order</button>
                        </div>
                    </form>

                    <!-- FORM 6: PEMINJAMAN ASET -->
                    <form id="formPEMINJAMAN_ASET" action="{{ route('requests.store') }}" method="POST" autocomplete="off" class="space-y-4 hidden">
                        @csrf
                        <input type="hidden" name="type" value="PEMINJAMAN_ASET">
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Nama Peminjam</label>
                            @include('requisition.partials.employee-name-input', ['ring' => 'rose'])
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">NIK Karyawan</label>
                                <input type="text" name="employee_nik" placeholder="NIK" required pattern="[0-9]*" title="NIK hanya angka" inputmode="numeric" autocomplete="off" oninput="this.value = this.value.replace(/[^0-9]/g, '')" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-rose-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Department</label>
                                <select name="department" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-rose-500">
                                    <option value="" disabled selected>Pilih Dept</option>
                                    @foreach ($departmentOptions as $dept)
                                        <option value="{{ $dept }}">{{ $dept }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Jabatan</label>
                                <input type="text" name="position" placeholder="Contoh: Foreman IT" required autocomplete="off" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-rose-500">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Keperluan Peminjaman</label>
                            <input type="text" name="title" placeholder="Contoh: Survey lapangan Site B" required autocomplete="off" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-rose-500">
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Tanggal Pinjam</label>
                                <input type="date" name="loan_start_date" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-rose-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Tanggal Kembali</label>
                                <input type="date" name="loan_end_date" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-rose-500">
                            </div>
                        </div>
                        <p class="text-[11px] text-slate-500 -mt-2">* Peminjaman lebih dari 7 hari diperlukan approval file Berita Acara.</p>
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-xs font-bold text-slate-300 uppercase">Aset yang Dipinjam (Maks. 5)</label>
                                <button type="button" id="addPeminjamanItemBtn" onclick="addPeminjamanItem()" class="text-xs font-bold text-rose-400 hover:text-rose-300">+ Tambah Aset</button>
                            </div>
                            <div id="peminjamanItemsContainer" class="space-y-2">
                                @if ($isLockedUser)
                                    <!-- Role user: cuma isi Nama Aset. Spesifikasi & Serial Number sengaja disembunyikan,
                                         nanti dilengkapi tim IT saat memproses peminjaman. -->
                                    <div class="peminjaman-item-row grid grid-cols-[24px_1fr_auto] gap-2 items-start">
                                        <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">1</div>
                                        <input type="text" name="items[0][name]" placeholder="Nama Aset" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-rose-500">
                                        <button type="button" onclick="removePeminjamanItem(this)" class="hidden text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
                                    </div>
                                @else
                                    <div class="peminjaman-item-row grid grid-cols-[24px_1fr_1fr_1fr_auto] gap-2 items-start">
                                        <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">1</div>
                                        <input type="text" name="items[0][name]" placeholder="Nama Aset" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-rose-500">
                                        <input type="text" name="items[0][spesifikasi]" placeholder="Spesifikasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-rose-500">
                                        <input type="text" name="items[0][serial_number]" placeholder="Serial Number" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-rose-500">
                                        <button type="button" onclick="removePeminjamanItem(this)" class="hidden text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
                                    </div>
                                @endif
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Catatan Tambahan</label>
                            <textarea name="description" rows="3" placeholder="Catatan tambahan terkait peminjaman..." required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-rose-500"></textarea>
                        </div>
                        <p class="text-[11px] text-slate-500"><svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.63a3.38 3.38 0 0 0-3.38-3.37h-1.5A1.13 1.13 0 0 1 13.5 7.13v-1.5a3.38 3.38 0 0 0-3.38-3.38H8.25m2.25 0H5.63c-.62 0-1.13.5-1.13 1.13v17.25c0 .62.5 1.12 1.13 1.12h12.75c.62 0 1.12-.5 1.12-1.12V11.25a9 9 0 0 0-9-9Z"/></svg> File PDF (untuk peminjaman 7 hari atau lebih) akan otomatis diterbitkan setelah permintaan disetujui oleh Tim IT.</p>

                        <div class="flex items-center justify-end space-x-3 pt-4 border-t border-slate-800">
                            <button type="button" onclick="closeModalForm()" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-white">Batal</button>
                            <button type="submit" class="bg-gradient-to-r from-rose-500 to-pink-600 text-white font-bold py-2.5 px-6 rounded-xl shadow-lg">Submit Peminjaman Aset</button>
                        </div>
                    </form>

                </div>
            </div>

        </div>
    </div>

    @include('requisition.partials.submit-helper')

    <!-- Script JavaScript Pengatur Modal -->
    <script>
        function openModalForm(type) {
            document.getElementById('modalFormErrors').classList.add('hidden');
            srHideOverlay('modal');
            document.querySelectorAll('#modalContainer form').forEach(function (f) { f.dataset.busy = '0'; });
            document.getElementById('formITR').classList.add('hidden');
            document.getElementById('formBAK').classList.add('hidden');
            document.getElementById('formSURAT_JALAN').classList.add('hidden');
            document.getElementById('formTICKET_SISTEM').classList.add('hidden');
            document.getElementById('formWO').classList.add('hidden');
            document.getElementById('formPEMINJAMAN_ASET').classList.add('hidden');

            const titleMap = {
                TICKET_SISTEM: ['Form Ticket Sistem', 'Laporkan kendala aplikasi, jaringan, atau perangkat agar segera ditangani Tim IT.'],
                ITR: ['Form IT Request (ITR)', 'Ajukan kebutuhan perangkat atau layanan IT. Isi manual; Tim IT yang mencocokkan dengan data aset.'],
                BAK: ['Form Berita Acara Kerusakan (BAK)', 'Laporkan perangkat yang rusak atau hilang beserta foto buktinya.'],
                SURAT_JALAN: ['Form Surat Jalan', 'Dokumen pengiriman barang atau aset IT keluar. Khusus Tim IT.'],
                WO: ['Form Work Order (WO)', 'Ajukan pekerjaan instalasi infrastruktur IT di lapangan: jaringan, CCTV, Access Point, dan sejenisnya.'],
                PEMINJAMAN_ASET: ['Form Peminjaman Aset', 'Pinjam aset atau perangkat IT untuk jangka waktu tertentu.'],
            };
            const [titleText, subText] = titleMap[type] || [type + ' FORM', ''];
            document.getElementById('modalTitle').innerText = titleText;
            document.getElementById('modalSubtitle').innerText = subText;

            document.getElementById('form' + type).classList.remove('hidden');

            let modal = document.getElementById('modalContainer');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeModalForm() {
            let modal = document.getElementById('modalContainer');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function validateBakForm() {
            const fotoInput = document.getElementById('bakFotoInput');
            if (!fotoInput.files || fotoInput.files.length === 0) {
                document.getElementById('bakFotoError').classList.remove('hidden');
                fotoInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }
            return true;
        }

        function validateSjForm() {
            const fotoInput = document.getElementById('sjFotoInput');
            if (!fotoInput.files || fotoInput.files.length === 0) {
                document.getElementById('sjFotoError').classList.remove('hidden');
                fotoInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }
            return true;
        }

        // Renomori label "No" (kolom tampilan saja, bukan field form) di sebuah container repeater
        function renumberItems(containerId, rowSelector) {
            document.querySelectorAll(`#${containerId} ${rowSelector}`).forEach((row, idx) => {
                const noEl = row.querySelector('.item-no');
                if (noEl) noEl.textContent = idx + 1;
            });
        }

        // Repeater Device (Form ITR) - maksimal 5 device per request
        const ITR_ITEM_MAX = 5;

        function addItrItem() {
            const container = document.getElementById('itrItemsContainer');
            const rows = container.querySelectorAll('.itr-item-row');
            if (rows.length >= ITR_ITEM_MAX) return;

            const idx = rows.length;
            const row = document.createElement('div');
            row.className = 'itr-item-row grid grid-cols-[24px_1fr_70px_1fr_1fr_auto] gap-2 items-start';
            row.innerHTML = `
                <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">${idx + 1}</div>
                <input type="text" name="items[${idx}][name]" placeholder="Nama Device / Perangkat" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                <input type="number" name="items[${idx}][qty]" min="1" value="1" required placeholder="Qty" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                <input type="text" name="items[${idx}][spesifikasi]" placeholder="Spesifikasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                <textarea name="items[${idx}][keterangan]" placeholder="Keterangan" rows="2" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500 resize-y"></textarea>
                <button type="button" onclick="removeItrItem(this)" class="text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
            `;
            container.appendChild(row);
            updateItrItemsUI();
        }

        function removeItrItem(btn) {
            btn.closest('.itr-item-row').remove();
            reindexItrItems();
            updateItrItemsUI();
        }

        function reindexItrItems() {
            document.querySelectorAll('#itrItemsContainer .itr-item-row').forEach((row, idx) => {
                const textInputs = row.querySelectorAll('input[type="text"]');
                textInputs[0].name = `items[${idx}][name]`;
                textInputs[1].name = `items[${idx}][spesifikasi]`;
                row.querySelector('input[type="number"]').name = `items[${idx}][qty]`;
                row.querySelector('textarea').name = `items[${idx}][keterangan]`;
            });
            renumberItems('itrItemsContainer', '.itr-item-row');
        }

        function updateItrItemsUI() {
            const rows = document.querySelectorAll('#itrItemsContainer .itr-item-row');
            document.getElementById('addItrItemBtn').style.display = rows.length >= ITR_ITEM_MAX ? 'none' : 'inline-block';
            rows.forEach((row) => {
                row.querySelector('button').classList.toggle('hidden', rows.length <= 1);
            });
        }

        // Repeater Barang (Form BAK) - maksimal 5 barang per laporan
        const BAK_ITEM_MAX = 5;

        function addBakItem() {
            const container = document.getElementById('bakItemsContainer');
            const rows = container.querySelectorAll('.bak-item-row');
            if (rows.length >= BAK_ITEM_MAX) return;

            const idx = rows.length;
            const row = document.createElement('div');
            row.className = 'bak-item-row grid grid-cols-[24px_1fr_1fr_1fr_1fr_auto] gap-2 items-start';
            row.innerHTML = `
                <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">${idx + 1}</div>
                <input type="text" name="items[${idx}][name]" placeholder="Contoh : Laptop Asus Tuf Gaming F16" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-cyan-500">
                <input type="text" name="items[${idx}][spesifikasi]" placeholder="Spesifikasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-cyan-500">
                <input type="text" name="items[${idx}][serial_number]" placeholder="Serial Number" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-cyan-500">
                <textarea name="items[${idx}][keterangan]" placeholder="Keterangan" rows="2" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-cyan-500 resize-y"></textarea>
                <button type="button" onclick="removeBakItem(this)" class="text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
            `;
            container.appendChild(row);
            updateBakItemsUI();
        }

        function removeBakItem(btn) {
            btn.closest('.bak-item-row').remove();
            reindexBakItems();
            updateBakItemsUI();
        }

        function reindexBakItems() {
            document.querySelectorAll('#bakItemsContainer .bak-item-row').forEach((row, idx) => {
                const inputs = row.querySelectorAll('input[type="text"]');
                inputs[0].name = `items[${idx}][name]`;
                inputs[1].name = `items[${idx}][spesifikasi]`;
                inputs[2].name = `items[${idx}][serial_number]`;
                row.querySelector('textarea').name = `items[${idx}][keterangan]`;
            });
            renumberItems('bakItemsContainer', '.bak-item-row');
        }

        function updateBakItemsUI() {
            const rows = document.querySelectorAll('#bakItemsContainer .bak-item-row');
            document.getElementById('addBakItemBtn').style.display = rows.length >= BAK_ITEM_MAX ? 'none' : 'inline-block';
            rows.forEach((row) => {
                row.querySelector('button').classList.toggle('hidden', rows.length <= 1);
            });
        }

        // Repeater Barang (Form Surat Jalan) - maksimal 5 barang per pengiriman
        const SJ_ITEM_MAX = 5;

        function addSjItem() {
            const container = document.getElementById('sjItemsContainer');
            const rows = container.querySelectorAll('.sj-item-row');
            if (rows.length >= SJ_ITEM_MAX) return;

            const idx = rows.length;
            const row = document.createElement('div');
            row.className = 'sj-item-row grid grid-cols-[24px_1fr_70px_1fr_1fr_auto] gap-2 items-center';
            row.innerHTML = `
                <div class="item-no text-center text-xs font-bold text-slate-500">${idx + 1}</div>
                <input type="text" name="items[${idx}][name]" placeholder="Nama Barang" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                <input type="number" name="items[${idx}][qty]" min="1" value="1" required placeholder="Qty" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                <input type="text" name="items[${idx}][spesifikasi]" placeholder="Spesifikasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                <input type="text" name="items[${idx}][serial_number]" placeholder="Serial Number" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-purple-500">
                <button type="button" onclick="removeSjItem(this)" class="text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3">✕</button>
            `;
            container.appendChild(row);
            updateSjItemsUI();
        }

        function removeSjItem(btn) {
            btn.closest('.sj-item-row').remove();
            reindexSjItems();
            updateSjItemsUI();
        }

        function reindexSjItems() {
            document.querySelectorAll('#sjItemsContainer .sj-item-row').forEach((row, idx) => {
                const textInputs = row.querySelectorAll('input[type="text"]');
                textInputs[0].name = `items[${idx}][name]`;
                textInputs[1].name = `items[${idx}][spesifikasi]`;
                textInputs[2].name = `items[${idx}][serial_number]`;
                row.querySelector('input[type="number"]').name = `items[${idx}][qty]`;
            });
            renumberItems('sjItemsContainer', '.sj-item-row');
        }

        function updateSjItemsUI() {
            const rows = document.querySelectorAll('#sjItemsContainer .sj-item-row');
            document.getElementById('addSjItemBtn').style.display = rows.length >= SJ_ITEM_MAX ? 'none' : 'inline-block';
            rows.forEach((row) => {
                row.querySelector('button').classList.toggle('hidden', rows.length <= 1);
            });
        }

        // Repeater Rincian Pekerjaan (Form WO) - maksimal 5 baris per pekerjaan
        const WO_ITEM_MAX = 5;

        function addWoItem() {
            const container = document.getElementById('woItemsContainer');
            const rows = container.querySelectorAll('.wo-item-row');
            if (rows.length >= WO_ITEM_MAX) return;

            const idx = rows.length;
            const row = document.createElement('div');
            row.className = 'wo-item-row grid grid-cols-[24px_1fr_1fr_60px_1fr_auto] gap-2 items-start';
            row.innerHTML = `
                <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">${idx + 1}</div>
                <input type="text" name="items[${idx}][name]" placeholder="Nama Pekerjaan" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-emerald-500">
                <input type="text" name="items[${idx}][lokasi]" placeholder="Lokasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-emerald-500">
                <input type="number" name="items[${idx}][qty]" min="1" value="1" placeholder="Qty" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-emerald-500">
                <textarea name="items[${idx}][keterangan]" placeholder="Keterangan" rows="2" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-emerald-500 resize-y"></textarea>
                <button type="button" onclick="removeWoItem(this)" class="text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
            `;
            container.appendChild(row);
            updateWoItemsUI();
        }

        function removeWoItem(btn) {
            btn.closest('.wo-item-row').remove();
            reindexWoItems();
            updateWoItemsUI();
        }

        function reindexWoItems() {
            document.querySelectorAll('#woItemsContainer .wo-item-row').forEach((row, idx) => {
                const textInputs = row.querySelectorAll('input[type="text"]');
                textInputs[0].name = `items[${idx}][name]`;
                textInputs[1].name = `items[${idx}][lokasi]`;
                row.querySelector('input[type="number"]').name = `items[${idx}][qty]`;
                row.querySelector('textarea').name = `items[${idx}][keterangan]`;
            });
            renumberItems('woItemsContainer', '.wo-item-row');
        }

        function updateWoItemsUI() {
            const rows = document.querySelectorAll('#woItemsContainer .wo-item-row');
            document.getElementById('addWoItemBtn').style.display = rows.length >= WO_ITEM_MAX ? 'none' : 'inline-block';
            rows.forEach((row) => {
                row.querySelector('button').classList.toggle('hidden', rows.length <= 1);
            });
        }

        // Repeater Aset (Form Peminjaman Aset) - maksimal 5 aset per peminjaman.
        // Role user cuma isi Nama Aset (Spesifikasi & Serial Number dilengkapi tim IT belakangan).
        const PEMINJAMAN_ITEM_MAX = 5;
        const PEMINJAMAN_LOCKED_USER = @json($isLockedUser);

        function addPeminjamanItem() {
            const container = document.getElementById('peminjamanItemsContainer');
            const rows = container.querySelectorAll('.peminjaman-item-row');
            if (rows.length >= PEMINJAMAN_ITEM_MAX) return;

            const idx = rows.length;
            const row = document.createElement('div');

            if (PEMINJAMAN_LOCKED_USER) {
                row.className = 'peminjaman-item-row grid grid-cols-[24px_1fr_auto] gap-2 items-start';
                row.innerHTML = `
                    <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">${idx + 1}</div>
                    <input type="text" name="items[${idx}][name]" placeholder="Nama Aset" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-rose-500">
                    <button type="button" onclick="removePeminjamanItem(this)" class="text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
                `;
            } else {
                row.className = 'peminjaman-item-row grid grid-cols-[24px_1fr_1fr_1fr_auto] gap-2 items-start';
                row.innerHTML = `
                    <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">${idx + 1}</div>
                    <input type="text" name="items[${idx}][name]" placeholder="Nama Aset" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-rose-500">
                    <input type="text" name="items[${idx}][spesifikasi]" placeholder="Spesifikasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-rose-500">
                    <input type="text" name="items[${idx}][serial_number]" placeholder="Serial Number" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-rose-500">
                    <button type="button" onclick="removePeminjamanItem(this)" class="text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
                `;
            }

            container.appendChild(row);
            updatePeminjamanItemsUI();
        }

        function removePeminjamanItem(btn) {
            btn.closest('.peminjaman-item-row').remove();
            reindexPeminjamanItems();
            updatePeminjamanItemsUI();
        }

        function reindexPeminjamanItems() {
            document.querySelectorAll('#peminjamanItemsContainer .peminjaman-item-row').forEach((row, idx) => {
                const textInputs = row.querySelectorAll('input[type="text"]');
                textInputs[0].name = `items[${idx}][name]`;
                if (!PEMINJAMAN_LOCKED_USER) {
                    textInputs[1].name = `items[${idx}][spesifikasi]`;
                    textInputs[2].name = `items[${idx}][serial_number]`;
                }
            });
            renumberItems('peminjamanItemsContainer', '.peminjaman-item-row');
        }

        function updatePeminjamanItemsUI() {
            const rows = document.querySelectorAll('#peminjamanItemsContainer .peminjaman-item-row');
            document.getElementById('addPeminjamanItemBtn').style.display = rows.length >= PEMINJAMAN_ITEM_MAX ? 'none' : 'inline-block';
            rows.forEach((row) => {
                row.querySelector('button').classList.toggle('hidden', rows.length <= 1);
            });
        }

        // Filter History Request Terbaru (halaman publik) - pencarian teks
        const publicFilters = { search: '' };

        function applyPublicFilters() {
            document.querySelectorAll('.public-request-row').forEach(row => {
                const matchesSearch = publicFilters.search === '' || row.innerText.toLowerCase().includes(publicFilters.search);
                row.style.display = matchesSearch ? '' : 'none';
            });
        }

        function publicSearchTable() {
            publicFilters.search = document.getElementById('publicSearchInput').value.toLowerCase();
            applyPublicFilters();
        }

        // Auto-refresh History Request Terbaru secara berkala (berlaku semua role, termasuk user),
        // supaya perubahan status (misal disetujui/ditolak) kelihatan tanpa harus manual reload.
        const publicHistoryUrl = @json(route('requests.history'));
        const currentUserId = @json(auth()->id());
        let publicHistoryRequestSeq = 0;
        let lastPublicHistoryHtml = null;
        let knownStatusById = null; // null = belum pernah load (jangan toast di load pertama)

        function loadPublicHistory() {
            const seq = ++publicHistoryRequestSeq;
            fetch(publicHistoryUrl, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(res => res.json())
                .then(data => {
                    if (seq !== publicHistoryRequestSeq) return; // Respon lama, abaikan
                    // Hanya bongkar tabel kalau isinya berubah (kalau tidak, hover berkedip tiap 2 detik)
                    if (data.rows_html !== lastPublicHistoryHtml) {
                        lastPublicHistoryHtml = data.rows_html;
                        document.getElementById('publicHistoryBody').innerHTML = data.rows_html;
                        applyPublicFilters(); // Terapkan lagi pencarian yang sedang aktif
                    }

                    const items = data.items || [];
                    const currentStatusById = new Map(items.map(i => [i.id, i.status]));
                    if (knownStatusById !== null) {
                        items.forEach(item => {
                            if (item.user_id !== currentUserId) return; // Cuma punya sendiri
                            const prevStatus = knownStatusById.get(item.id);
                            const isNewlyVisible = prevStatus === undefined;
                            // Request yang baru saja disubmit sendiri (masih Pending) sudah dapat
                            // notifikasi "berhasil dibuat" lewat overlay sukses — tidak perlu toast
                            // status-changed lagi begitu langsung muncul di history untuk pertama kali.
                            if (isNewlyVisible && item.status === 'Pending') return;
                            if (isNewlyVisible || prevStatus !== item.status) {
                                playNotificationSound();
                                const statusIcon = { Approved: '✓', 'In Progress': '⏳', Completed: '', Rejected: '✕' }[item.status] || '';
                                showToast({
                                    icon: statusIcon,
                                    title: 'Status Diperbarui: ' + item.status,
                                    body: item.title || 'Request Anda',
                                    accent: item.status === 'Rejected' ? 'rose' : (item.status === 'Completed' ? 'emerald' : 'amber'),
                                });
                            }
                        });
                    }
                    knownStatusById = currentStatusById;
                })
                .catch(() => {
                    // Diamkan saja kalau gagal — data lama tetap tampil
                });
        }

        const PUBLIC_HISTORY_REFRESH_INTERVAL_MS = 2000;
        setInterval(function () {
            if (document.hidden) return;
            loadPublicHistory();
        }, PUBLIC_HISTORY_REFRESH_INTERVAL_MS);

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                loadPublicHistory();
            }
        });

        // Modal Preview PDF (sama seperti di dashboard)
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

        // Lihat isi form yang sudah diisi (read-only). Semua teks dirakit lewat textContent (bukan innerHTML)
        // karena isinya berasal dari input user.
        const MY_FORM_TYPE_LABELS = {
            ITR: 'ITR — IT Request', TICKET_SISTEM: 'Ticket Sistem — Laporan Kendala', BAK: 'BAK — Berita Acara',
            WO: 'WO — Work Order', PEMINJAMAN_ASET: 'Peminjaman Aset', SURAT_JALAN: 'Surat Jalan', TT: 'Tanda Terima',
        };

        function openMyFormModal(item) {
            document.getElementById('myFormTicket').textContent = item.ticket_number || 'Menunggu Approval';
            document.getElementById('myFormTitle').textContent = item.title || '(tanpa judul)';
            document.getElementById('myFormType').textContent = MY_FORM_TYPE_LABELS[item.type] || item.type;

            const body = document.getElementById('myFormBody');
            body.replaceChildren();

            const fmtDate = (v) => v ? new Date(v).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
            const field = (label, value) => {
                if (value === null || value === undefined || String(value).trim() === '' || value === '-') return null;
                const d = document.createElement('div');
                const l = document.createElement('p'); l.className = 'text-[10px] text-slate-500 uppercase font-semibold'; l.textContent = label;
                const v = document.createElement('p'); v.className = 'text-slate-200 whitespace-pre-line break-words'; v.textContent = String(value);
                d.append(l, v);
                return d;
            };
            const section = (heading, nodes, cols) => {
                const list = nodes.filter(Boolean);
                if (!list.length) return;
                const wrap = document.createElement('div');
                const h = document.createElement('h4'); h.className = 'text-xs font-bold text-slate-400 uppercase tracking-wider mb-2'; h.textContent = heading;
                const grid = document.createElement('div'); grid.className = 'grid gap-3 bg-slate-900/50 p-3 rounded-xl border border-slate-800 ' + (cols === 1 ? 'grid-cols-1' : 'grid-cols-2');
                list.forEach(n => grid.appendChild(n));
                wrap.append(h, grid);
                body.appendChild(wrap);
            };

            section('Pemohon', [
                field('Nama Karyawan', item.requester_name), field('NIK', item.nik),
                field('Department', item.department), field('Jabatan', item.position),
            ]);
            section('Status', [
                field('Status', item.status),
                field('Diajukan', fmtDate(item.created_at)), field('Kategori', item.category),
            ]);
            section('Penerima', [field('Nama Penerima', item.recipient_name), field('No. Telepon', item.recipient_phone)]);
            section('Peminjaman', [field('Tanggal Pinjam', fmtDate(item.loan_start_date)), field('Tanggal Kembali', fmtDate(item.loan_end_date))]);

            const items = Array.isArray(item.items) ? item.items : [];
            if (items.length) {
                const wrap = document.createElement('div');
                const h = document.createElement('h4'); h.className = 'text-xs font-bold text-slate-400 uppercase tracking-wider mb-2'; h.textContent = 'Daftar Barang / Aset';
                const list = document.createElement('div'); list.className = 'space-y-2';
                items.forEach((it, i) => {
                    const card = document.createElement('div'); card.className = 'bg-slate-900/50 p-3 rounded-xl border border-slate-800 text-xs';
                    const t = document.createElement('p'); t.className = 'font-bold text-slate-200'; t.textContent = (i + 1) + '. ' + (it.name || '-') + (it.qty ? ' × ' + it.qty : '');
                    card.appendChild(t);
                    [['Kondisi', it.condition], ['Spesifikasi', it.spesifikasi], ['Serial Number', it.serial_number], ['Lokasi', it.lokasi], ['Keterangan', it.keterangan]].forEach(([k, v]) => {
                        if (v) { const p = document.createElement('p'); p.className = 'text-slate-400'; p.textContent = k + ': ' + v; card.appendChild(p); }
                    });
                    list.appendChild(card);
                });
                wrap.append(h, list);
                body.appendChild(wrap);
            }

            section('Deskripsi', [field('', item.description)], 1);
            section('Analisa & Rekomendasi Tim IT', [field('Analisa', item.analisa), field('Rekomendasi', item.rekomendasi)], 1);
            section('Keterangan dari Tim IT', [field('', item.update_note)], 1);

            if (item.attachment) {
                const wrap = document.createElement('div');
                const h = document.createElement('h4'); h.className = 'text-xs font-bold text-slate-400 uppercase tracking-wider mb-2'; h.textContent = 'Lampiran Foto';
                const a = document.createElement('a'); a.href = '/storage/' + item.attachment; a.target = '_blank'; a.rel = 'noopener';
                const img = document.createElement('img'); img.src = '/storage/' + item.attachment; img.alt = 'Lampiran'; img.className = 'max-h-64 mx-auto rounded-lg border border-slate-800 object-contain';
                a.appendChild(img); wrap.append(h, a);
                body.appendChild(wrap);
            }

            const modal = document.getElementById('myFormModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeMyFormModal() {
            const modal = document.getElementById('myFormModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function openPublicNoteModal(updateNote) {
            document.getElementById('publicNoteModalText').textContent = updateNote || '-';
            const modal = document.getElementById('publicNoteModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        // Hapus request Rejected milik sendiri langsung dari History Request Terbaru
        function deleteRequest(id, ticketNumber) {
            if (!confirm('Yakin ingin menghapus request ' + ticketNumber + '? Tindakan ini tidak bisa dibatalkan.')) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '{{ url('/requisition/requests') }}/' + id;
            form.style.display = 'none';

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_token';
            csrfInput.value = '{{ csrf_token() }}';
            form.appendChild(csrfInput);

            const methodInput = document.createElement('input');
            methodInput.type = 'hidden';
            methodInput.name = '_method';
            methodInput.value = 'DELETE';
            form.appendChild(methodInput);

            document.body.appendChild(form);
            form.submit();
        }

        function closePublicNoteModal() {
            const modal = document.getElementById('publicNoteModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // Modal Edit Request (ITR/BAK/WO yang ditolak, diperbaiki lalu dikirim ulang)
        let editItemType = 'ITR';

        function escapeEditAttr(str) {
            const div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }

        function buildEditItemRow(idx, it) {
            it = it || {};
            if (editItemType === 'ITR') {
                return `
                    <div class="edit-item-row grid grid-cols-[24px_1fr_70px_1fr_1fr_auto] gap-2 items-start">
                        <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">${idx + 1}</div>
                        <input type="text" name="items[${idx}][name]" value="${escapeEditAttr(it.name)}" placeholder="Nama Device / Perangkat" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                        <input type="number" name="items[${idx}][qty]" min="1" value="${it.qty || 1}" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                        <input type="text" name="items[${idx}][spesifikasi]" value="${escapeEditAttr(it.spesifikasi)}" placeholder="Spesifikasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                        <textarea name="items[${idx}][keterangan]" placeholder="Keterangan" rows="2" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500 resize-y">${escapeEditAttr(it.keterangan)}</textarea>
                        <button type="button" onclick="removeEditItem(this)" class="text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
                    </div>`;
            }
            if (editItemType === 'BAK') {
                return `
                    <div class="edit-item-row grid grid-cols-[24px_1fr_1fr_1fr_1fr_auto] gap-2 items-start">
                        <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">${idx + 1}</div>
                        <input type="text" name="items[${idx}][name]" value="${escapeEditAttr(it.name)}" placeholder="Nama Asset" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                        <input type="text" name="items[${idx}][spesifikasi]" value="${escapeEditAttr(it.spesifikasi)}" placeholder="Spesifikasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                        <input type="text" name="items[${idx}][serial_number]" value="${escapeEditAttr(it.serial_number)}" placeholder="Serial Number" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                        <textarea name="items[${idx}][keterangan]" placeholder="Keterangan" rows="2" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500 resize-y">${escapeEditAttr(it.keterangan)}</textarea>
                        <button type="button" onclick="removeEditItem(this)" class="text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
                    </div>`;
            }
            if (editItemType === 'PEMINJAMAN_ASET') {
                // Sama seperti form Buat Request: role user cuma isi Nama Aset saat resubmit,
                // Spesifikasi & Serial Number tetap urusan tim IT untuk dilengkapi.
                if (PEMINJAMAN_LOCKED_USER) {
                    return `
                        <div class="edit-item-row grid grid-cols-[24px_1fr_auto] gap-2 items-start">
                            <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">${idx + 1}</div>
                            <input type="text" name="items[${idx}][name]" value="${escapeEditAttr(it.name)}" placeholder="Nama Aset" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                            <button type="button" onclick="removeEditItem(this)" class="text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
                        </div>`;
                }
                return `
                    <div class="edit-item-row grid grid-cols-[24px_1fr_1fr_1fr_auto] gap-2 items-start">
                        <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">${idx + 1}</div>
                        <input type="text" name="items[${idx}][name]" value="${escapeEditAttr(it.name)}" placeholder="Nama Aset" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                        <input type="text" name="items[${idx}][spesifikasi]" value="${escapeEditAttr(it.spesifikasi)}" placeholder="Spesifikasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                        <input type="text" name="items[${idx}][serial_number]" value="${escapeEditAttr(it.serial_number)}" placeholder="Serial Number" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                        <button type="button" onclick="removeEditItem(this)" class="text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
                    </div>`;
            }
            // WO
            return `
                <div class="edit-item-row grid grid-cols-[24px_1fr_1fr_60px_1fr_auto] gap-2 items-start">
                    <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">${idx + 1}</div>
                    <input type="text" name="items[${idx}][name]" value="${escapeEditAttr(it.name)}" placeholder="Nama Pekerjaan" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                    <input type="text" name="items[${idx}][lokasi]" value="${escapeEditAttr(it.lokasi)}" placeholder="Lokasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                    <input type="number" name="items[${idx}][qty]" min="1" value="${it.qty || 1}" placeholder="Qty" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500">
                    <textarea name="items[${idx}][keterangan]" placeholder="Keterangan" rows="2" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-amber-500 resize-y">${escapeEditAttr(it.keterangan)}</textarea>
                    <button type="button" onclick="removeEditItem(this)" class="text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
                </div>`;
        }

        function renumberEditItems() {
            document.querySelectorAll('#editItemsContainer .edit-item-row').forEach((row, idx) => {
                row.querySelector('.item-no').textContent = idx + 1;
                row.querySelectorAll('[name^="items["]').forEach((input) => {
                    input.name = input.name.replace(/items\[\d+\]/, `items[${idx}]`);
                });
            });
        }

        function updateEditRemoveButtons() {
            const rows = document.querySelectorAll('#editItemsContainer .edit-item-row');
            rows.forEach((row) => {
                row.querySelector('button').classList.toggle('hidden', rows.length <= 1);
            });
        }

        function addEditItem() {
            const container = document.getElementById('editItemsContainer');
            const rows = container.querySelectorAll('.edit-item-row');
            if (rows.length >= 5) return;
            container.insertAdjacentHTML('beforeend', buildEditItemRow(rows.length, {}));
            updateEditRemoveButtons();
        }

        function removeEditItem(btn) {
            btn.closest('.edit-item-row').remove();
            renumberEditItems();
            updateEditRemoveButtons();
        }

        function openEditRequestModal(item) {
            document.getElementById('editFormErrors').classList.add('hidden');
            srHideOverlay('edit');
            document.getElementById('editRequestForm').dataset.busy = '0';
            editItemType = item.type;

            const editTypeLabelMap = { TICKET_SISTEM: 'TICKET SISTEM', PEMINJAMAN_ASET: 'PEMINJAMAN ASET' };
            const editTypeLabel = editTypeLabelMap[item.type] || item.type;
            document.getElementById('editRequestForm').action = `{{ url('/requisition/requests') }}/${item.id}`;
            document.getElementById('editModalTitle').innerText = 'EDIT ' + editTypeLabel + ' — ' + (item.ticket_number || 'Menunggu Approval');
            document.getElementById('editModalRejectNotice').classList.toggle('hidden', item.status !== 'Rejected');
            document.getElementById('editEmployeeName').value = item.requester_name || '';
            document.getElementById('editEmployeeNik').value = (item.nik && item.nik !== '-') ? item.nik : '';
            document.getElementById('editDepartment').value = (item.department && item.department !== '-') ? item.department : '';
            document.getElementById('editPosition').value = item.position || '';
            if (window.EmployeePicker) EmployeePicker.hydrate(document.getElementById('editRequestForm'), item);
            document.getElementById('editDescription').value = item.description || '';
            document.getElementById('editAdminNotes').value = item.admin_notes || '';
            document.getElementById('editFotoFileName').textContent = 'Kosongkan jika tidak ingin ganti foto';
            document.getElementById('editFotoInput').value = '';

            const titleWrapper = document.getElementById('editTitleWrapper');
            const titleInput = document.getElementById('editTitle');
            const categoryWrapper = document.getElementById('editCategoryWrapper');
            const categorySelect = document.getElementById('editCategory');
            const itemsWrapper = document.getElementById('editItemsWrapper');
            const itemsLabel = document.getElementById('editItemsLabel');
            const descLabel = document.getElementById('editDescriptionLabel');
            const attachmentLabel = document.getElementById('editAttachmentLabel');
            const adminNotesWrapper = document.getElementById('editAdminNotesWrapper');
            const adminNotesInput = document.getElementById('editAdminNotes');
            const loanDatesWrapper = document.getElementById('editLoanDatesWrapper');
            const attachmentWrapperEl = document.getElementById('editAttachmentWrapper');

            attachmentWrapperEl.classList.remove('hidden');
            itemsWrapper.classList.remove('hidden');
            // Field admin_notes cuma dipakai untuk Ticket Sistem (sebagai "Lokasi", wajib) - untuk
            // tipe lain field ini disembunyikan total, bukan ditampilkan sebagai keterangan kosong yang tidak berguna
            // yang tidak ada gunanya.
            adminNotesWrapper.classList.add('hidden');
            adminNotesInput.value = '';
            adminNotesInput.required = false;
            titleWrapper.classList.remove('hidden');
            document.getElementById('editTitleLabel').innerText = 'Judul Permintaan';
            titleInput.value = item.title || '';
            titleInput.required = true;
            categoryWrapper.classList.add('hidden');
            categorySelect.disabled = true;
            categorySelect.required = false;
            loanDatesWrapper.classList.add('hidden');
            document.getElementById('editLoanStartDate').required = false;
            document.getElementById('editLoanEndDate').required = false;

            if (item.type === 'ITR') {
                itemsLabel.innerText = 'Device / Barang yang Diminta (Maks. 5)';
                descLabel.innerText = 'Alasan Permintaan';
                attachmentLabel.innerText = 'Upload Bukti Foto (Opsional)';
            } else if (item.type === 'BAK') {
                document.getElementById('editTitleLabel').innerText = 'Judul Laporan';
                itemsLabel.innerText = 'Nama Asset & Type';
                descLabel.innerText = 'Kronologis';
                attachmentLabel.innerText = 'Upload Foto Barang Rusak';
            } else if (item.type === 'TICKET_SISTEM') {
                document.getElementById('editTitleLabel').innerText = 'Judul Tiket';
                categoryWrapper.classList.remove('hidden');
                categorySelect.disabled = false;
                categorySelect.required = true;
                categorySelect.value = item.category || '';
                itemsWrapper.classList.add('hidden');
                descLabel.innerText = 'Deskripsi';
                attachmentLabel.innerText = 'Upload Foto (Opsional, kosongkan jika tidak ganti)';
                adminNotesWrapper.classList.remove('hidden');
                adminNotesInput.value = item.admin_notes || '';
                adminNotesInput.required = true;
            } else if (item.type === 'PEMINJAMAN_ASET') {
                document.getElementById('editTitleLabel').innerText = 'Keperluan Peminjaman';
                loanDatesWrapper.classList.remove('hidden');
                document.getElementById('editLoanStartDate').required = true;
                document.getElementById('editLoanEndDate').required = true;
                document.getElementById('editLoanStartDate').value = item.loan_start_date ? item.loan_start_date.slice(0, 10) : '';
                document.getElementById('editLoanEndDate').value = item.loan_end_date ? item.loan_end_date.slice(0, 10) : '';
                itemsLabel.innerText = 'Aset yang Dipinjam (Maks. 5)';
                descLabel.innerText = 'Catatan Tambahan';
                attachmentWrapperEl.classList.add('hidden');
            } else {
                // WO
                titleWrapper.classList.add('hidden');
                titleInput.required = false;
                itemsLabel.innerText = 'Rincian Pekerjaan (Maks. 5)';
                descLabel.innerText = 'Deskripsi Pekerjaan';
                attachmentLabel.innerText = 'Upload Foto (Opsional)';
            }

            const container = document.getElementById('editItemsContainer');
            container.innerHTML = '';
            if (item.type !== 'TICKET_SISTEM') {
                const items = (Array.isArray(item.items) && item.items.length) ? item.items : [{}];
                items.forEach((it, idx) => container.insertAdjacentHTML('beforeend', buildEditItemRow(idx, it)));
                updateEditRemoveButtons();
            }

            const modal = document.getElementById('editRequestModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeEditRequestModal() {
            const modal = document.getElementById('editRequestModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // Kalau submit gagal validasi, buka lagi modal form yang tadi diisi supaya user langsung lihat errornya
        @if ($errors->any() && old('type'))
            openModalForm(@json(old('type')));
        @endif

        // Submit form request/edit via AJAX: loading dulu sampai server selesai memproses,
        // baru muncul notifikasi berhasil — tidak ada reload/pindah halaman di tengah proses.
        function showFormErrors(containerId, errors) {
            const box = document.getElementById(containerId);
            const messages = Object.values(errors || {}).flat();
            box.innerHTML = messages.map(function (m) { return '<div>• ' + m + '</div>'; }).join('');
            box.classList.remove('hidden');
            box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        document.querySelectorAll('#formTICKET_SISTEM, #formITR, #formBAK, #formSURAT_JALAN, #formWO, #formPEMINJAMAN_ASET, #editRequestForm').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (e.defaultPrevented) return; // validasi client-side (mis. validateBakForm) sudah menolak

                e.preventDefault();

                // Cegah kirim ganda (klik/Enter dua kali): satu form hanya boleh punya satu pengiriman aktif
                if (form.dataset.busy === '1') return;
                form.dataset.busy = '1';

                // Loading/sukses/error tampil DI DALAM kartu modal yang sedang dibuka (bukan layar penuh)
                const prefix = form.id === 'editRequestForm' ? 'edit' : 'modal';
                const errorContainerId = prefix + 'FormErrors';
                document.getElementById(errorContainerId).classList.add('hidden');
                srShowOverlay(prefix);

                const fail = function (errors) {
                    form.dataset.busy = '0';
                    srHideOverlay(prefix);
                    showFormErrors(errorContainerId, errors);
                };

                srPost(form.action, new FormData(form))
                    .then(function (result) {
                        if (result.ok && result.data.success) {
                            document.getElementById(prefix + 'StatusSpinner').classList.add('hidden');
                            document.getElementById(prefix + 'StatusSuccessText').textContent = result.data.message || 'Berhasil disimpan.';

                            // Catatan PDF cuma relevan untuk tipe form yang memang punya PDF
                            // (lihat downloadPdf() di RequestController - Ticket Sistem tidak pernah punya PDF).
                            if (prefix === 'modal') {
                                const pdfNoteByType = {
                                    formTICKET_SISTEM: 'Mohon ditunggu approval dari Tim IT.',
                                    formPEMINJAMAN_ASET: 'File PDF (untuk peminjaman 7 hari atau lebih) akan otomatis diterbitkan setelah permintaan disetujui oleh Tim IT.',
                                };
                                document.getElementById('modalStatusNote').textContent = pdfNoteByType[form.id]
                                    || 'File PDF akan otomatis diterbitkan setelah permintaan disetujui oleh Tim IT.';
                            }

                            document.getElementById(prefix + 'StatusSuccess').classList.remove('hidden');

                            // Sengaja TIDAK auto-reload - notifikasi tetap tampil sampai user klik "Selesai"
                            // (form tetap terkunci selama itu, jadi tidak bisa terkirim dua kali).
                            document.getElementById(prefix + 'StatusOkButton').onclick = function () {
                                window.location.reload();
                            };
                            document.getElementById(prefix + 'StatusOkButton').focus();
                        } else {
                            const friendly = srFriendlyError(result.status, result.data);
                            fail((result.data && result.data.errors) ? result.data.errors : {
                                _: [friendly || 'Request gagal disimpan, silakan coba lagi.'],
                            });
                        }
                    })
                    .catch(function (err) {
                        fail({ _: [err && err.timeout
                            ? 'Server terlalu lama merespons. Request belum tentu terkirim - cek History Request Terbaru sebelum mengirim ulang.'
                            : 'Gagal terhubung ke server. Periksa koneksi internet Anda lalu coba lagi.'] });
                    });
            });
        });
    </script>

    @include('requisition.partials.employee-picker-assets')
</x-app-layout>
