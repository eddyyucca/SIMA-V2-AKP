<?php

namespace App\Http\Controllers;

use App\Services\WeeklyReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PhpOffice\PhpPresentation\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WeeklyReportController extends Controller
{
    // Download laporan mingguan (Ticket Sistem & WO) dalam format PPTX
    public function download(Request $request, WeeklyReportService $service): BinaryFileResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
        ]);

        $start = isset($validated['start_date']) ? Carbon::parse($validated['start_date']) : Carbon::now()->startOfWeek();
        $end = isset($validated['end_date']) ? Carbon::parse($validated['end_date']) : Carbon::now()->endOfWeek();

        $presentation = $service->generate($start, $end);

        $filename = 'Laporan-Mingguan-IT-' . $start->format('Ymd') . '-' . $end->format('Ymd') . '.pptx';
        // berkas sementara di folder temp sistem (bukan storage/app): terhapus setelah dikirim, dan tes tidak meninggalkan sampah di penyimpanan asli
        $tempPath = sys_get_temp_dir() . '/' . uniqid('weekly_report_') . '.pptx';

        $writer = IOFactory::createWriter($presentation, 'PowerPoint2007');
        $writer->save($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }
}
