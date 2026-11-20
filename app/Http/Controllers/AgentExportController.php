<?php

namespace App\Http\Controllers;

use App\Services\AgentAlertReport;
use App\Services\AgentRolloutReport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Ekspor CSV dari halaman SIMA Agent (laptop belum terpasang agent, daftar peringatan). Izin: manage inventory. */
class AgentExportController extends Controller
{
    public function rollout(AgentRolloutReport $report): StreamedResponse
    {
        abort_unless(auth()->user()?->can('manage inventory'), 403);

        $data = $report->build(false);
        $stateText = ['token_only' => 'Token dibuat, belum melapor', 'missing' => 'Belum terpasang'];
        $rows = [['Tag aset', 'Nama', 'Model', 'Status aset', 'Lokasi', 'Pengguna', 'Departemen', 'Section', 'Kondisi agent']];
        foreach ($data['pending_all'] as $r) {
            $a = $r['asset'];
            $rows[] = array_map([AgentAlertReport::class, 'safe'], [
                (string) $a->asset_tag, AgentAlertReport::assetName($a), (string) ($a->model ?? ''), $r['status'], $r['location'],
                $r['user'], $r['department'], $r['section'], $stateText[$r['state']] ?? $r['state'],
            ]);
        }

        return $this->csv($rows, 'laptop-belum-terpasang-agent-' . now()->format('Ymd') . '.csv');
    }

    public function alerts(Request $request, AgentAlertReport $report): StreamedResponse
    {
        abort_unless(auth()->user()?->can('manage inventory'), 403);

        $severity = in_array($request->query('tingkat'), AgentAlertReport::SEVERITIES, true) ? $request->query('tingkat') : null;

        return $this->csv($report->csvRows($severity), 'peringatan-agent-' . ($severity ?? 'semua') . '-' . now()->format('Ymd') . '.csv');
    }

    /** CSV UTF-8 dengan BOM supaya Excel membaca aksara non-Latin dengan benar. */
    private function csv(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
