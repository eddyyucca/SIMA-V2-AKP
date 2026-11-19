<?php

namespace App\Services;

/** Pembantu edisi Windows untuk aturan peringatan agent (bagian kecil dari AgentFleetMonitor SIMA; laporan armada penuh diganti Monitor Agent di Filament). */
class AgentFleetMonitor
{
    /** 'Home' | 'Pro' | 'Enterprise' | 'Education' | 'Server' | '-' dari teks seperti "Microsoft Windows 11 Home Single Language". */
    public static function editionOf(string $osVersion): string
    {
        return match (true) {
            $osVersion === '' => '-',
            (bool) preg_match('/\bServer\b/i', $osVersion) => 'Server',
            (bool) preg_match('/\bEnterprise\b/i', $osVersion) => 'Enterprise',
            (bool) preg_match('/\bEducation\b/i', $osVersion) => 'Education',
            (bool) preg_match('/\bPro(fessional)?\b/i', $osVersion) => 'Pro',
            (bool) preg_match('/\bHome\b/i', $osVersion) => 'Home',
            default => '-',
        };
    }
}
