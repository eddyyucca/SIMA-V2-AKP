<?php

namespace App\Console\Commands;

use App\Services\LabelService;
use Illuminate\Console\Command;

class LabelSample extends Command
{
    protected $signature = 'labels:sample {kind=asset} {--tape=24} {--length=} {--out=label-sample.pdf} {--limit=2}';
    protected $description = 'Buat PDF contoh label untuk pengecekan visual (disimpan di storage/app/private)';

    public function handle(LabelService $svc): int
    {
        $kind = $this->argument('kind');
        $items = LabelService::model($kind)::orderBy('id')->limit((int) $this->option('limit'))->get();
        $pdf = $svc->pdf($kind, $items, (int) $this->option('tape'), (int) ($this->option('length') ?: config('labels.length')));
        $path = storage_path('app/private/' . $this->option('out'));
        file_put_contents($path, $pdf->output());
        $this->info($path);
        return self::SUCCESS;
    }
}
