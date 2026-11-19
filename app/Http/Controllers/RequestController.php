<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\ITRequest;
use App\Models\RequestItemLink;
use App\Models\User;
use App\Notifications\NewRequestSubmitted;
use App\Notifications\RequestStatusUpdated;
use App\Services\TelegramNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Barryvdh\DomPDF\Facade\Pdf;

class RequestController extends Controller
{
    // Pilihan Kategori Tiket untuk tipe TICKET_SISTEM (menggantikan judul bebas)
    public const TICKET_CATEGORIES = [
        'Hardware', 'Software', 'Network', 'Account & Access Sharing Folder',
    ];

    // Nama Karyawan wajib huruf besar di awal tiap kata (Title Case), contoh: "Dendi Ardiansyah"
    private const NAME_TITLE_CASE_REGEX = '/^[A-Z][a-z]+( [A-Z][a-z]+)*$/';

// 1. Method Index Dashboard
    public function index(Request $request)
    {
        // Role 'user' tidak boleh akses dashboard sama sekali, arahkan ke Pilih Jenis Request
        if (auth()->user()->role === 'user') {
            return redirect()->route('requests.create');
        }

        // Statistik per status untuk Card Dashboard & tombol filter status di sidebar - keduanya
        // memicu filter ke tabel "List All Form" (lihat filterStatus() di dashboard.blade.php), jadi
        // angkanya HARUS dihitung dari scope yang sama dengan tabel itu (listAllFormVisibleQuery()),
        // bukan hitungan mentah semua request. Kalau tidak, badge "Pending: 6" misalnya bisa menampilkan
        // tabel kosong begitu diklik, karena request Pending yang belum pernah diproses memang sengaja
        // tidak muncul di List All Form (ada di panel "Incoming Requests" sendiri).
        $totalRequests = $this->listAllFormVisibleQuery()->count();
        $pendingRequests = $this->listAllFormVisibleQuery()->where('status', 'Pending')->count();
        $inProgressRequests = $this->listAllFormVisibleQuery()->where('status', 'In Progress')->count();
        $completedRequests = $this->listAllFormVisibleQuery()->where('status', 'Completed')->count();

        // Jumlah request per tipe (untuk tombol filter kategori) - sama seperti badge status di atas,
        // tombolnya juga memfilter tabel "List All Form" (lihat filterType()), jadi pakai scope yang sama.
        $itrTypeCount = $this->listAllFormVisibleQuery()->where('type', 'ITR')->count();
        $bakTypeCount = $this->listAllFormVisibleQuery()->where('type', 'BAK')->count();
        $sjTypeCount = $this->listAllFormVisibleQuery()->where('type', 'SURAT_JALAN')->count();
        $ticketSistemCount = $this->listAllFormVisibleQuery()->where('type', 'TICKET_SISTEM')->count();
        $woTypeCount = $this->listAllFormVisibleQuery()->where('type', 'WO')->count();

        // Tabel "List All Form": dipaginasi server-side, halaman pertama dimuat langsung di sini,
        // filter/halaman berikutnya diambil via AJAX ke listAllForm() (lihat method di bawah)
        $requests = $this->filteredRequestsQuery($request)->paginate(25);

        // Incoming Requests (Pending) untuk form yang butuh approval IT: ITR, BAK, Ticket Sistem, WO
        $pendingApprovals = $this->pendingApprovalsQuery()->paginate(25, ['*'], 'pending_page');

        // RBAC: role 'user' hanya bisa melihat, tidak bisa approve/reject/edit
        $canManage = auth()->user()->canManageRequests();
        // Kolom & tombol Delete di tabel "List All Form" sekarang khusus Super Admin, admin biasa tidak lagi bisa hapus dari sini
        $isSuperAdmin = auth()->user()->isSuperAdmin();

        return view('requisition.dashboard', compact(
            'totalRequests', 'pendingRequests', 'inProgressRequests', 'completedRequests',
            'requests',
            'pendingApprovals', 'canManage', 'isSuperAdmin',
            'itrTypeCount', 'bakTypeCount', 'sjTypeCount', 'ticketSistemCount', 'woTypeCount'
        ));
    }

    // Scope dasar tabel "List All Form" (dipakai juga untuk hitungan badge status di dashboard, supaya
    // angkanya konsisten dengan isi tabel begitu difilter - lihat RequestController::index()).
    // PEMINJAMAN_ASET sengaja tidak pernah muncul di sini — punya list terpisah sendiri (lihat
    // PeminjamanController). ITR/TICKET_SISTEM/WO/BAK baru muncul di sini setelah dapat nomor tiket
    // (approved+, atau direvert ke Pending setelah sempat diproses), SURAT_JALAN & TT selalu tampil
    // karena memang tidak melalui approval.
    private function listAllFormVisibleQuery()
    {
        return ITRequest::where('type', '!=', 'PEMINJAMAN_ASET')
            ->where(function ($q) {
                $q->whereNotIn('type', ['ITR', 'TICKET_SISTEM', 'WO', 'BAK'])
                    ->orWhereNotNull('sequence_number');
            });
    }

    // Query dasar tabel "List All Form" + filter opsional dari query string (search, type, status)
    private function filteredRequestsQuery(Request $request)
    {
        $query = $this->listAllFormVisibleQuery()->with(['approvedBy', 'user', 'accessoryHandovers']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('requester_name', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type') && $request->type !== 'All') {
            $query->where('type', $request->type);
        }

        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }

        // Urut menurut waktu nomor terbit (approval), yang baru disetujui paling atas; request lama pakai approved_at/created_at.
        return $query->orderByRaw('COALESCE(numbered_at, approved_at, created_at) desc')->orderByDesc('id');
    }


    // 1b. Endpoint AJAX untuk filter & ganti halaman tabel "List All Form" tanpa reload penuh
    public function listAllForm(Request $request)
    {
        if (auth()->user()->role === 'user') {
            abort(403);
        }

        $requests = $this->filteredRequestsQuery($request)->paginate(25);
        $canManage = auth()->user()->canManageRequests();
        $isSuperAdmin = auth()->user()->isSuperAdmin();

        return response()->json([
            'rows_html' => view('requisition.partials.list-all-form-rows', compact('requests', 'canManage', 'isSuperAdmin'))->render(),
            'pagination_html' => view('requisition.partials.list-all-form-pagination', compact('requests'))->render(),
            'total' => $requests->total(),
        ]);
    }

    // Query "Incoming Requests (Pending)" — form ITR/BAK/Ticket Sistem/WO/Peminjaman Aset yang masih
    // menunggu approval IT. Peminjaman Aset baru masuk ke list-nya sendiri (lihat PeminjamanController)
    // setelah keluar dari Pending, sama seperti tipe lain yang tidak muncul di List All Form saat Pending.
    // sequence_number null = benar-benar belum pernah diproses sama sekali (lihat komentar di
    // getTicketNumberAttribute) - kalau tim IT mengubah status yang SUDAH pernah diproses balik ke
    // Pending (lewat dropdown status), sequence_number-nya tetap terisi (frozen), jadi item itu sengaja
    // TIDAK muncul lagi di sini seolah-olah request baru - cukup terlihat statusnya "Pending" lagi di
    // List All Form/list Peminjaman & History Request Terbaru.
    private function pendingApprovalsQuery()
    {
        return ITRequest::with('user')
            ->where('status', 'Pending')
            ->whereNull('sequence_number')
            ->whereIn('type', ['ITR', 'BAK', 'TICKET_SISTEM', 'WO', 'PEMINJAMAN_ASET'])
            ->latest();
    }

    // 1c. Endpoint AJAX untuk auto-refresh "Incoming Requests (Pending)" secara berkala tanpa reload
    public function pendingApprovalsJson()
    {
        if (auth()->user()->role === 'user') {
            abort(403);
        }

        $pendingApprovals = $this->pendingApprovalsQuery()->paginate(25, ['*'], 'pending_page');
        $canManage = auth()->user()->canManageRequests();

        return response()->json([
            'rows_html' => view('requisition.partials.pending-approvals-rows', compact('pendingApprovals', 'canManage'))->render(),
            'pagination_html' => view('requisition.partials.pending-approvals-pagination', compact('pendingApprovals'))->render(),
            'count' => $pendingApprovals->total(),
            'last_page' => $pendingApprovals->lastPage(),
            // Data ringkas SEMUA request pending (bukan cuma halaman ini) buat deteksi "request baru"
            // dan pembersihan id yang sudah tidak pending di sisi client
            'items' => $this->pendingApprovalsQuery()->get(['id', 'type', 'title', 'requester_name'])->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->type,
                'title' => $item->title,
                'requester_name' => $item->requester_name,
            ])->values(),
        ]);
    }

    // 2. Form Tambah Request
    public function create()
    {
        $requests = $this->publicHistoryQuery();

        return view('requisition.requests.create', compact('requests'));
    }

    // History Request Terbaru di halaman publik ini menampilkan tipe ITR, Ticket Sistem, BAK, WO &
    // Peminjaman Aset - langsung muncul begitu berhasil dibuat dengan status Pending ("Menunggu
    // Approval"), supaya user langsung lihat request-nya masuk tanpa perlu menunggu diproses tim IT
    // dulu. Beda dengan "List All Form" (RequestController::filteredRequestsQuery) yang tetap
    // mengecualikan Peminjaman Aset - itu khusus tabel manajemen admin, bukan visibilitas publik ini.
    private function publicHistoryQuery()
    {
        return ITRequest::with('approvedBy')
            ->whereIn('type', ['ITR', 'TICKET_SISTEM', 'BAK', 'WO', 'PEMINJAMAN_ASET'])
            ->latest()
            ->take(15)
            ->get();
    }

    // 2b. Endpoint AJAX untuk auto-refresh History Request Terbaru (berlaku semua role, termasuk user)
    public function publicHistoryJson()
    {
        $requests = $this->publicHistoryQuery();

        return response()->json([
            'rows_html' => view('requisition.partials.public-history-rows', compact('requests'))->render(),
            // Data ringkas buat deteksi "status berubah" di sisi client (untuk toast notifikasi dalam aplikasi)
            'items' => $requests->map(fn ($item) => [
                'id' => $item->id,
                'user_id' => $item->user_id,
                'status' => $item->status,
                'title' => $item->title,
                'type' => $item->type,
            ])->values(),
        ]);
    }

    /**
     * Karyawan yang dipilih pemohon dari database (kolom Nama berupa kotak cari). Pada request baru WAJIB dipilih dari daftar; pada edit
     * boleh tidak dikirim (request lama yang belum terhubung). $keepId = karyawan yang sudah tertaut: tetap sah walau kini resign.
     */
    private function resolveEmployee(Request $request, bool $required, ?int $keepId = null): ?Employee
    {
        $request->validate(['employee_id' => [$required ? 'required' : 'nullable', 'bail', 'integer', function ($attribute, $value, $fail) use ($keepId) {
            $e = Employee::find($value);
            if (! $e || ($e->status !== 'active' && (int) $value !== (int) $keepId)) $fail('Karyawan yang dipilih tidak ditemukan atau sudah tidak aktif.');
        }]], [
            'employee_id.required' => 'Pilih nama karyawan dari daftar: ketik nama, lalu pilih dari saran yang muncul.',
            'employee_id.integer' => 'Pilih nama karyawan dari daftar: ketik nama, lalu pilih dari saran yang muncul.',
        ]);

        return $request->filled('employee_id') ? Employee::with('department')->find($request->employee_id) : null;
    }

    /**
     * Data pemohon yang tersimpan pada request: dari database karyawan (nama, NIK, department, jabatan). Hanya bagian yang BELUM ada
     * di database (mis. NIK kosong) yang diambil dari isian formulir. Isian browser tidak dipercaya untuk data yang sudah ada di database.
     */
    private function requesterFrom(Employee $e, Request $request): array
    {
        return [
            'employee_id' => $e->id,
            'requester_name' => $e->name,
            'nik' => filled($e->nik) ? $e->nik : ($request->filled('employee_nik') ? $request->employee_nik : '-'),
            'department' => $e->department?->name ?: ($request->filled('department') ? $request->department : '-'),
            'position' => filled($e->position) ? $e->position : ($request->filled('position') ? $request->position : null),
        ];
    }

    /** Aturan validasi untuk data pemohon yang diambil dari database: hanya yang kosong di database yang wajib diisi di formulir. */
    private function employeeBackedRules(Employee $e, bool $nikRequired): array
    {
        return [
            'employee_name' => ['nullable', 'string', 'max:255'],
            'employee_nik' => (filled($e->nik) || ! $nikRequired ? 'nullable' : 'required') . '|regex:/^[0-9]*$/|max:50',
            // department dari formulir hanya dipakai bila karyawan belum punya department di database; saat itu harus dari daftar
            'department' => filled($e->department_id) ? ['nullable', 'string', 'max:100'] : ['nullable', 'string', 'max:100', Rule::in(Department::names())],
            'position' => (filled($e->position) ? 'nullable' : 'required') . '|string|max:100',
        ];
    }

    /** Barang ITR yang dipilih dari saran database (items[].ref = accessory|consumable|component:id) menjadi tautan barang pada request. */
    private function linkSuggestedItems(ITRequest $item): void
    {
        foreach ((array) $item->items as $row) {
            if (! preg_match('/^(accessory|consumable|component):(\d+)$/', (string) ($row['ref'] ?? ''), $m)) continue;
            [$kind, $id] = [$m[1], (int) $m[2]];
            if (! RequestItemLink::CLASSES[$kind]::whereKey($id)->exists()) continue;
            $qty = max(1, (float) ($row['qty'] ?? 1));
            $existing = $item->links()->where('kind', $kind)->where('item_id', $id)->first();
            if ($existing) { $existing->update(['qty' => (float) $existing->qty + $qty]); continue; }     // barang sama dua kali: digabung
            $item->links()->create(['kind' => $kind, 'item_id' => $id, 'qty' => $qty, 'created_by' => auth()->id()]);
        }
    }

    // 3. Simpan Request Baru
   public function store(Request $request)
    {
        // Role 'user' hanya boleh membuat ITR & BAK, tidak SURAT_JALAN/TT (kartu dikunci di UI, ini proteksi server-side)
        if (auth()->check() && auth()->user()->role === 'user' && in_array($request->type, ['SURAT_JALAN'], true)) {
            abort(403, 'Role Anda tidak memiliki akses untuk membuat request tipe ini.');
        }

        // Pemohon dipilih dari database karyawan; nama, NIK, department, dan jabatan diambil dari sana
        $employee = $this->resolveEmployee($request, true);

        $validated = $request->validate([
            'type'           => 'nullable|in:ITR,BAK,SURAT_JALAN,TICKET_SISTEM,WO,PEMINJAMAN_ASET',
            'title'          => (in_array($request->type, ['TT', 'WO'], true) ? 'nullable' : 'required') . '|string|max:255',
            'category'       => ($request->type === 'TICKET_SISTEM' ? ['required', Rule::in(self::TICKET_CATEGORIES)] : 'nullable'),
            // NIK wajib angka saja; wajib diisi untuk semua tipe kecuali Surat Jalan, tetapi hanya bila NIK-nya belum ada di database karyawan
            ...$this->employeeBackedRules($employee, $request->type !== 'SURAT_JALAN'),
            'recipient_name' => ($request->type === 'SURAT_JALAN' ? 'required' : 'nullable') . '|string|max:255',
            'recipient_phone' => 'nullable|string|max:30',
            'qty'            => 'nullable|integer|min:1',
            // Tanggal pinjam & rencana kembali - wajib khusus PEMINJAMAN_ASET, dipakai untuk ambang 7 hari
            'loan_start_date' => ($request->type === 'PEMINJAMAN_ASET' ? 'required' : 'nullable') . '|date',
            'loan_end_date'  => ($request->type === 'PEMINJAMAN_ASET' ? 'required' : 'nullable') . '|date|after_or_equal:loan_start_date',
            'items'          => 'nullable|array|max:5',
            'items.*.name'   => 'required_with:items|string|max:255',
            'items.*.qty'    => 'nullable|integer|min:1',
            'items.*.condition' => 'nullable|in:Baru,Bekas',
            'items.*.serial_number' => 'nullable|string|max:255',
            'items.*.spesifikasi' => 'nullable|string|max:255',
            'items.*.lokasi' => 'nullable|string|max:255',
            'items.*.keterangan' => 'nullable|string|max:500',
            'items.*.ref'    => ['nullable', 'regex:/^(accessory|consumable|component):[0-9]+$/'],
            'description'    => 'required|string',
            'admin_notes'    => 'nullable|string|max:1000',
            // Wajib upload foto khusus tipe Surat Jalan & BAK (Ticket Sistem & Peminjaman Aset: opsional)
            'attachment'     => (in_array($request->type, ['SURAT_JALAN', 'BAK'], true) ? 'required' : 'nullable') . '|image|mimes:jpeg,png,jpg,gif|max:5120',
        ], [
            'attachment.required' => 'Foto wajib diupload sebelum submit.',
            'employee_nik.regex' => 'NIK hanya boleh berisi angka (tanpa huruf, spasi, atau tanda baca).',
            'employee_nik.required' => 'NIK karyawan ini belum ada di database. Isi NIK (angka saja).',
            'position.required' => 'Jabatan karyawan ini belum ada di database. Isi jabatan.',
            'department.in' => 'Department harus dipilih dari daftar.',
            'loan_end_date.after_or_equal' => 'Tanggal rencana kembali tidak boleh sebelum tanggal pinjam.',
        ]);

        if ($request->hasFile('attachment')) {
            $validated['attachment'] = $request->file('attachment')->store('attachments', 'public');
        }

        // TT & WO tidak punya field judul tersendiri, judul diambil dari daftar nama barang/pekerjaan
        $title = $request->title;
        if (!$title && in_array($request->type, ['TT', 'WO'], true)) {
            $default = $request->type === 'WO' ? 'Work Order' : 'Serah Terima Barang';
            $title = $request->filled('items')
                ? collect($request->items)->pluck('name')->filter()->implode(', ')
                : $default;
        }

        // ITR/TICKET_SISTEM/WO/BAK/PEMINJAMAN_ASET baru dapat nomor tiket setelah di-approve tim IT
        // (lihat updateStatus()). SURAT_JALAN & TT tidak melalui approval, nomor langsung diberikan.
        $needsApprovalFirst = in_array($request->type, ['ITR', 'TICKET_SISTEM', 'WO', 'BAK', 'PEMINJAMAN_ASET'], true);

        // Mapping agar sesuai dengan nama kolom database (requester_name & nik)
        $dataToSave = [
            'type'           => $request->type,
            'sequence_number' => $needsApprovalFirst ? null : $this->nextSequenceNumber($request->type, now()),
            'numbered_at'    => $needsApprovalFirst ? null : now(),
            'user_id'        => auth()->id(),
            ...$this->requesterFrom($employee, $request),                    // employee_id, requester_name, nik, department, position (dari database)
            'recipient_name' => $request->recipient_name,
            'recipient_phone' => $request->recipient_phone,
            'title'          => $title,
            'category'       => $request->category,
            'loan_start_date' => $request->loan_start_date,
            'loan_end_date'  => $request->loan_end_date,
            'qty'            => $request->qty ?? 1,
            'items'          => $request->filled('items') ? array_values($request->items) : null,
            'description'    => $request->description,
            'admin_notes'    => $request->admin_notes,
            'status'         => 'Pending',
            'attachment'     => $validated['attachment'] ?? null,
        ];

       // Simpan ke database
        $item = ITRequest::create($dataToSave);

        // ITR: barang yang dipilih dari saran database (Accessories / Consumable / Komponen) langsung tertaut ke request,
        // sehingga tim IT melihatnya sudah terpilih di halaman Proses Request.
        if ($item->type === 'ITR') $this->linkSuggestedItems($item);

        // Kabari semua tim IT (admin/super_admin) via push notification browser
        User::requisitionAdmins()->get()
            ->each(fn ($it) => $it->notify(new NewRequestSubmitted($item)));
        // + sekali ke grup Telegram bersama (bukan per-admin, supaya grup tidak kebanjiran duplikat)
        TelegramNotifier::notifyNewRequest($item);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Request berhasil dibuat!']);
        }

        // Kembali ke halaman form dengan pesan sukses
        return back()->with('success', 'Request berhasil dibuat!');
    }

    // Nomor urut permanen per tipe+bulan: diambil dari nomor tertinggi yang PERNAH terbit
    // (bukan dihitung dari baris yang masih ada), supaya menghapus satu request tidak
    // menggeser nomor request lain, dan nomor yang sudah dihapus tidak dipakai ulang.
    //
    // $referenceDate menentukan bulan yang dipakai untuk menghitung nomor urut — HARUS tanggal
    // dibuatnya request (created_at), bukan waktu sekarang, karena kode AKP-XXX{yymm}### di
    // ticket_number selalu memakai bulan created_at. Untuk ITR/TICKET_SISTEM/WO/BAK nomor baru
    // dikunci saat di-approve (bisa beda bulan dari created_at kalau approvalnya telat), jadi kalau
    // dihitung pakai now() nomornya bisa salah bulan / tidak nyambung dengan tiket lain di bulan itu.
    private function nextSequenceNumber(string $type, $referenceDate): int
    {
        // Bulan sebuah nomor = COALESCE(numbered_at, created_at): nomor baru ikut waktu approval,
        // nomor lama (numbered_at NULL) tetap di bulan created_at-nya.
        $maxSequence = ITRequest::where('type', $type)
            ->whereRaw('COALESCE(numbered_at, created_at) between ? and ?', [
                $referenceDate->copy()->startOfMonth()->format('Y-m-d H:i:s'),
                $referenceDate->copy()->endOfMonth()->format('Y-m-d H:i:s'),
            ])
            ->max('sequence_number');

        return ($maxSequence ?? 0) + 1;
    }

    // 3b. Edit isi form ITR/BAK/WO/TICKET_SISTEM/PEMINJAMAN_ASET — dipakai tim IT (perbaiki sebelum
    // diproses, atau melengkapi Spesifikasi/Serial Number aset yang sengaja tidak diisi user) maupun
    // pemilik request sendiri (perbaiki request yang sudah Rejected lalu dikirim ulang)
    public function update(Request $request, $id)
    {
        $item = ITRequest::findOrFail($id);

        if (! in_array($item->type, ['ITR', 'BAK', 'WO', 'TICKET_SISTEM', 'PEMINJAMAN_ASET'], true)) {
            abort(403, 'Edit form hanya tersedia untuk request tipe ITR, BAK, WO, Ticket Sistem, dan Peminjaman Aset.');
        }

        // RBAC: tim IT bisa edit kapan saja; pemilik request hanya boleh edit miliknya sendiri
        // dan hanya kalau statusnya Rejected (untuk diperbaiki lalu dikirim ulang)
        if (! auth()->user()->canManageRequests()) {
            if ($item->user_id !== auth()->id()) {
                abort(403, 'Anda tidak memiliki akses untuk mengedit request ini.');
            }

            if ($item->status !== 'Rejected') {
                abort(403, 'Anda hanya bisa mengedit request yang sudah ditolak.');
            }
        }

        // Karyawan boleh diganti dengan memilih ulang dari database; bila tidak diganti, data pemohon yang tersimpan tetap
        // (request adalah catatan pada saat dibuat, bukan dihitung ulang dari data karyawan terbaru).
        $employee = $this->resolveEmployee($request, false, $item->employee_id);
        $relink = $employee !== null && (int) $employee->id !== (int) $item->employee_id;
        $requesterRules = $relink ? $this->employeeBackedRules($employee, true) : [
            // nama yang tertaut ke database tidak diuji aturan Title Case (nama dari database dianggap benar)
            'employee_name'  => $item->employee_id ? ['required', 'string', 'max:255'] : ['required', 'string', 'max:255', 'regex:' . self::NAME_TITLE_CASE_REGEX],
            'employee_nik'   => 'required|regex:/^[0-9]*$/|max:50',
            'department'     => 'nullable|string|max:100',
            'position'       => 'required|string|max:100',
        ];

        $validated = $request->validate([
            ...$requesterRules,
            'title'          => ($item->type === 'WO' ? 'nullable' : 'required') . '|string|max:255',
            'category'       => ($item->type === 'TICKET_SISTEM' ? ['required', Rule::in(self::TICKET_CATEGORIES)] : 'nullable'),
            'loan_start_date' => ($item->type === 'PEMINJAMAN_ASET' ? 'required' : 'nullable') . '|date',
            'loan_end_date'  => ($item->type === 'PEMINJAMAN_ASET' ? 'required' : 'nullable') . '|date|after_or_equal:loan_start_date',
            'items'          => 'nullable|array|max:5',
            'items.*.name'   => 'required_with:items|string|max:255',
            'items.*.qty'    => 'nullable|integer|min:1',
            'items.*.condition' => 'nullable|in:Baru,Bekas',
            'items.*.spesifikasi' => 'nullable|string|max:255',
            'items.*.serial_number' => 'nullable|string|max:255',
            'items.*.lokasi' => 'nullable|string|max:255',
            'items.*.keterangan' => 'nullable|string|max:500',
            'description'    => 'required|string',
            'admin_notes'    => ($item->type === 'TICKET_SISTEM' ? 'required' : 'nullable') . '|string|max:1000',
            'attachment'     => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
            // Analisa & Rekomendasi (khusus BAK) digabung di form edit ini supaya tim IT bisa
            // lihat Kronologis sambil mengisi analisa — tapi tetap cuma boleh diisi tim IT,
            // bukan pemilik request (dijaga di bawah, bukan lewat validasi ini).
            'analisa'        => 'nullable|string|max:2000',
            'rekomendasi'    => 'nullable|string|max:2000',
        ], [
            'employee_name.regex' => 'Nama Karyawan harus diawali huruf besar tiap kata, contoh: Dendi Ardiansyah.',
            'employee_nik.required' => 'NIK karyawan ini belum ada di database. Isi NIK (angka saja).',
            'position.required' => 'Jabatan karyawan ini belum ada di database. Isi jabatan.',
            'department.in' => 'Department harus dipilih dari daftar.',
            'loan_end_date.after_or_equal' => 'Tanggal rencana kembali tidak boleh sebelum tanggal pinjam.',
        ]);

        $title = $validated['title'] ?? null;
        if (! $title && $item->type === 'WO') {
            $title = $request->filled('items')
                ? collect($request->items)->pluck('name')->filter()->implode(', ')
                : 'Work Order';
        }

        $dataToUpdate = [
            'requester_name' => $validated['employee_name'] ?? $item->requester_name,
            'nik'            => $validated['employee_nik'] ?? '-',
            'department'     => $validated['department'] ?? '-',
            'position'       => $validated['position'] ?? null,
            'title'          => $title,
            'category'       => $validated['category'] ?? null,
            'loan_start_date' => $validated['loan_start_date'] ?? null,
            'loan_end_date'  => $validated['loan_end_date'] ?? null,
            'items'          => $request->filled('items') ? array_values($request->items) : null,
            'description'    => $validated['description'],
            'admin_notes'    => $validated['admin_notes'] ?? null,
        ];

        // karyawan diganti: data pemohon diambil dari database karyawan yang baru dipilih
        if ($relink) $dataToUpdate = array_merge($dataToUpdate, $this->requesterFrom($employee, $request));

        // Tanggal pinjam/kembali berubah = jadwal reminder lama sudah tidak relevan, reset supaya
        // siklus H-30 menit / jatuh tempo / tiap 6 jam dihitung ulang dari tanggal yang baru.
        if ($item->type === 'PEMINJAMAN_ASET') {
            $dataToUpdate['pre_due_reminder_sent_at'] = null;
            $dataToUpdate['due_reminder_sent_at'] = null;
            $dataToUpdate['last_overdue_reminder_at'] = null;
        }

        // Cuma tim IT yang boleh mengisi Analisa & Rekomendasi — pemilik request (saat resubmit
        // request Rejected miliknya sendiri) tidak boleh menyentuh field ini sama sekali.
        if (auth()->user()->canManageRequests()) {
            $dataToUpdate['analisa'] = $validated['analisa'] ?? null;
            $dataToUpdate['rekomendasi'] = $validated['rekomendasi'] ?? null;
        }

        // Request yang sebelumnya Rejected otomatis dikirim ulang sebagai Pending setelah diperbaiki -
        // TAPI cuma kalau yang mengedit adalah PEMILIK request itu sendiri. Kalau Tim IT yang mengedit
        // request Rejected milik orang lain (mis. lewat Edit di List All Form untuk melengkapi data),
        // perubahan tersimpan tapi statusnya TETAP Rejected - Tim IT tidak boleh mengirim ulang
        // request atas nama user, resubmit harus tindakan sadar dari pemilik request sendiri.
        // approved_by/approved_at ikut direset supaya siklus approval berikutnya (termasuk durasi
        // pengerjaan) dicatat dari nol, bukan menyisakan data dari penolakan sebelumnya.
        $isResubmission = $item->status === 'Rejected' && $item->user_id === auth()->id();
        if ($isResubmission) {
            $dataToUpdate['status'] = 'Pending';
            $dataToUpdate['approved_by'] = null;
            $dataToUpdate['approved_at'] = null;
        }

        if ($request->hasFile('attachment')) {
            if ($item->attachment) {
                Storage::disk('public')->delete($item->attachment);
            }
            $dataToUpdate['attachment'] = $request->file('attachment')->store('attachments', 'public');
        }

        $item->update($dataToUpdate);

        // Kirim ulang setelah revisi = sama seperti request baru masuk buat tim IT — kabari lagi
        if ($isResubmission) {
            User::requisitionAdmins()->get()
                ->each(fn ($it) => $it->notify(new NewRequestSubmitted($item)));
            TelegramNotifier::notifyNewRequest($item);
        }

        // request yang belum pernah disetujui belum punya nomor tiket: pakai judul / jenisnya supaya pesan tidak menulis Request ""
        $ref = $item->ticket_number ?: ($item->title ?: ($item->type ?: 'Request'));
        $message = 'Request "' . $ref . '" berhasil diperbarui';
        $message .= $item->status === 'Pending' ? ' dan dikirim ulang untuk approval.' : '.';

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return back()->with('success', $message);
    }


    // 4. Update Status Request (Setujui / Tolak / In Progress / Completed)
    public function updateStatus(Request $request, $id)
    {
        // TAMBAHKAN Approved DAN Rejected DI SINI
        $request->validate([
            'status' => 'required|in:Pending,Approved,Rejected,In Progress,Completed',
        ]);

        $item = ITRequest::findOrFail($id);

        // Status Completed bersifat final — begitu sudah Completed, tidak bisa diubah ke status lain lagi.
        if ($item->status === 'Completed') {
            abort(403, 'Status "Completed" sudah final dan tidak bisa diubah kembali.');
        }

        // BAK baru boleh disetujui (pertama kali) setelah tim IT mengisi Analisa DAN Rekomendasi lewat Edit.
        // approved_at === null = belum pernah disetujui; BAK lama yang sudah pernah disetujui tidak ikut terblokir
        // saat statusnya dilanjutkan (mis. In Progress -> Completed). Menolak (Rejected) tetap boleh tanpa isian itu.
        if ($item->type === 'BAK' && ! in_array($request->status, ['Pending', 'Rejected'], true) && $item->approved_at === null
            && (trim((string) $item->analisa) === '' || trim((string) $item->rekomendasi) === '')) {
            $message = 'BAK belum bisa disetujui: isi Analisa dan Rekomendasi dulu lewat Edit, lalu simpan.';

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $message], 422);
            }

            return back()->with('error', $message);
        }

        // Nomor tiket dikunci begitu request PERTAMA KALI diproses maju (bukan Pending/Rejected).
        // Nomor urut & bulan kodenya mengikuti waktu approval (bukan waktu pengajuan): yang baru
        // disetujui selalu dapat nomor paling besar di bulan approval-nya.
        if ($item->sequence_number === null && ! in_array($request->status, ['Pending', 'Rejected'], true)) {
            $item->numbered_at = now();
            $item->sequence_number = $this->nextSequenceNumber($item->type, $item->numbered_at);
        }

        $item->status = $request->status;
        // Hanya catat approved_by/approved_at sekali (siapa & kapan PERTAMA memproses dari Pending) —
        // perubahan status berikutnya oleh admin lain (mis. In Progress -> Completed) tidak boleh
        // menimpa keduanya. approved_at inilah titik mulai durasi pengerjaan dihitung.
        if ($item->approved_by === null) {
            $item->approved_by = auth()->id();
        }
        if ($item->approved_at === null && $request->status !== 'Rejected') {
            $item->approved_at = now();
        }
        // completed_at = titik akhir durasi pengerjaan, diisi begitu status masuk Completed.
        if ($request->status === 'Completed' && $item->completed_at === null) {
            $item->completed_at = now();
        }
        $item->save();

        // Kabari pemilik request (kalau akunnya ada & sudah aktifkan notifikasi) via push notification browser
        if ($item->user) {
            $item->user->notify(new RequestStatusUpdated($item));
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Status berhasil diperbarui menjadi: ' . $request->status]);
        }

        return back()->with('success', 'Status berhasil diperbarui menjadi: ' . $request->status);
    }

    // 5b. Update Keterangan — catatan bebas dari tim IT untuk user (misal alasan revisi/reject),
    // berlaku untuk semua tipe yang tampil di History Request Terbaru (ITR/TICKET_SISTEM/BAK/WO)
    public function updateNote(Request $request, $id)
    {
        $validated = $request->validate([
            'update_note' => 'nullable|string|max:2000',
        ]);

        $item = ITRequest::findOrFail($id);
        $item->update_note = $validated['update_note'] ?? null;
        $item->save();

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Keterangan berhasil disimpan.']);
        }

        return back()->with('success', 'Keterangan berhasil disimpan.');
    }

    // Upload dokumen pendukung dari List All Form (semua tipe kecuali Ticket Sistem), disimpan di disk privat
    public function uploadDocument(Request $request, $id)
    {
        $item = ITRequest::findOrFail($id);

        if ($item->type === 'TICKET_SISTEM') {
            return back()->with('error', 'Ticket Sistem tidak memiliki upload dokumen.');
        }

        $validated = $request->validate([
            'document' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png|max:5120',
        ], [
            'document.required' => 'Pilih file dokumen terlebih dahulu.',
            'document.mimes'    => 'Format dokumen harus PDF, Word, Excel, atau gambar (JPG/PNG).',
            'document.max'      => 'Ukuran dokumen maksimal 5 MB.',
        ]);

        $file = $validated['document'];
        $oldPath = $item->document_path;

        $item->document_path = $file->store('request-documents', 'local');
        $item->document_name = mb_substr($file->getClientOriginalName(), 0, 255);
        $item->save();

        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return back()->with('success', 'Dokumen berhasil diupload.');
    }

    // Lihat/unduh dokumen yang sudah diupload (khusus tim IT)
    public function viewDocument($id)
    {
        $item = ITRequest::findOrFail($id);

        abort_unless($item->document_path && Storage::disk('local')->exists($item->document_path), 404);

        return Storage::disk('local')->response($item->document_path, $item->document_name);
    }

    // 6. Download / Preview PDF Request
    public function downloadPdf(Request $request, $id)
    {
        $itRequest = ITRequest::findOrFail($id);

        // Ticket Sistem tidak punya PDF sama sekali (bukan cuma disembunyikan di UI)
        if ($itRequest->type === 'TICKET_SISTEM') {
            abort(404);
        }

        // Peminjaman Aset cuma punya PDF (dengan kolom TTD User & IT Team) kalau durasi pinjam
        // 7 hari atau lebih — di bawah itu cukup tercatat di list, tanpa dokumen resmi.
        if ($itRequest->type === 'PEMINJAMAN_ASET' && (int) $itRequest->loan_duration_days < 7) {
            abort(404);
        }

        // Tim IT (admin/super_admin) selalu bisa akses. Role 'user' hanya boleh akses
        // PDF request miliknya sendiri, dan baru setelah disetujui/diproses (Pending & Rejected
        // belum punya nomor tiket, jadi belum ada PDF resmi untuk diunduh).
        if (! auth()->user()->canManageRequests()) {
            if ($itRequest->user_id !== auth()->id()) {
                abort(403, 'Anda tidak memiliki akses ke PDF request ini.');
            }

            if (in_array($itRequest->status, ['Pending', 'Rejected'], true)) {
                abort(403, 'PDF baru bisa diunduh setelah request disetujui oleh tim IT.');
            }
        }

        $template = match ($itRequest->type) {
            'BAK' => 'requisition.pdf_template_bak',
            'TT' => 'requisition.pdf_template_tt',
            'SURAT_JALAN' => 'requisition.pdf_template_sj',
            'WO' => 'requisition.pdf_template_wo',
            'PEMINJAMAN_ASET' => 'requisition.pdf_template_peminjaman',
            default => 'requisition.pdf_template',
        };

        $pdf = Pdf::loadView($template, compact('itRequest'));
        $filename = 'IT-Request-' . $itRequest->id . '.pdf';

        if ($request->query('preview')) {
            return $pdf->stream($filename);
        }

        return $pdf->download($filename);
    }

    // 7. Hapus satu request - super admin bebas kapan saja, selain itu (termasuk admin biasa) hanya
    // boleh untuk request miliknya sendiri yang sudah Rejected (semua tipe form)
    public function destroy($id)
    {
        $item = ITRequest::findOrFail($id);

        $isOwnRejectedRequest = $item->user_id === auth()->id() && $item->status === 'Rejected';

        if (! $isOwnRejectedRequest) {
            abort_unless(auth()->user()->isSuperAdmin(), 403);
        }

        if ($item->document_path) {
            Storage::disk('local')->delete($item->document_path);
        }
        $item->delete();

        return back()->with('success', 'Request "' . $item->ticket_number . '" berhasil dihapus.');
    }

    // 8. Hapus beberapa request sekaligus (khusus Super Admin - admin biasa tidak lagi bisa hapus massal)
    public function bulkDestroy(Request $request)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:i_t_requests,id',
        ]);

        $documentPaths = ITRequest::whereIn('id', $validated['ids'])->whereNotNull('document_path')->pluck('document_path')->all();
        $count = ITRequest::whereIn('id', $validated['ids'])->delete();
        Storage::disk('local')->delete($documentPaths);

        return back()->with('success', $count . ' request berhasil dihapus.');
    }
}