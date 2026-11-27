<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logo-akp.png') }}">
    <link rel="manifest" href="{{ asset('requisition-manifest.json') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/icon-192.png') }}">
    <meta name="theme-color" content="#f2f6fa">
    @include('requisition.partials.theme-head')
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>

        /* Cetak Detail: hanya print isi modal, bukan seluruh dashboard */
        @media print {
            @page {
                size: auto;
                margin: 12mm;
            }

            body * {
                visibility: hidden;
            }

            #detailModal, #detailModal * {
                visibility: visible;
            }

            #detailModal {
                position: absolute;
                inset: 0;
                display: block !important;
                background: #ffffff !important;
                padding: 0;
            }

            #detailModal > div {
                position: static;
                max-width: 100%;
                max-height: none;
                overflow: visible;
                box-shadow: none;
                border: none;
                margin: 0;
                padding: 0;
                background: #ffffff !important;
                color: #0f172a !important;
            }

            #detailModal h2,
            #detailModal p,
            #detailModal span,
            #detailModal div {
                color: #0f172a !important;
                background: transparent !important;
                border-color: #cbd5e1 !important;
            }

            #detailModal img {
                max-height: 70mm;
            }

            #detailModal button {
                display: none !important;
            }
        }
    </style>
</head>
<body class="text-slate-200 h-screen flex overflow-hidden">

    <!-- Toast Notifikasi Dalam Aplikasi (bukan notifikasi bawaan OS) -->
    <div id="toastContainer" class="fixed top-4 right-4 z-[100] flex flex-col gap-3 w-[calc(100%-2rem)] max-w-sm pointer-events-none"></div>

    @php
        $departmentOptions = \App\Models\Department::names();   // satu sumber: tabel departments (IT Asset)
        $ticketCategoryOptions = [
            'Hardware', 'Software', 'Network', 'Account & Access Sharing Folder',
        ];
    @endphp

    <!-- Sidebar Left -->
    <!-- Backdrop mobile - tap di luar sidebar untuk menutup drawer -->
    <div id="sidebarBackdrop" onclick="closeSidebar()" class="hidden fixed inset-0 z-30 bg-black/60 lg:hidden"></div>

    <aside id="sidebar" class="w-64 glass-card p-6 flex flex-col justify-between fixed lg:static inset-y-0 left-0 z-40 h-screen overflow-y-auto -translate-x-full lg:translate-x-0 transition-transform duration-300">
        <div>
            <!-- App Logo/Title -->
            <div class="mb-8 flex items-start justify-between">
                <div>
                    <img src="{{ asset('images/logo-akp.png') }}" alt="AKP Logo" class="h-10 w-auto mb-2">
                    <p class="text-sm text-white font-medium">PT Adhi Kartiko Pratama Tbk</p>
                </div>
                <button type="button" onclick="closeSidebar()" title="Tutup Menu" class="md:hidden text-slate-400 hover:text-white text-2xl font-bold leading-none">
                    &times;
                </button>
            </div>

            <!-- Navigation Links -->
            <nav class="space-y-6">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-3">Action</p>
                    <!-- Tombol Buat Request Baru (Gradien Neon) -->
                    <a href="{{ route('requests.create') }}" class="w-full py-2.5 px-4 bg-gradient-to-r from-fuchsia-600 to-purple-600 hover:from-fuchsia-500 hover:to-purple-500 text-white font-bold text-xs rounded-xl shadow-lg shadow-fuchsia-600/30 flex items-center justify-center space-x-2 transition">
                        <span><svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg> Buat Request Baru</span>
                    </a>
                </div>

                <div>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-3">Aset</p>
                    <!-- List semua peminjaman aset - terpisah dari List All Form -->
                    <a href="{{ route('peminjaman.index') }}" class="w-full py-2.5 px-4 bg-slate-900/80 hover:bg-slate-800 text-slate-200 font-semibold text-xs rounded-xl border border-slate-800/80 shadow-md flex items-center justify-center space-x-2 transition-all duration-200">
                        <span><svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/></svg> Peminjaman</span>
                    </a>
                </div>

                <div>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-fuchsia-400 mb-3">Tickets Status</p>
                    <ul class="space-y-2 text-xs text-slate-400">
                        <!-- All Status Button -->
                        <li>
                            <button data-status-btn="All" onclick="filterStatus('All')" class="filter-btn w-full flex justify-between items-center px-3 py-2.5 rounded-xl bg-slate-900/80 hover:bg-gradient-to-r hover:from-slate-800 hover:to-slate-700/80 text-slate-200 font-semibold border border-slate-800/80 shadow-md transition-all duration-200 active:scale-95 focus:outline-none">
                                <span>All Status</span>
                                <span class="bg-slate-800 text-slate-200 px-2.5 py-0.5 rounded-lg text-[11px] font-extrabold border border-slate-700 shadow-inner">{{ $totalRequests }}</span>
                            </button>
                        </li>
                        <!-- Pending Button -->
                        <li>
                            <button data-status-btn="Pending" onclick="filterStatus('Pending')" class="filter-btn w-full flex justify-between items-center px-3 py-2.5 rounded-xl bg-slate-900/80 hover:bg-gradient-to-r hover:from-amber-950/40 hover:to-slate-900 text-slate-200 font-semibold border border-slate-800/80 hover:border-amber-500/30 shadow-md transition-all duration-200 active:scale-95 focus:outline-none">
                                <span>Pending</span>
                                <span class="bg-amber-500/20 text-amber-400 border border-amber-500/30 px-2.5 py-0.5 rounded-lg text-[11px] font-extrabold shadow-inner">{{ $pendingRequests }}</span>
                            </button>
                        </li>
                        <!-- In Progress Button -->
                        <li>
                            <button data-status-btn="In Progress" onclick="filterStatus('In Progress')" class="filter-btn w-full flex justify-between items-center px-3 py-2.5 rounded-xl bg-slate-900/80 hover:bg-gradient-to-r hover:from-blue-950/40 hover:to-slate-900 text-slate-200 font-semibold border border-slate-800/80 hover:border-blue-500/30 shadow-md transition-all duration-200 active:scale-95 focus:outline-none">
                                <span>In Progress</span>
                                <span class="bg-blue-500/20 text-blue-400 border border-blue-500/30 px-2.5 py-0.5 rounded-lg text-[11px] font-extrabold shadow-inner">{{ $inProgressRequests }}</span>
                            </button>
                        </li>
                        <!-- Completed Button -->
                        <li>
                            <button data-status-btn="Completed" onclick="filterStatus('Completed')" class="filter-btn w-full flex justify-between items-center px-3 py-2.5 rounded-xl bg-slate-900/80 hover:bg-gradient-to-r hover:from-emerald-950/40 hover:to-slate-900 text-slate-200 font-semibold border border-slate-800/80 hover:border-emerald-500/30 shadow-md transition-all duration-200 active:scale-95 focus:outline-none">
                                <span>Completed</span>
                                <span class="bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 px-2.5 py-0.5 rounded-lg text-[11px] font-extrabold shadow-inner">{{ $completedRequests }}</span>
                            </button>
                        </li>
                    </ul>
                </div>

                <div>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-fuchsia-400 mb-3">Kategori Request</p>
                    <ul class="space-y-2 text-xs text-slate-400">
                        <!-- Semua Kategori Button -->
                        <li>
                            <button data-type-btn="All" onclick="filterType('All')" class="filter-btn w-full flex justify-between items-center px-3 py-2.5 rounded-xl bg-slate-900/80 hover:bg-gradient-to-r hover:from-slate-800 hover:to-slate-700/80 text-slate-200 font-semibold border border-slate-800/80 shadow-md transition-all duration-200 active:scale-95 focus:outline-none">
                                <span>Semua Kategori</span>
                                <span class="bg-slate-800 text-slate-200 px-2.5 py-0.5 rounded-lg text-[11px] font-extrabold border border-slate-700 shadow-inner">{{ $totalRequests }}</span>
                            </button>
                        </li>
                        <!-- IT Request Button -->
                        <li>
                            <button data-type-btn="ITR" onclick="filterType('ITR')" class="filter-btn w-full flex justify-between items-center px-3 py-2.5 rounded-xl bg-slate-900/80 hover:bg-gradient-to-r hover:from-purple-950/40 hover:to-slate-900 text-slate-200 font-semibold border border-slate-800/80 hover:border-purple-500/30 shadow-md transition-all duration-200 active:scale-95 focus:outline-none">
                                <span>IT Request</span>
                                <span class="bg-purple-500/20 text-purple-400 border border-purple-500/30 px-2.5 py-0.5 rounded-lg text-[11px] font-extrabold shadow-inner">{{ $itrTypeCount }}</span>
                            </button>
                        </li>
                        <!-- Tanda Terima Button -->
                        <li>
                        </li>
                        <!-- BAK Button -->
                        <li>
                            <button data-type-btn="BAK" onclick="filterType('BAK')" class="filter-btn w-full flex justify-between items-center px-3 py-2.5 rounded-xl bg-slate-900/80 hover:bg-gradient-to-r hover:from-blue-950/40 hover:to-slate-900 text-slate-200 font-semibold border border-slate-800/80 hover:border-blue-500/30 shadow-md transition-all duration-200 active:scale-95 focus:outline-none">
                                <span>BAK</span>
                                <span class="bg-blue-500/20 text-blue-400 border border-blue-500/30 px-2.5 py-0.5 rounded-lg text-[11px] font-extrabold shadow-inner">{{ $bakTypeCount }}</span>
                            </button>
                        </li>
                        <!-- Surat Jalan Button -->
                        <li>
                            <button data-type-btn="SURAT_JALAN" onclick="filterType('SURAT_JALAN')" class="filter-btn w-full flex justify-between items-center px-3 py-2.5 rounded-xl bg-slate-900/80 hover:bg-gradient-to-r hover:from-amber-950/40 hover:to-slate-900 text-slate-200 font-semibold border border-slate-800/80 hover:border-amber-500/30 shadow-md transition-all duration-200 active:scale-95 focus:outline-none">
                                <span>Surat Jalan</span>
                                <span class="bg-amber-500/20 text-amber-400 border border-amber-500/30 px-2.5 py-0.5 rounded-lg text-[11px] font-extrabold shadow-inner">{{ $sjTypeCount }}</span>
                            </button>
                        </li>
                        <!-- Ticket Sistem Button -->
                        <li>
                            <button data-type-btn="TICKET_SISTEM" onclick="filterType('TICKET_SISTEM')" class="filter-btn w-full flex justify-between items-center px-3 py-2.5 rounded-xl bg-slate-900/80 hover:bg-gradient-to-r hover:from-orange-950/40 hover:to-slate-900 text-slate-200 font-semibold border border-slate-800/80 hover:border-orange-500/30 shadow-md transition-all duration-200 active:scale-95 focus:outline-none">
                                <span>Ticket Sistem</span>
                                <span class="bg-orange-500/20 text-orange-400 border border-orange-500/30 px-2.5 py-0.5 rounded-lg text-[11px] font-extrabold shadow-inner">{{ $ticketSistemCount }}</span>
                            </button>
                        </li>
                        <!-- Work Order Button -->
                        <li>
                            <button data-type-btn="WO" onclick="filterType('WO')" class="filter-btn w-full flex justify-between items-center px-3 py-2.5 rounded-xl bg-slate-900/80 hover:bg-gradient-to-r hover:from-emerald-950/40 hover:to-slate-900 text-slate-200 font-semibold border border-slate-800/80 hover:border-emerald-500/30 shadow-md transition-all duration-200 active:scale-95 focus:outline-none">
                                <span>Work Order</span>
                                <span class="bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 px-2.5 py-0.5 rounded-lg text-[11px] font-extrabold shadow-inner">{{ $woTypeCount }}</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </nav>
        </div>

        <!-- Bottom: Notifikasi dan Akun -->
        <div class="pt-4 border-t border-slate-800 space-y-3">
            <div class="flex items-center gap-2">
                @if (auth()->user()->isAdmin())
                    <button type="button" id="pushNotifBtn" onclick="togglePushNotification()" title="Notifikasi Request Baru & Reminder Peminjaman" class="shrink-0 flex items-center justify-center w-9 h-9 rounded-xl bg-slate-900/80 hover:bg-slate-800 text-slate-300 border border-slate-800/80 transition-all duration-200">
                        
                    </button>
                    <a href="{{ \App\Filament\Resources\Employees\EmployeeResource::getUrl() }}" title="Kelola Akun &amp; akses login (IT Asset › Karyawan)" class="shrink-0 flex items-center justify-center w-9 h-9 rounded-xl bg-slate-900/80 hover:bg-slate-800 text-slate-300 border border-slate-800/80 transition-all duration-200">
                        <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.59 3.94c.09-.54.56-.94 1.11-.94h2.59c.55 0 1.02.4 1.11.94l.21 1.28c.07.37.32.68.65.87.07.04.15.08.22.12.35.18.77.2 1.12.05l1.2-.48c.51-.2 1.09 0 1.37.48l1.3 2.24c.28.48.17 1.09-.25 1.45l-1.01.83c-.29.24-.44.6-.42.97a6.6 6.6 0 0 1 0 .25c-.02.37.13.73.42.97l1.01.83c.42.36.53.97.25 1.45l-1.3 2.24c-.28.48-.86.68-1.37.48l-1.2-.48a1.28 1.28 0 0 0-1.12.05c-.07.04-.15.08-.22.12-.33.19-.58.5-.65.87l-.21 1.28c-.09.54-.56.94-1.11.94H10.7c-.55 0-1.02-.4-1.11-.94l-.21-1.28a1.28 1.28 0 0 0-.65-.87 5.5 5.5 0 0 1-.22-.12c-.35-.18-.77-.2-1.12-.05l-1.2.48c-.51.2-1.09 0-1.37-.48l-1.3-2.24a1.13 1.13 0 0 1 .25-1.45l1.01-.83c.29-.24.44-.6.42-.97a6.6 6.6 0 0 1 0-.25c.02-.37-.13-.73-.42-.97l-1.01-.83a1.13 1.13 0 0 1-.25-1.45l1.3-2.24c.28-.48.86-.68 1.37-.48l1.2.48c.35.14.77.13 1.12-.05.07-.04.15-.08.22-.12.33-.19.58-.5.65-.87l.21-1.28ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                    </a>
                    <a href="{{ \Filament\Facades\Filament::getUrl() }}" title="Kembali ke IT Asset" class="shrink-0 flex items-center justify-center gap-1 px-3 h-9 rounded-xl bg-slate-900/80 hover:bg-slate-800 text-slate-300 text-xs font-semibold border border-slate-800/80 transition-all duration-200">
                        <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.06-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.38a1.5 1.5 0 0 1-1.06-.44Z"/></svg> IT Asset
                    </a>
                @endif
            </div>

            @include('requisition.partials.account-menu')
        </div>
    </aside>

    <!-- Main Content Area -->
    <main class="flex-1 h-screen p-6 md:p-8 space-y-6 overflow-y-auto">

        <!-- Page Title -->
        <div class="shrink-0 flex items-center gap-3">
            <button type="button" onclick="openSidebar()" title="Menu" class="lg:hidden shrink-0 flex items-center justify-center w-10 h-10 rounded-xl bg-slate-900/80 hover:bg-slate-800 text-white border border-slate-800/80 transition-all duration-200">
                ☰
            </button>
            <h1 class="text-2xl font-black text-white tracking-wide">Dashboard ITERA</h1>
        </div>

        <!-- Top Stat Cards (Neon Gradient Style) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 shrink-0">

            <!-- Card 1: Total Ticket -->
            <div class="stat-card p-5 rounded-2xl bg-gradient-to-br from-purple-600 to-indigo-600 text-white shadow-xl shadow-purple-900/20">
                <p class="text-xs font-semibold uppercase tracking-wider opacity-80">Total Requests</p>
                <div class="mt-2 flex items-baseline justify-between">
                    <span class="text-3xl font-extrabold">{{ $totalRequests }}</span>
                    <span class="text-xs bg-white/20 px-2 py-1 rounded-lg">Tickets</span>
                </div>
            </div>

            <!-- Card 2: Pending -->
            <div class="stat-card p-5 rounded-2xl bg-gradient-to-br from-cyan-500 to-blue-600 text-white shadow-xl shadow-cyan-900/20">
                <p class="text-xs font-semibold uppercase tracking-wider opacity-80">Pending Status</p>
                <div class="mt-2 flex items-baseline justify-between">
                    <span class="text-3xl font-extrabold">{{ $pendingRequests }}</span>
                    <span class="text-xs bg-white/20 px-2 py-1 rounded-lg">Need Action</span>
                </div>
            </div>

            <!-- Card 3: In Progress -->
            <div class="stat-card p-5 rounded-2xl bg-gradient-to-br from-purple-600 to-indigo-600 text-white shadow-xl shadow-purple-900/20">
                <p class="text-xs font-semibold uppercase tracking-wider opacity-80"    >In Progress</p>
                <div class="mt-2 flex items-baseline justify-between">
                    <span class="text-3xl font-extrabold">{{ $inProgressRequests }}</span>
                    <span class="text-xs bg-white/20 px-2 py-1 rounded-lg">Working</span>
                </div>
            </div>

            <!-- Card 4: Completed -->
            <div class="stat-card p-5 rounded-2xl bg-gradient-to-br from-cyan-500 to-blue-600 text-white shadow-xl shadow-cyan-900/20">
                <p class="text-xs font-semibold uppercase tracking-wider opacity-80">Completed</p>
                <div class="mt-2 flex items-baseline justify-between">
                    <span class="text-3xl font-extrabold">{{ $completedRequests }}</span>
                    <span class="text-xs bg-white/20 px-2 py-1 rounded-lg">Done</span>
                </div>
            </div>

        </div>

        <!-- Tombol pilih panel: Incoming Requests / List All Form -->
        <div class="flex flex-wrap items-center gap-3 shrink-0" role="tablist">
            <button type="button" id="tabIncoming" role="tab" onclick="showPanel('incoming')" class="panel-tab flex items-center gap-2 px-6 py-3 rounded-xl text-base font-bold border-2 transition">
                <span>Incoming Requests</span>
                <span id="pendingApprovalsBadge" class="px-2.5 py-0.5 rounded-full text-sm bg-amber-500 text-slate-900">{{ $pendingApprovals->total() }}</span>
            </button>
            <button type="button" id="tabAll" role="tab" onclick="showPanel('all')" class="panel-tab flex items-center gap-2 px-6 py-3 rounded-xl text-base font-bold border-2 transition">
                <span>List All Form</span>
            </button>
        </div>

        <!-- Lower Section -->
        <div id="panelIncoming" class="grid grid-cols-1 gap-6 shrink-0">

            <!-- Card: Incoming Requests -->
            <div class="glass-card p-6 rounded-2xl">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div>
                        <h3 class="text-lg font-bold text-white">Incoming Requests</h3>
                        <p class="text-sm text-slate-400">Request baru yang menunggu persetujuan Tim IT</p>
                    </div>
                    <span class="bg-amber-500/10 text-amber-400 border border-amber-500/30 px-4 py-1.5 rounded-full text-xs font-bold whitespace-nowrap">
                        <span id="pendingApprovalsCount">{{ $pendingApprovals->total() }}</span> Menunggu Approval
                    </span>
                </div>
                @if (($awaitingSupervisor ?? 0) > 0)
                    <div class="mb-4 rounded-xl border border-violet-300 bg-violet-50 p-4">
                        <div class="text-sm font-bold text-violet-900">{{ $awaitingSupervisor }} request menunggu persetujuan atasan</div>
                        <p class="text-xs text-violet-800 mb-2">Otomatis masuk ke sini setelah atasan menyetujui atau lewat {{ \App\Services\RequestSupervisor::ESCALATE_AFTER_DAYS }} hari. Bila atasan berhalangan, Tim IT dapat menyetujui atas namanya; nama akun Anda tercatat pada keputusan.</p>
                        <div class="space-y-2">
                            @foreach ($awaitingList as $w)
                                <form method="POST" action="{{ route('requests.supervisor.override', $w->id) }}" class="flex flex-wrap items-center gap-2 bg-white rounded-lg border border-violet-200 px-3 py-2 text-xs"
                                      onsubmit="var n = prompt('Setujui atas nama atasan ({{ e($w->supervisor?->name ?? 'atasan') }}) sebagai {{ e(auth()->user()->name) }}.\nCatatan (opsional):'); if (n === null) return false; this.note.value = n; return true;">
                                    @csrf
                                    <input type="hidden" name="note" value="">
                                    <span class="font-semibold text-slate-800">{{ $w->requester_name }}</span>
                                    <span class="text-slate-500">{{ $w->department }}</span>
                                    <span class="text-slate-700">· {{ $w->title }}</span>
                                    <span class="text-slate-500">· menunggu {{ $w->supervisor?->name ?? 'atasan' }} sejak {{ $w->supervisor_asked_at?->diffForHumans() }}</span>
                                    <button class="ml-auto px-3 py-1 rounded-md bg-violet-700 text-white font-semibold hover:bg-violet-600">Setujui (Tim IT)</button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="rtable w-full text-left text-xs text-slate-300">
                        <thead class="text-[10px] uppercase tracking-wider text-slate-400 border-b border-slate-800 bg-[#151b2c] sticky top-0 z-10">
                            <tr>
                                <th class="py-3 px-4">Nama / Dept / Username</th>
                                <th class="py-3 px-4">Tipe / Judul</th>
                                <th class="py-3 px-4">Jam Masuk</th>
                                <th class="py-3 px-4">
                                    <div class="text-right"><span>Approval</span></div>
                                </th>
                            </tr>
                        </thead>
                        <tbody id="pendingApprovalsBody" class="divide-y divide-slate-800">
                            @include('requisition.partials.pending-approvals-rows', ['pendingApprovals' => $pendingApprovals, 'canManage' => $canManage])
                        </tbody>
                    </table>
                </div>
                <div id="pendingApprovalsPagination">
                    @include('requisition.partials.pending-approvals-pagination', ['pendingApprovals' => $pendingApprovals])
                </div>
            </div>

        </div>

        <!-- Section History Request (Dark Mode Table) -->
        <div id="panelAll" class="hidden glass-card p-6 rounded-2xl shadow-xl">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4 shrink-0">
                <div>
                    <h3 class="text-lg font-bold text-white">List All Form</h3>
                    <p class="text-sm text-slate-400">Semua form yang sudah diproses. Klik No. Ticket untuk melihat rincian &amp; foto lampiran</p>
                </div>
                @if ($isSuperAdmin)
                    <button type="button" id="bulkDeleteBtn" onclick="bulkDeleteSelected()" disabled class="shrink-0 px-3 py-2 rounded-lg bg-rose-600/20 text-rose-400 border border-rose-500/30 text-[11px] font-bold uppercase tracking-wide opacity-40 cursor-not-allowed transition disabled:opacity-40 enabled:opacity-100 enabled:hover:bg-rose-600 enabled:hover:text-white">
                        <svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.35 9m-4.78 0L9.26 9m9.97-3.21c.34.05.68.11 1.02.17m-1.02-.17-1.07 13.9a2.25 2.25 0 0 1-2.24 2.08H8.08a2.25 2.25 0 0 1-2.24-2.08L4.77 5.79m14.46 0a48.1 48.1 0 0 0-3.48-.4m-12 .57c.34-.06.68-.12 1.02-.17m0 0a48.1 48.1 0 0 1 3.48-.4m7.5 0v-.92c0-1.18-.91-2.16-2.09-2.2a51.96 51.96 0 0 0-3.32 0c-1.18.04-2.09 1.02-2.09 2.2v.92m7.5 0a48.67 48.67 0 0 0-7.5 0"/></svg> Hapus Terpilih (<span id="bulkDeleteCount">0</span>)
                    </button>
                @endif
            </div>

            <!-- Filter Pencarian & Dropdown Kategori -->
            <div class="flex flex-col md:flex-row justify-between items-center gap-4 mb-4 shrink-0">
                <div class="relative w-full md:w-64">
                    <input type="text" id="searchInput" onkeyup="searchTable()" placeholder="Cari requester / judul..." class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-2 text-xs text-slate-200 focus:outline-none focus:border-fuchsia-500 transition">
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="rtable w-full min-w-[1100px] text-left text-xs text-slate-300">
                    <thead class="text-[10px] uppercase tracking-wider text-slate-400 border-b border-slate-800 bg-[#151b2c] sticky top-0 z-10">
                        <tr>
                            @if ($isSuperAdmin)
                                <th class="py-3 px-4 whitespace-nowrap">
                                    <input type="checkbox" id="selectAllRows" onchange="toggleSelectAll(this)" class="rounded border-slate-600 bg-slate-800 text-fuchsia-600 focus:ring-fuchsia-500">
                                </th>
                            @endif
                            <th class="py-3 px-4 whitespace-nowrap">No. Ticket</th>
                            <th class="py-3 px-4 whitespace-nowrap">Requester / Username</th>
                            <th class="py-3 px-4 whitespace-nowrap">Judul Request</th>
                            <th class="py-3 px-4 whitespace-nowrap">Status</th>
                            <th class="py-3 px-4 whitespace-nowrap">Disetujui Oleh</th>
                            <th class="py-3 px-4 whitespace-nowrap">Tanggal</th>
                            <th class="py-3 px-4 whitespace-nowrap">Durasi Pengerjaan</th>
                            <th class="py-3 px-4 whitespace-nowrap text-center md:sticky md:right-0 md:bg-[#151b2c] md:shadow-[-8px_0_8px_-8px_rgba(0,0,0,0.6)]">Aksi</th>
                            @if ($isSuperAdmin)
                                <th class="py-3 px-4 whitespace-nowrap text-center">Delete</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody id="listAllFormBody" class="divide-y divide-slate-800">
                        @include('requisition.partials.list-all-form-rows', ['requests' => $requests, 'canManage' => $canManage, 'isSuperAdmin' => $isSuperAdmin])
                    </tbody>
                </table>
            </div>
            <div id="listAllFormPagination">
                @include('requisition.partials.list-all-form-pagination', ['requests' => $requests])
            </div>
        </div>

    </main>

    <!-- Modal Detail Request (Pop-up) -->
    <div id="detailModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="glass-card w-full max-w-2xl rounded-2xl p-6 shadow-2xl relative border border-slate-700 max-h-[90vh] overflow-y-auto">
            
            <!-- Header Modal -->
            <div class="flex items-center justify-between pb-4 border-b border-slate-800">
                <div>
                    <span id="modalTicketNo" class="ticket-no font-mono text-xs font-bold bg-fuchsia-500/10 px-2.5 py-1 rounded-lg border border-fuchsia-500/20"></span>
                    <h2 id="modalTitle" class="text-lg font-bold text-white mt-2"></h2>
                </div>
                <button onclick="closeDetailModal()" class="text-slate-400 hover:text-white p-2 text-xl font-bold">&times;</button>
            </div>

            <!-- Body Modal -->
            <div class="py-4 space-y-4 text-sm">
                <!-- Info Requester & Status -->
                <div class="grid grid-cols-2 gap-4 bg-slate-900/50 p-3 rounded-xl border border-slate-800">
                    <div>
                        <p class="text-[10px] text-slate-500 uppercase font-semibold">Requester</p>
                        <p id="modalRequester" class="font-medium text-slate-200"></p>
                        <p id="modalUsername" class="text-[11px] text-slate-500"></p>
                    </div>
                    <div>
                        <p class="text-[10px] text-slate-500 uppercase font-semibold">Status</p>
                        <div class="flex space-x-2 mt-0.5">
                            <span id="modalStatus" class="text-xs font-bold px-2 py-0.5 rounded-full"></span>
                        </div>
                    </div>
                </div>

                <!-- Deskripsi Request -->
                <div>
                    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Deskripsi Permintaan</h4>
                    <p id="modalDescription" class="text-slate-300 bg-slate-900/30 p-3 rounded-xl border border-slate-800 whitespace-pre-line leading-relaxed text-xs">
                    </p>
                </div>

                <!-- Upload Lampiran Foto -->
                <div>
                    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Lampiran / Foto Upload</h4>
                    <div id="modalImageContainer" class="rounded-xl overflow-hidden border border-slate-800 bg-slate-900/50 p-2 text-center">
                        <!-- Tag <img> dibungkus dengan tag <a> -->
                        <a id="modalImageLink" href="#" target="_blank" title="Klik untuk membuka ukuran asli di tab baru">
                            <img id="modalImage" src="" alt="Lampiran Request" class="max-h-64 mx-auto rounded-lg object-contain hidden hover:opacity-80 transition duration-200 cursor-pointer" />
                        </a>
                        <p id="noImageText" class="text-xs text-slate-500 italic py-4">Tidak ada lampiran foto untuk request ini.</p>
                    </div>
                </div>
            </div>

            <!-- Footer Modal (dengan Tombol Cetak) -->
            <div class="pt-4 border-t border-slate-800 flex justify-between items-center">
                <button onclick="window.print()" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-xs font-semibold flex items-center space-x-1 transition">
                    <span><svg class="ic" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.83a42.4 42.4 0 0 1 10.56 0m-10.56 0c-1.1.13-2.16.3-3.2.5a.75.75 0 0 0-.52.74v4.18c0 .41.34.75.75.75H6.8m-.08-6.17L6.34 18m10.94-4.17c1.1.13 2.16.3 3.2.5.38.1.52.37.52.74v4.18c0 .41-.34.75-.75.75H17.2m.08-6.17L17.66 18m0 0 .23 2.82c.03.39-.28.72-.67.72H6.78c-.39 0-.7-.33-.67-.72L6.34 18m11.32 0H6.34m11.32-9.17V3.38c0-.62-.5-1.13-1.13-1.13H7.47c-.62 0-1.13.5-1.13 1.13v5.45"/></svg> Cetak Detail</span>
                </button>
                <button onclick="closeDetailModal()" class="px-4 py-2 bg-fuchsia-600 hover:bg-fuchsia-500 text-white rounded-xl text-xs font-bold transition">
                    Tutup
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Edit Form ITR/WO (khusus tim IT, perbaiki isian user sebelum diproses) -->
    <div id="editRequestModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/80 backdrop-blur-sm p-4">
        <div class="bg-[#111827] w-full max-w-4xl rounded-2xl shadow-2xl border border-slate-800 p-6 md:p-8 max-h-[90vh] overflow-y-auto relative">

            <!-- Overlay Loading/Sukses di tengah kartu form ini (bukan layar penuh) -->
            @include('requisition.partials.status-overlay', ['prefix' => 'edit', 'loading' => 'Menyimpan perubahan...', 'withOk' => false])

            <div class="flex items-center justify-between pb-4 border-b border-slate-800 mb-6">
                <h2 id="editModalTitle" class="text-xl font-black text-white uppercase tracking-wide">EDIT REQUEST</h2>
                <button type="button" onclick="closeEditModal()" class="text-slate-400 hover:text-white text-2xl font-bold">&times;</button>
            </div>

            <div id="editFormErrors" class="mb-4 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs space-y-0.5 hidden"></div>

            <form id="editRequestForm" method="POST" enctype="multipart/form-data" class="space-y-4">
                @csrf
                @method('PATCH')
                <div>
                    <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Nama Karyawan</label>
                    @include('requisition.partials.employee-name-input', ['ring' => 'fuchsia', 'id' => 'editEmployeeName', 'strict' => false, 'placeholder' => 'Ketik nama untuk memilih ulang dari daftar'])
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-300 uppercase mb-2">NIK Karyawan</label>
                        <input type="text" name="employee_nik" id="editEmployeeNik" required pattern="[0-9]*" title="NIK hanya angka" inputmode="numeric" autocomplete="off" oninput="this.value = this.value.replace(/[^0-9]/g, '')" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Department</label>
                        <select name="department" id="editDepartment" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                            <option value="">Pilih Dept</option>
                            @foreach ($departmentOptions as $dept)
                                <option value="{{ $dept }}">{{ $dept }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Jabatan</label>
                        <input type="text" name="position" id="editPosition" required autocomplete="off" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                    </div>
                </div>

                <div id="editTitleWrapper">
                    <label id="editTitleLabel" class="block text-xs font-bold text-slate-300 uppercase mb-2">Judul Permintaan</label>
                    <input type="text" name="title" id="editTitle" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                </div>

                <div id="editCategoryWrapper" class="hidden">
                    <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Kategori</label>
                    <select name="category" id="editCategory" disabled class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                        <option value="" disabled>Pilih Kategori</option>
                        @foreach ($ticketCategoryOptions as $category)
                            <option value="{{ $category }}">{{ $category }}</option>
                        @endforeach
                    </select>
                </div>


                <div id="editLoanDatesWrapper" class="hidden grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Tanggal Pinjam</label>
                        <input type="date" name="loan_start_date" id="editLoanStartDate" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Tanggal Kembali</label>
                        <input type="date" name="loan_end_date" id="editLoanEndDate" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                    </div>
                </div>

                <div id="editItemsWrapper">
                    <div class="flex items-center justify-between mb-2">
                        <label id="editItemsLabel" class="block text-xs font-bold text-slate-300 uppercase">Device / Barang yang Diminta (Maks. 5)</label>
                        <button type="button" onclick="addEditItem()" class="text-xs font-bold text-fuchsia-400 hover:text-fuchsia-300">+ Tambah</button>
                    </div>
                    <div id="editItemsContainer" class="space-y-2"></div>
                </div>

                <div>
                    <label id="editDescriptionLabel" class="block text-xs font-bold text-slate-300 uppercase mb-2">Deskripsi / Alasan Permintaan</label>
                    <textarea name="description" id="editDescription" rows="3" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500"></textarea>
                </div>

                <!-- Analisa & Rekomendasi (khusus BAK) — sengaja ditaruh tepat di bawah Kronologis
                     supaya tim IT bisa langsung lihat kronologisnya sambil mengisi analisa -->
                <div id="editAnalysisWrapper" class="hidden space-y-4 p-4 rounded-xl bg-cyan-500/5 border border-cyan-500/20">
                    <p class="text-[11px] text-amber-400">Analisa dan Rekomendasi wajib diisi sebelum BAK bisa di-approve.</p>
                    <div>
                        <label class="block text-xs font-bold text-cyan-300 uppercase mb-2">Analisa <span class="text-rose-400">*</span></label>
                        <textarea name="analisa" id="editAnalisa" rows="3" placeholder="Tulis analisa IT terhadap laporan ini..." class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-cyan-500"></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-cyan-300 uppercase mb-2">Rekomendasi <span class="text-rose-400">*</span></label>
                        <textarea name="rekomendasi" id="editRekomendasi" rows="3" placeholder="Tulis rekomendasi tindak lanjut..." class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-cyan-500"></textarea>
                    </div>
                </div>

                <div id="editAdminNotesWrapper" class="hidden">
                    <label id="editAdminNotesLabel" class="block text-xs font-bold text-slate-300 uppercase mb-2">Lokasi</label>
                    <input type="text" name="admin_notes" id="editAdminNotes" autocomplete="off" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                </div>

                <div id="editAttachmentWrapper">
                    <label id="editAttachmentLabel" class="block text-xs font-bold text-slate-300 uppercase mb-2">Upload Foto (Opsional)</label>
                    <div class="flex items-center gap-3">
                        <label for="editFotoInput" class="cursor-pointer shrink-0 px-4 py-2 rounded-md bg-fuchsia-600 hover:bg-fuchsia-700 text-white text-sm font-semibold transition">Foto</label>
                        <span id="editFotoFileName" class="text-sm text-slate-400 truncate">Kosongkan jika tidak ingin ganti foto</span>
                    </div>
                    <input type="file" id="editFotoInput" name="attachment" class="hidden" onchange="document.getElementById('editFotoFileName').textContent = this.files[0] ? this.files[0].name : 'Kosongkan jika tidak ingin ganti foto';">
                </div>

                <div class="flex items-center justify-end space-x-3 pt-4 border-t border-slate-800">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-white">Batal</button>
                    <button type="submit" class="bg-gradient-to-r from-fuchsia-600 to-purple-600 text-white font-bold py-2.5 px-6 rounded-xl shadow-lg">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Preview PDF -->
    <div id="pdfModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="glass-card w-full max-w-3xl h-[90vh] rounded-2xl shadow-2xl relative border border-slate-700 flex flex-col overflow-hidden">

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

    <!-- Modal Isi Keterangan (catatan bebas dari tim IT, tampil ke user di History Request Terbaru) -->
    <div id="noteModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
        <div class="glass-card w-full max-w-xl rounded-2xl shadow-2xl relative border border-slate-700 p-6">

            <!-- Overlay Loading/Sukses di tengah kartu form ini (bukan layar penuh) -->
            @include('requisition.partials.status-overlay', ['prefix' => 'note', 'loading' => 'Menyimpan keterangan...', 'withOk' => false])

            <div class="flex items-center justify-between pb-4 border-b border-slate-800 mb-4">
                <h2 class="text-sm font-bold text-white uppercase tracking-wide">Isi Keterangan untuk User</h2>
                <button type="button" onclick="closeNoteModal()" class="text-slate-400 hover:text-white text-2xl font-bold">&times;</button>
            </div>

            <div id="noteFormErrors" class="mb-4 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs space-y-0.5 hidden"></div>

            <form id="noteForm" method="POST" class="space-y-4">
                @csrf
                @method('PATCH')
                <div>
                    <label class="block text-xs font-bold text-slate-300 uppercase mb-2">Keterangan</label>
                    <textarea id="noteFieldUpdateNote" name="update_note" rows="4" placeholder="Contoh: catatan revisi saat request ditolak, atau info tindak lanjut..." class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500"></textarea>
                    <p class="text-[11px] text-slate-500 mt-1">Keterangan ini akan terlihat oleh user pemilik request di halaman History Request Terbaru.</p>
                </div>
                <div class="flex items-center justify-end space-x-3 pt-2">
                    <button type="button" onclick="closeNoteModal()" class="px-4 py-2 text-sm font-medium text-slate-400 hover:text-white">Batal</button>
                    <button type="submit" class="bg-gradient-to-r from-fuchsia-600 to-purple-600 text-white font-bold py-2.5 px-6 rounded-xl shadow-lg">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Upload Dokumen (semua form kecuali Ticket Sistem) -->
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

    @include('requisition.partials.submit-helper')

    <!-- Script Filter/Modal -->
    <script>
        // ===== Push Notification "Request Baru Masuk" (khusus tim IT) =====
        const vapidPublicKey = @json(config('webpush.vapid.public_key'));

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

        async function updatePushButtonState() {
            const btn = document.getElementById('pushNotifBtn');
            if (!btn || !('serviceWorker' in navigator) || !('PushManager' in window)) return;

            const registration = await navigator.serviceWorker.getRegistration();
            const subscription = registration ? await registration.pushManager.getSubscription() : null;

            if (subscription && Notification.permission === 'granted') {
                btn.textContent = '';
                btn.title = 'Notifikasi request baru & reminder peminjaman aktif (klik untuk matikan)';
            } else {
                btn.textContent = '';
                btn.title = 'Klik untuk aktifkan notifikasi request baru & reminder peminjaman';
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

        // ===== Sidebar drawer (mobile) - sidebar-nya sendiri "hidden md:flex" di desktop, jadi di
        // mobile satu-satunya jalan masuk (Kelola Akun, filter, ganti tema, dst.) adalah lewat
        // tombol hamburger ini yang menggeser sidebar masuk sebagai drawer di atas konten. =====
        function openSidebar() {
            document.getElementById('sidebar').classList.remove('-translate-x-full');
            document.getElementById('sidebarBackdrop').classList.remove('hidden');
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.add('-translate-x-full');
            document.getElementById('sidebarBackdrop').classList.add('hidden');
        }

        // ===== Filter Gabungan (AND): Status + Kategori + Search =====
        // Tabel "List All Form" dipaginasi server-side; setiap ganti filter/halaman
        // mengambil ulang data via AJAX supaya tidak perlu load semua baris sekaligus.
        const activeFilters = { status: 'All', type: 'All', search: '' };
        const listAllFormUrl = @json(route('requests.list-all-form'));
        let listAllFormRequestSeq = 0;
        let lastListAllFormSnapshot = null;
        let lastPendingApprovalsHtml = null;
        let currentPendingPage = 1;

        // Ganti panel yang tampil: 'incoming' (Incoming Requests) atau 'all' (List All Form).
        // Pilihan terakhir diingat di browser ini supaya tidak kembali ke default tiap reload.
        const PANEL_STORAGE_KEY = 'dashboardPanel';
        function showPanel(name) {
            const showAll = name === 'all';
            document.getElementById('panelIncoming').classList.toggle('hidden', showAll);
            document.getElementById('panelAll').classList.toggle('hidden', !showAll);
            document.getElementById('tabIncoming').classList.toggle('panel-tab-active', !showAll);
            document.getElementById('tabAll').classList.toggle('panel-tab-active', showAll);
            document.getElementById('tabIncoming').setAttribute('aria-selected', String(!showAll));
            document.getElementById('tabAll').setAttribute('aria-selected', String(showAll));
            try { localStorage.setItem(PANEL_STORAGE_KEY, name); } catch (e) {}
        }
        (function () {
            let saved = 'incoming';
            try { saved = localStorage.getItem(PANEL_STORAGE_KEY) === 'all' ? 'all' : 'incoming'; } catch (e) {}
            showPanel(saved);
        })();

        // True kalau fokus sedang ada di dropdown/input di dalam elemen tsb (mis. saat memilih status).
        function isInteractingWithin(containerId) {
            const el = document.activeElement;
            const container = document.getElementById(containerId);
            return !!(el && container && container.contains(el) && ['SELECT', 'INPUT', 'TEXTAREA'].includes(el.tagName));
        }
        let currentListAllFormPage = 1;

        function loadListAllForm(page) {
            page = page || 1;
            currentListAllFormPage = page;
            const params = new URLSearchParams({
                page: page,
                status: activeFilters.status,
                type: activeFilters.type,
                search: activeFilters.search,
            });

            const seq = ++listAllFormRequestSeq;
            fetch(listAllFormUrl + '?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(res => res.json())
                .then(data => {
                    if (seq !== listAllFormRequestSeq) return; // Respon lama, abaikan (race condition saat ganti filter cepat)
                    // Jangan bongkar tabel kalau isinya sama persis (kalau tidak, hover berkedip & dropdown yang
                    // sedang dibuka langsung menutup) atau saat user sedang memilih dropdown/mengetik di dalamnya.
                    const snapshot = data.rows_html + '\u0000' + data.pagination_html;
                    if (snapshot === lastListAllFormSnapshot || isInteractingWithin('listAllFormBody')) return;
                    lastListAllFormSnapshot = snapshot;
                    document.getElementById('listAllFormBody').innerHTML = data.rows_html;
                    document.getElementById('listAllFormPagination').innerHTML = data.pagination_html;
                    updateBulkDeleteState();
                })
                .catch(() => {
                    // Diamkan saja kalau gagal (misal koneksi putus) — data lama tetap tampil
                });
        }

        // Gate "wajib klik View dulu" sebelum Keterangan/Reject/Approve bisa dipakai - tombolnya
        // sengaja dirender `disabled` dari server (lihat partials.pending-approvals-rows), di sini
        // cuma menyalakan lagi begitu View item itu sudah pernah diklik. viewedRequestIds disimpan
        // di localStorage (bukan cuma di memory) supaya bertahan lewat reload - tanpa ini, submit
        // form edit/keterangan yang diikuti window.location.reload() akan mengunci ulang tombol
        // pada item yang sebenarnya sudah dilihat admin. direset lagi tiap 2 detik panel ini
        // di-refresh (innerHTML diganti total), makanya applyViewGate() harus dipanggil ulang tiap
        // habis refresh supaya baris yang sudah dilihat tidak balik terkunci.
        const VIEWED_REQUESTS_STORAGE_KEY = 'viewedRequestIds';

        function loadViewedRequestIds() {
            try {
                return new Set(JSON.parse(localStorage.getItem(VIEWED_REQUESTS_STORAGE_KEY) || '[]'));
            } catch (e) {
                return new Set();
            }
        }

        function saveViewedRequestIds() {
            try {
                localStorage.setItem(VIEWED_REQUESTS_STORAGE_KEY, JSON.stringify(Array.from(viewedRequestIds)));
            } catch (e) {
                // Diamkan saja kalau localStorage tidak tersedia (mis. private mode) - gate tetap
                // jalan seperti sebelumnya, cuma tidak bertahan lewat reload.
            }
        }

        const viewedRequestIds = loadViewedRequestIds();

        function markViewed(id) {
            viewedRequestIds.add(id);
            saveViewedRequestIds();
            applyViewGate();
        }

        function applyViewGate() {
            document.querySelectorAll('#pendingApprovalsBody tr[data-request-id]').forEach(function (row) {
                const id = parseInt(row.dataset.requestId, 10);
                const unlocked = viewedRequestIds.has(id);
                row.querySelectorAll('.view-gated').forEach(function (btn) {
                    btn.disabled = !unlocked;
                    // tooltip ikut berubah: "Klik View dulu…" hanya saat terkunci
                    const tip = unlocked ? btn.dataset.readyTitle : btn.dataset.lockedTitle;
                    if (tip) btn.title = tip;
                });
            });
        }

        // Saat halaman dimuat, baris dari server selalu dirender terkunci. Item yang sudah pernah di-View (tersimpan di localStorage)
        // harus langsung terbuka, tanpa menunggu polling pertama (dulu tombol terkunci ±2 detik setiap kali halaman dimuat ulang).
        applyViewGate();

        // Auto-refresh "Incoming Requests" secara berkala (tanpa reload halaman),
        // supaya request baru dari user lain kelihatan tanpa harus manual F5.
        const pendingApprovalsUrl = @json(route('requests.pending-approvals'));
        let pendingApprovalsRequestSeq = 0;
        let knownPendingIds = null; // null = belum pernah load (jangan toast di load pertama)

        function loadPendingApprovals(page) {
            if (page) currentPendingPage = page;
            const seq = ++pendingApprovalsRequestSeq;
            fetch(pendingApprovalsUrl + '?pending_page=' + currentPendingPage, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(res => res.json())
                .then(data => {
                    if (seq !== pendingApprovalsRequestSeq) return; // Respon lama, abaikan
                    // Halaman yang sedang dibuka sudah kosong (mis. semua isinya baru di-approve): mundur ke halaman terakhir
                    if (currentPendingPage > data.last_page) {
                        currentPendingPage = Math.max(1, data.last_page);
                        loadPendingApprovals();
                        return;
                    }
                    const pendingSnapshot = data.rows_html + '\u0000' + data.pagination_html;
                    if (pendingSnapshot !== lastPendingApprovalsHtml && !isInteractingWithin('pendingApprovalsBody')) {
                        lastPendingApprovalsHtml = pendingSnapshot;
                        document.getElementById('pendingApprovalsBody').innerHTML = data.rows_html;
                        document.getElementById('pendingApprovalsPagination').innerHTML = data.pagination_html;
                        applyViewGate();
                    }
                    document.getElementById('pendingApprovalsCount').textContent = data.count;
                    document.getElementById('pendingApprovalsBadge').textContent = data.count;

                    const currentIds = new Set((data.items || []).map(i => i.id));

                    // Buang id yang sudah tidak lagi pending (sudah diproses admin lain/lewat halaman
                    // lain) dari viewedRequestIds, supaya localStorage tidak menumpuk id yang basi.
                    let viewedIdsChanged = false;
                    viewedRequestIds.forEach(function (id) {
                        if (!currentIds.has(id)) {
                            viewedRequestIds.delete(id);
                            viewedIdsChanged = true;
                        }
                    });
                    if (viewedIdsChanged) saveViewedRequestIds();

                    if (knownPendingIds !== null) {
                        const newItems = (data.items || []).filter(i => !knownPendingIds.has(i.id));
                        if (newItems.length > 0) {
                            playNotificationSound();
                        }
                        newItems.forEach(item => {
                            const typeLabel = item.type === 'TICKET_SISTEM' ? 'Ticket Sistem' : item.type;
                            showToast({
                                icon: '',
                                title: 'Request Baru: ' + typeLabel,
                                body: (item.requester_name || 'N/A') + ' — ' + item.title,
                                accent: 'fuchsia',
                            });
                        });
                    }
                    knownPendingIds = currentIds;
                })
                .catch(() => {
                    // Diamkan saja kalau gagal — data lama tetap tampil
                });
        }

        // Polling ringan tiap 25 detik: cek request baru & sinkronkan List All Form di halaman aktif.
        // Berhenti otomatis saat tab disembunyikan (hemat request kalau dashboard dibiarkan di background).
        const AUTO_REFRESH_INTERVAL_MS = 2000;
        let autoRefreshTimer = setInterval(function () {
            if (document.hidden) return;
            loadPendingApprovals(currentPendingPage);
            loadListAllForm(currentListAllFormPage);
        }, AUTO_REFRESH_INTERVAL_MS);

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                // Begitu tab aktif lagi, langsung sinkronkan (jangan tunggu interval berikutnya)
                loadPendingApprovals(currentPendingPage);
                loadListAllForm(currentListAllFormPage);
            }
        });

        function setActiveButton(attr, value) {
            document.querySelectorAll(`[${attr}]`).forEach(btn => {
                btn.classList.toggle('filter-btn-active', btn.getAttribute(attr) === value);
            });
        }

        // Function Filter Status Tabel Sidebar
        function filterStatus(status) {
            activeFilters.status = status;
            setActiveButton('data-status-btn', status);
            showPanel('all');
            loadListAllForm(1);
            closeSidebar(); // di mobile, tutup drawer supaya langsung lihat hasil filter di konten
        }

        // Function Filter Kategori/Tipe Request (IT Request, Tanda Terima, BAK, Surat Jalan)
        function filterType(type) {
            activeFilters.type = type;
            setActiveButton('data-type-btn', type);
            showPanel('all');
            loadListAllForm(1);
            closeSidebar(); // di mobile, tutup drawer supaya langsung lihat hasil filter di konten
        }

        // Function Search Table Real-time (debounce supaya tidak fetch di setiap ketukan huruf)
        let searchDebounceTimer;
        function searchTable() {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => {
                activeFilters.search = document.getElementById('searchInput').value;
                loadListAllForm(1);
            }, 350);
        }

        // Set tombol default aktif saat halaman dimuat
        setActiveButton('data-status-btn', 'All');
        setActiveButton('data-type-btn', 'All');

        // Modal Handlers
        function openDetailModal(item) {
            const ticketNo = item.ticket_number || 'Menunggu Approval';

            document.getElementById('modalTicketNo').innerText = ticketNo;
            document.getElementById('modalTitle').innerText = item.title || item.subject || 'Detail Request';
            document.getElementById('modalRequester').innerText = item.requester_name || (item.user ? item.user.name : 'N/A');
            document.getElementById('modalUsername').innerText = (item.user && item.user.username) ? item.user.username : '';
            document.getElementById('modalStatus').innerText = item.status || 'Pending';
            document.getElementById('modalDescription').innerText = item.description || item.details || 'Tidak ada deskripsi rincian.';

            // Handling Image Upload
            const imgElement = document.getElementById('modalImage');
            const imgLink = document.getElementById('modalImageLink');
            const noImgText = document.getElementById('noImageText');

            const imagePath = item.attachment || item.image || item.photo || item.file_path;

            if (imagePath) {
                const fullUrl = imagePath.startsWith('http') ? imagePath : `/storage/${imagePath}`;
                imgElement.src = fullUrl;
                imgLink.href = fullUrl;
                imgElement.classList.remove('hidden');
                noImgText.classList.add('hidden');
            } else {
                imgElement.classList.add('hidden');
                imgLink.href = "#";
                noImgText.classList.remove('hidden');
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

        // Modal Edit Form ITR/WO
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
                        <input type="text" name="items[${idx}][name]" value="${escapeEditAttr(it.name)}" placeholder="Nama Device / Perangkat" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                        <input type="number" name="items[${idx}][qty]" min="1" value="${it.qty || 1}" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                        <input type="text" name="items[${idx}][spesifikasi]" value="${escapeEditAttr(it.spesifikasi)}" placeholder="Spesifikasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                        <textarea name="items[${idx}][keterangan]" placeholder="Keterangan" rows="2" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500 resize-y">${escapeEditAttr(it.keterangan)}</textarea>
                        <button type="button" onclick="removeEditItem(this)" class="text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
                    </div>`;
            }
            if (editItemType === 'BAK') {
                return `
                    <div class="edit-item-row grid grid-cols-[24px_1fr_1fr_1fr_1fr_auto] gap-2 items-start">
                        <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">${idx + 1}</div>
                        <input type="text" name="items[${idx}][name]" value="${escapeEditAttr(it.name)}" placeholder="Nama Asset" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                        <input type="text" name="items[${idx}][spesifikasi]" value="${escapeEditAttr(it.spesifikasi)}" placeholder="Spesifikasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                        <input type="text" name="items[${idx}][serial_number]" value="${escapeEditAttr(it.serial_number)}" placeholder="Serial Number" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                        <textarea name="items[${idx}][keterangan]" placeholder="Keterangan" rows="2" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500 resize-y">${escapeEditAttr(it.keterangan)}</textarea>
                        <button type="button" onclick="removeEditItem(this)" class="text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
                    </div>`;
            }
            if (editItemType === 'PEMINJAMAN_ASET') {
                return `
                    <div class="edit-item-row grid grid-cols-[24px_1fr_1fr_1fr_auto] gap-2 items-start">
                        <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">${idx + 1}</div>
                        <input type="text" name="items[${idx}][name]" value="${escapeEditAttr(it.name)}" placeholder="Nama Aset" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                        <input type="text" name="items[${idx}][spesifikasi]" value="${escapeEditAttr(it.spesifikasi)}" placeholder="Spesifikasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                        <input type="text" name="items[${idx}][serial_number]" value="${escapeEditAttr(it.serial_number)}" placeholder="Serial Number" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                        <button type="button" onclick="removeEditItem(this)" class="text-rose-400 hover:text-rose-300 text-sm font-bold px-2 py-3 pt-3">✕</button>
                    </div>`;
            }
            return `
                <div class="edit-item-row grid grid-cols-[24px_1fr_1fr_60px_1fr_auto] gap-2 items-start">
                    <div class="item-no text-center text-xs font-bold text-slate-500 pt-3">${idx + 1}</div>
                    <input type="text" name="items[${idx}][name]" value="${escapeEditAttr(it.name)}" placeholder="Jenis instalasi (mis. Access Point, Kabel LAN, CCTV)" required class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                    <input type="text" name="items[${idx}][lokasi]" value="${escapeEditAttr(it.lokasi)}" placeholder="Lokasi" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                    <input type="number" name="items[${idx}][qty]" min="1" value="${it.qty || 1}" placeholder="Qty" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500">
                    <textarea name="items[${idx}][keterangan]" placeholder="Keterangan" rows="2" class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-3 py-3 text-sm text-white focus:ring-2 focus:ring-fuchsia-500 resize-y">${escapeEditAttr(it.keterangan)}</textarea>
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

        function openEditModal(item) {
            document.getElementById('editFormErrors').classList.add('hidden');
            srHideOverlay('edit');
            document.getElementById('editRequestForm').dataset.busy = '0';
            editItemType = item.type;

            const editTypeLabelMap = { TICKET_SISTEM: 'TICKET SISTEM', PEMINJAMAN_ASET: 'PEMINJAMAN ASET' };
            const editTypeLabel = editTypeLabelMap[item.type] || item.type;
            document.getElementById('editRequestForm').action = `{{ url('/requisition/requests') }}/${item.id}`;
            document.getElementById('editModalTitle').innerText = 'EDIT ' + editTypeLabel + ' — ' + (item.ticket_number || 'Menunggu Approval');
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
            const analysisWrapper = document.getElementById('editAnalysisWrapper');
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
            analysisWrapper.classList.add('hidden');
            document.getElementById('editAnalisa').value = '';
            document.getElementById('editRekomendasi').value = '';
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
                analysisWrapper.classList.remove('hidden');
                document.getElementById('editAnalisa').value = item.analisa || '';
                document.getElementById('editRekomendasi').value = item.rekomendasi || '';
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

        function closeEditModal() {
            const modal = document.getElementById('editRequestModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // Modal Preview PDF
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

        // Hapus request (satu / banyak sekaligus) - form dibuat on-the-fly supaya tidak nested di dalam tabel
        const csrfToken = '{{ csrf_token() }}';

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

            ids.forEach((id) => {
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'ids[]';
                idInput.value = id;
                form.appendChild(idInput);
            });

            document.body.appendChild(form);
            form.submit();
        }

        function deleteRequest(id, ticketNumber) {
            if (!confirm('Yakin ingin menghapus request ' + ticketNumber + '? Tindakan ini tidak bisa dibatalkan.')) {
                return;
            }
            submitDeleteForm('{{ url('/requisition/requests') }}/' + id, [id]);
        }

        function toggleSelectAll(checkbox) {
            document.querySelectorAll('.row-select-checkbox').forEach((cb) => {
                cb.checked = checkbox.checked;
            });
            updateBulkDeleteState();
        }

        function updateBulkDeleteState() {
            const checked = document.querySelectorAll('.row-select-checkbox:checked');
            const btn = document.getElementById('bulkDeleteBtn');
            const countEl = document.getElementById('bulkDeleteCount');
            if (!btn) return;
            countEl.textContent = checked.length;
            btn.disabled = checked.length === 0;
        }

        function bulkDeleteSelected() {
            const ids = Array.from(document.querySelectorAll('.row-select-checkbox:checked')).map((cb) => cb.value);
            if (ids.length === 0) return;
            if (!confirm('Yakin ingin menghapus ' + ids.length + ' request terpilih? Tindakan ini tidak bisa dibatalkan.')) {
                return;
            }
            submitDeleteForm('{{ route('requests.bulk-destroy') }}', ids);
        }

        // Modal Isi Keterangan untuk User (catatan revisi/reject atau info dari tim IT)
        function openNoteModal(updateUrl, updateNote) {
            document.getElementById('noteFormErrors').classList.add('hidden');
            srHideOverlay('note');
            document.getElementById('noteForm').dataset.busy = '0';
            document.getElementById('noteForm').action = updateUrl;
            document.getElementById('noteFieldUpdateNote').value = updateNote || '';

            const modal = document.getElementById('noteModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeNoteModal() {
            const modal = document.getElementById('noteModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
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

        // Pesan error dari server untuk aksi yang memakai form biasa (mis. Approve BAK sebelum Analisa/Rekomendasi diisi).
        @if (session('error'))
            showToast({ icon: '⚠', title: 'Aksi tidak bisa dilakukan', body: @json(session('error')), accent: 'rose' });
        @endif
        @if (session('success'))
            showToast({ icon: '✓', title: 'Berhasil', body: @json(session('success')), accent: 'emerald' });
        @endif
        @if ($errors->has('document'))
            showToast({ icon: '⚠', title: 'Upload dokumen gagal', body: @json($errors->first('document')), accent: 'rose' });
        @endif

        // Submit form Edit Request & Analisa/Rekomendasi via AJAX: loading dulu sampai server
        // selesai memproses, baru muncul notifikasi berhasil — tidak ada reload di tengah proses.
        function showFormErrors(containerId, errors) {
            const box = document.getElementById(containerId);
            const messages = Object.values(errors || {}).flat();
            box.innerHTML = messages.map(function (m) { return '<div>• ' + m + '</div>'; }).join('');
            box.classList.remove('hidden');
            box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        const formPrefixMap = { editRequestForm: 'edit', noteForm: 'note' };

        document.querySelectorAll('#editRequestForm, #noteForm').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (e.defaultPrevented) return;

                e.preventDefault();

                // Cegah kirim ganda (klik/Enter dua kali)
                if (form.dataset.busy === '1') return;
                form.dataset.busy = '1';

                // Loading/sukses/error tampil DI DALAM kartu modal yang sedang dibuka (bukan layar penuh)
                const prefix = formPrefixMap[form.id];
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
                            document.getElementById(prefix + 'StatusSuccess').classList.remove('hidden');
                            setTimeout(function () { window.location.reload(); }, 2200);
                        } else {
                            const friendly = srFriendlyError(result.status, result.data);
                            fail((result.data && result.data.errors) ? result.data.errors : {
                                _: [friendly || 'Gagal disimpan, silakan coba lagi.'],
                            });
                        }
                    })
                    .catch(function (err) {
                        fail({ _: [err && err.timeout
                            ? 'Server terlalu lama merespons. Perubahan belum tentu tersimpan - muat ulang halaman untuk memeriksa.'
                            : 'Gagal terhubung ke server. Periksa koneksi internet Anda lalu coba lagi.'] });
                    });
            });
        });

        // Modal Edit Update (ITR)
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
