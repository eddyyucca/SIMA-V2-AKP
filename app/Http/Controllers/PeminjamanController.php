<?php

namespace App\Http\Controllers;

use App\Models\ITRequest;
use Illuminate\Http\Request;

// List "Peminjaman Aset" terpisah dari List All Form / History Request Terbaru (lihat
// RequestController::filteredRequestsQuery & publicHistoryQuery yang sengaja mengecualikan
// tipe ini). Aksi submit/approve/reject/delete/PDF tetap dipakaikan lewat RequestController
// yang sudah generik per-tipe, controller ini cuma untuk menampilkan list-nya.
class PeminjamanController extends Controller
{
    public function index(Request $request)
    {
        $items = $this->query($request)->paginate(25);

        // Sama dengan daftar dept di form request lain (create.blade.php) - dipakai dropdown Edit modal
        $departmentOptions = \App\Models\Department::names();   // satu sumber: tabel departments (IT Asset)

        return view('requisition.peminjaman.index', compact('items', 'departmentOptions'));
    }

    // Endpoint AJAX untuk filter/ganti halaman tanpa reload penuh (sama pola dengan listAllForm())
    public function listJson(Request $request)
    {
        $items = $this->query($request)->paginate(25);

        return response()->json([
            'rows_html' => view('requisition.partials.peminjaman-rows', compact('items'))->render(),
            'pagination_html' => view('requisition.partials.peminjaman-pagination', compact('items'))->render(),
            'total' => $items->total(),
        ]);
    }

    // Tim IT menandai aset sudah dikembalikan fisiknya - menghentikan reminder harian.
    // Terpisah dari status (Approved/In Progress/Completed) karena "sudah kembali" adalah
    // penanda fisik aset, bukan tahap approval.
    public function markReturned(Request $request, $id)
    {
        $item = ITRequest::where('type', 'PEMINJAMAN_ASET')->findOrFail($id);

        $warnings = [];
        if ($item->returned_at === null) {
            // aset yang dipinjamkan lewat request ini otomatis kembali ke status Tersedia
            try {
                $warnings = app(\App\Services\RequestProcessingService::class)->release($item);
            } catch (\InvalidArgumentException $e) {
                $msg = 'Aset belum bisa dikembalikan ke stok: ' . $e->getMessage();
                return $request->expectsJson() ? response()->json(['success' => false, 'message' => $msg], 422) : back()->with('error', $msg);
            }
            $item->returned_at = now();
            $item->save();
            app(\App\Services\RequestLifecycle::class)->returned($item);
        }

        $message = 'Aset ditandai sudah kembali dan statusnya Tersedia.' . ($warnings ? ' Catatan: ' . implode(' ', $warnings) : '');
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return back()->with('success', 'Aset "' . $item->ticket_number . '" ditandai sudah kembali.' . ($warnings ? ' Catatan: ' . implode(' ', $warnings) : ''));
    }

    // Pending yang BELUM PERNAH diproses (sequence_number masih null) sengaja tidak muncul di sini —
    // selama masih menunggu approval pertama kali, tampil di panel "Incoming Requests (Pending)"
    // dashboard seperti tipe lain, baru pindah ke list ini begitu statusnya berubah. Tapi kalau statusnya
    // sudah PERNAH diproses (sequence_number terisi) lalu diubah balik ke Pending lewat dropdown status,
    // tetap tampil di sini — supaya tidak seolah-olah hilang/jadi request baru lagi (lihat juga
    // RequestController::pendingApprovalsQuery).
    private function query(Request $request)
    {
        $query = ITRequest::with(['approvedBy', 'user'])
            ->where('type', 'PEMINJAMAN_ASET')
            ->where(function ($q) {
                $q->where('status', '!=', 'Pending')->orWhereNotNull('sequence_number');
            });

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('requester_name', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }

        return $query->latest();
    }
}
