<?php

namespace App\Http\Controllers;

use App\Models\AssetAssignment;
use App\Models\Disposal;
use App\Reports\ArrayExport;
use App\Reports\ReportCatalog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function download(Request $request, string $key, string $format)
    {
        abort_unless($request->user()->can('view reports'), 403);
        [$title, $head, $rows] = ReportCatalog::build($key, $request->query());
        $file = str($title)->slug() . '-' . now()->format('Ymd');

        if ($format === 'xlsx') {
            return Excel::download(new ArrayExport($head, $rows), $file . '.xlsx');
        }
        return Pdf::loadView('pdf.table', compact('title', 'head', 'rows'))->setPaper('a4', 'landscape')->download($file . '.pdf');
    }

    public function bast(Request $request, AssetAssignment $assignment)
    {
        $u = $request->user();
        $own = $u->employee && in_array($u->employee->id, [$assignment->to_employee_id, $assignment->from_employee_id]);
        abort_unless($u->can('view inventory') || $own, 403);
        abort_unless($assignment->bast_pdf_path && Storage::disk('local')->exists($assignment->bast_pdf_path), 404);
        return Storage::disk('local')->download($assignment->bast_pdf_path, str_replace('/', '-', $assignment->bast_number) . '.pdf');
    }

    /** Lihat BAST langsung di browser (inline), aturan akses sama dengan unduh. */
    public function bastView(Request $request, AssetAssignment $assignment)
    {
        $u = $request->user();
        $own = $u->employee && in_array($u->employee->id, [$assignment->to_employee_id, $assignment->from_employee_id]);
        abort_unless($u->can('view inventory') || $own, 403);
        abort_unless($assignment->bast_pdf_path && Storage::disk('local')->exists($assignment->bast_pdf_path), 404);
        $name = str_replace('/', '-', $assignment->bast_number) . '.pdf';
        return Storage::disk('local')->response($assignment->bast_pdf_path, $name, ['Content-Type' => 'application/pdf'], 'inline');
    }

    /** PDF BAST bertanda tangan yang diunggah. Boleh dilihat Admin/Staff IT, pihak pada BAST terkait, atau pemegang aset saat ini. */
    private function signedFile(Request $request, \App\Models\AssetBastFile $file): string
    {
        $u = $request->user();
        $emp = $u->employee?->id;
        $a = $file->assignment;
        $own = $emp && (in_array($emp, [$a?->to_employee_id, $a?->from_employee_id], true) || $emp === $file->asset?->employee_id);
        abort_unless($u->can('view inventory') || $own, 403);
        abort_unless(Storage::disk('local')->exists($file->path), 404);
        return $file->path;
    }

    public function bastFile(Request $request, \App\Models\AssetBastFile $file)
    {
        $path = $this->signedFile($request, $file);
        return Storage::disk('local')->response($path, $file->downloadName(), ['Content-Type' => 'application/pdf'], 'inline');
    }

    public function bastFileDownload(Request $request, \App\Models\AssetBastFile $file)
    {
        $path = $this->signedFile($request, $file);
        return Storage::disk('local')->download($path, $file->downloadName(), ['Content-Type' => 'application/pdf']);
    }

    /** Berkas kontrak sewa: Admin/Staff IT (izin lihat inventaris). */
    private function contractPath(Request $request, \App\Models\RentalContract $contract): string
    {
        abort_unless($request->user()->can('view inventory'), 403);
        abort_unless(Storage::disk('local')->exists($contract->path), 404);
        return $contract->path;
    }

    public function contractView(Request $request, \App\Models\RentalContract $contract)
    {
        return Storage::disk('local')->response($this->contractPath($request, $contract), $contract->downloadName(), ['Content-Type' => 'application/pdf'], 'inline');
    }

    public function contractDownload(Request $request, \App\Models\RentalContract $contract)
    {
        return Storage::disk('local')->download($this->contractPath($request, $contract), $contract->downloadName(), ['Content-Type' => 'application/pdf']);
    }

    public function disposalView(Request $request, Disposal $disposal)
    {
        abort_unless($request->user()->can('view inventory'), 403);
        abort_unless($disposal->ba_pdf_path && Storage::disk('local')->exists($disposal->ba_pdf_path), 404);
        return Storage::disk('local')->response($disposal->ba_pdf_path, 'BA-Penghapusan-' . $disposal->id . '.pdf', ['Content-Type' => 'application/pdf'], 'inline');
    }

    public function disposal(Request $request, Disposal $disposal)
    {
        abort_unless($request->user()->can('view inventory'), 403);
        abort_unless($disposal->ba_pdf_path && Storage::disk('local')->exists($disposal->ba_pdf_path), 404);
        return Storage::disk('local')->download($disposal->ba_pdf_path, 'BA-Penghapusan-' . $disposal->id . '.pdf');
    }

    /** Hasil cetak daftar aset (PDF sementara, nama acak, hanya yang login dengan izin lihat inventaris). */
    public function printFile(Request $request, string $file)
    {
        abort_unless($request->user()->can('view inventory'), 403);
        abort_unless(preg_match('/^[a-f0-9]{32}$/', $file) === 1 && Storage::disk('local')->exists("prints/{$file}.pdf"), 404);
        return Storage::disk('local')->response("prints/{$file}.pdf", 'daftar-aset.pdf', ['Content-Type' => 'application/pdf'], 'inline');
    }

    public function templateEmployees()
    {
        $csv = "nik,nama,email,jabatan,department,telepon,status\n"
            . "3201001,Budi Santoso,budi@perusahaan.co.id,Staff IT,IT,081234567890,aktif\n"
            . "3201002,Siti Aminah,siti@perusahaan.co.id,Akuntan,Finance,081298765432,aktif\n";
        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="template-import-karyawan.csv"']);
    }

    public function template()
    {
        // kolom spesifikasi mengikuti Pengaturan (kunci kolom, mis. cpu, ram, hdd, vga); boleh dikosongkan
        $keys = array_keys(\App\Models\Asset::specFields());
        $examples = ['cpu' => 'Intel Core i5', 'ram' => '16 GB', 'hdd' => '512 GB SSD', 'vga' => 'Intel Iris Xe'];
        $cell = fn (int $row) => array_map(fn ($k) => $row === 0 ? ($examples[$k] ?? '') : '', $keys);
        $csv = "kategori,merek,model,serial_number,tanggal_beli,harga,garansi_sampai,lokasi,vendor,kepemilikan,no_kontrak,sewa_mulai,sewa_berakhir,biaya_sewa_bulanan" . ($keys ? ',' . implode(',', $keys) : '') . "\n"
            . "Laptop,Lenovo,ThinkPad E14,SN12345,2026-01-15,9500000,2029-01-15,Kantor Pusat,PT Sumber Teknologi,milik,,,," . ($keys ? ',' . implode(',', $cell(0)) : '') . "\n"
            . "Laptop,Dell,Latitude 5440,SN67890,,0,,Kantor Pusat,PT Rental Teknologi,sewa,KTR-2026-01,2026-01-01,2027-12-31,700000" . ($keys ? ',' . implode(',', $cell(1)) : '') . "\n";
        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="template-import-aset.csv"']);
    }
}
