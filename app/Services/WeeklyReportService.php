<?php

namespace App\Services;

use App\Models\ITRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Shape\Chart\Legend;
use PhpOffice\PhpPresentation\Shape\Chart\Series;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Bar;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Line;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Pie;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpPresentation\Slide\Background\Color as SlideBackground;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Border;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpPresentation\Style\Outline;

class WeeklyReportService
{
    private const BG = 'FFFFFFFF';
    private const CARD_BG = 'FFF8FAFC';
    private const CARD_BORDER = 'FFE2E8F0';
    private const PURPLE = 'FF9333EA';
    private const INDIGO = 'FF6366F1';
    private const CYAN = 'FF0891B2';
    private const BLUE = 'FF3B82F6';
    private const AMBER = 'FFF59E0B';
    private const EMERALD = 'FF10B981';
    private const ROSE = 'FFF43F5E';
    private const TEAL = 'FF14B8A6';
    private const RED = 'FFEF4444';
    private const TEXT_PRIMARY = 'FF0F172A';
    private const TEXT_SECONDARY = 'FF475569';
    private const TEXT_MUTED = 'FF94A3B8';

    private const SLIDE_W = 720;
    private const SLIDE_H = 405;

    public function generate(Carbon $start, Carbon $end): PhpPresentation
    {
        $ts = ITRequest::where('type', 'TICKET_SISTEM')
            ->whereBetween('created_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->orderBy('created_at')
            ->get();

        $wo = ITRequest::where('type', 'WO')
            ->whereBetween('created_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->orderBy('created_at')
            ->get();

        $pp = new PhpPresentation();
        $pp->removeSlideByIndex(0);
        $pp->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);

        $this->addTitleSlide($pp, $start, $end);
        $this->addSummarySlide($pp, $ts, $wo);
        $this->addTrendChartSlide($pp, $start, $end, $ts, $wo);
        $this->addStatusChartSlide($pp, 'STATUS TICKET SISTEM', $ts, self::AMBER);
        $this->addStatusChartSlide($pp, 'STATUS WORK ORDER', $wo, self::EMERALD);
        $this->addDetailTableSlides($pp, 'DETAIL TICKET SISTEM', $ts, self::AMBER);
        $this->addDetailTableSlides($pp, 'DETAIL WORK ORDER', $wo, self::EMERALD);
        $this->addClosingSlide($pp, $start, $end);

        return $pp;
    }

    private function newSlide(PhpPresentation $pp): Slide
    {
        $slide = $pp->createSlide();
        $bg = new SlideBackground();
        $bg->setColor(new Color(self::BG));
        $slide->setBackground($bg);

        return $slide;
    }

    private function addTitleSlide(PhpPresentation $pp, Carbon $start, Carbon $end): void
    {
        $slide = $this->newSlide($pp);

        if (file_exists(public_path('images/logo-akp.png'))) {
            $logo = $slide->createDrawingShape();
            $logo->setPath(public_path('images/logo-akp.png'));
            $logo->setHeight(40)->setOffsetX(50)->setOffsetY(40);
        }

        $company = $slide->createRichTextShape()->setHeight(20)->setWidth(500)->setOffsetX(50)->setOffsetY(90);
        $company->createTextRun('PT ADHI KARTIKO PRATAMA TBK')
            ->getFont()->setBold(true)->setSize(11)->setColor(new Color(self::TEXT_SECONDARY));

        $badge = $slide->createRichTextShape()->setHeight(24)->setWidth(220)->setOffsetX(50)->setOffsetY(140);
        $badge->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('22A855F7'));
        $badgeRun = $badge->createTextRun('IT SUPPORT WEEKLY REPORT');
        $badgeRun->getFont()->setBold(true)->setSize(10)->setColor(new Color(self::PURPLE));
        $badge->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $badge->getActiveParagraph()->setAlignment(clone $badge->getActiveParagraph()->getAlignment());

        $title = $slide->createRichTextShape()->setHeight(90)->setWidth(620)->setOffsetX(50)->setOffsetY(175);
        $title->createTextRun('Laporan Mingguan')->getFont()->setBold(true)->setSize(40)->setColor(new Color(self::TEXT_PRIMARY));

        $title2 = $slide->createRichTextShape()->setHeight(50)->setWidth(620)->setOffsetX(50)->setOffsetY(240);
        $title2->createTextRun('Ticket Sistem & Work Order')->getFont()->setBold(true)->setSize(24)->setColor(new Color(self::CYAN));

        $period = $slide->createRichTextShape()->setHeight(30)->setWidth(620)->setOffsetX(50)->setOffsetY(300);
        $period->createTextRun('Periode: ' . $start->locale('id')->translatedFormat('d F Y') . ' — ' . $end->locale('id')->translatedFormat('d F Y'))
            ->getFont()->setSize(15)->setColor(new Color(self::TEXT_SECONDARY));

        $footer = $slide->createRichTextShape()->setHeight(20)->setWidth(620)->setOffsetX(50)->setOffsetY(360);
        $footer->createTextRun('Digenerate otomatis pada ' . Carbon::now()->locale('id')->translatedFormat('d F Y, H:i'))
            ->getFont()->setSize(9)->setItalic(true)->setColor(new Color(self::TEXT_MUTED));
    }

    private function addSummarySlide(PhpPresentation $pp, Collection $ts, Collection $wo): void
    {
        $slide = $this->newSlide($pp);
        $this->addSlideHeader($slide, 'RINGKASAN MINGGU INI');

        $cards = [
            ['Total Ticket Sistem', (string) $ts->count(), self::AMBER],
            ['Total Work Order', (string) $wo->count(), self::EMERALD],
            ['Selesai (Completed)', (string) ($ts->where('status', 'Completed')->count() + $wo->where('status', 'Completed')->count()), self::TEAL],
            ['Masih Pending', (string) ($ts->where('status', 'Pending')->count() + $wo->where('status', 'Pending')->count()), self::ROSE],
        ];

        $cardWidth = 145;
        $gap = 15;
        $startX = (int) round((self::SLIDE_W - (count($cards) * $cardWidth + (count($cards) - 1) * $gap)) / 2);

        foreach ($cards as $i => [$label, $value, $color]) {
            $x = $startX + $i * ($cardWidth + $gap);

            $card = $slide->createRichTextShape()->setHeight(110)->setWidth($cardWidth)->setOffsetX($x)->setOffsetY(120);
            $card->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color(self::CARD_BG));
            $card->getBorder()->setLineStyle(Border::LINE_SINGLE)->setLineWidth(1)->setColor(new Color(self::CARD_BORDER));

            $numShape = $slide->createRichTextShape()->setHeight(50)->setWidth($cardWidth - 20)->setOffsetX($x + 10)->setOffsetY(150);
            $numShape->createTextRun($value)->getFont()->setBold(true)->setSize(32)->setColor(new Color($color));

            $labelShape = $slide->createRichTextShape()->setHeight(30)->setWidth($cardWidth - 20)->setOffsetX($x + 10)->setOffsetY(205);
            $labelShape->createTextRun($label)->getFont()->setSize(10)->setColor(new Color(self::TEXT_SECONDARY));
        }

        // Breakdown per department (gabungan TS+WO)
        $allItems = $ts->concat($wo);
        $byDept = $allItems->groupBy(fn ($r) => $r->department ?: 'Tidak diisi')->map->count()->sortDesc()->take(5);

        $deptShape = $slide->createRichTextShape()->setHeight(20)->setWidth(400)->setOffsetX(50)->setOffsetY(260);
        $deptShape->createTextRun('Top Department Pengaju (minggu ini)')->getFont()->setBold(true)->setSize(12)->setColor(new Color(self::TEXT_PRIMARY));

        $y = 290;
        if ($byDept->isEmpty()) {
            $emptyShape = $slide->createRichTextShape()->setHeight(20)->setWidth(600)->setOffsetX(50)->setOffsetY($y);
            $emptyShape->createTextRun('Tidak ada data pada periode ini.')->getFont()->setItalic(true)->setSize(11)->setColor(new Color(self::TEXT_SECONDARY));
        } else {
            foreach ($byDept as $dept => $count) {
                $rowShape = $slide->createRichTextShape()->setHeight(18)->setWidth(600)->setOffsetX(50)->setOffsetY($y);
                $rowShape->createTextRun($dept . '  —  ' . $count . ' request')->getFont()->setSize(11)->setColor(new Color(self::TEXT_SECONDARY));
                $y += 20;
            }
        }
    }

    private function addSlideHeader(Slide $slide, string $text): void
    {
        $header = $slide->createRichTextShape()->setHeight(40)->setWidth(620)->setOffsetX(50)->setOffsetY(50);
        $header->createTextRun($text)->getFont()->setBold(true)->setSize(22)->setColor(new Color(self::TEXT_PRIMARY));

        $rule = $slide->createRichTextShape()->setHeight(2)->setWidth(620)->setOffsetX(50)->setOffsetY(92);
        $rule->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color(self::CARD_BORDER));
    }

    private function statusCounts(Collection $items): array
    {
        $order = ['Pending', 'In Progress', 'Approved', 'Completed', 'Rejected'];
        $counts = [];
        foreach ($order as $status) {
            $c = $items->where('status', $status)->count();
            if ($c > 0) {
                $counts[$status] = $c;
            }
        }

        return $counts;
    }

    private function addStatusChartSlide(PhpPresentation $pp, string $title, Collection $items, string $accentColor): void
    {
        $slide = $this->newSlide($pp);
        $this->addSlideHeader($slide, $title . ' (' . $items->count() . ' REQUEST)');

        $counts = $this->statusCounts($items);

        if (empty($counts)) {
            $emptyShape = $slide->createRichTextShape()->setHeight(30)->setWidth(600)->setOffsetX(50)->setOffsetY(180);
            $emptyShape->createTextRun('Tidak ada data pada periode ini.')->getFont()->setItalic(true)->setSize(14)->setColor(new Color(self::TEXT_SECONDARY));

            return;
        }

        $chartShape = $slide->createChartShape();
        $chartShape->setHeight(280)->setWidth(500)->setOffsetX(110)->setOffsetY(110);

        $series = new Series('Status', $counts);
        $series->setShowPercentage(true);
        $series->setShowValue(false);
        $series->setShowSeriesName(false);
        $series->setShowCategoryName(false);

        $statusColorMap = [
            'Pending' => self::AMBER,
            'In Progress' => self::BLUE,
            'Approved' => self::TEAL,
            'Completed' => self::EMERALD,
            'Rejected' => self::ROSE,
        ];
        $idx = 0;
        foreach (array_keys($counts) as $status) {
            $series->getDataPointFill($idx)->setFillType(Fill::FILL_SOLID)->setStartColor(new Color($statusColorMap[$status] ?? $accentColor));
            $idx++;
        }

        $type = new Pie();
        $type->addSeries($series);
        $chartShape->getPlotArea()->setType($type);

        $chartShape->getLegend()->setPosition(Legend::POSITION_RIGHT);
        $chartShape->getLegend()->getFont()->setColor(new Color(self::TEXT_SECONDARY))->setSize(11);
        $chartShape->getView3D()->setRotationX(30);
    }

    private function addTrendChartSlide(PhpPresentation $pp, Carbon $start, Carbon $end, Collection $ts, Collection $wo): void
    {
        $slide = $this->newSlide($pp);
        $this->addSlideHeader($slide, 'TICKETS CREATED VS TICKETS SOLVED');

        $combined = $ts->concat($wo);

        $days = [];
        $cursor = $start->copy()->startOfDay();
        while ($cursor->lte($end)) {
            $days[] = $cursor->copy();
            $cursor->addDay();
        }

        $created = [];
        $solved = [];
        foreach ($days as $day) {
            $label = $day->locale('id')->translatedFormat('d M');
            $created[$label] = $combined->filter(fn ($r) => $r->created_at->isSameDay($day))->count();
            $solved[$label] = $combined->filter(fn ($r) => $r->status === 'Completed' && $r->updated_at->isSameDay($day))->count();
        }

        if (array_sum($created) === 0 && array_sum($solved) === 0) {
            $emptyShape = $slide->createRichTextShape()->setHeight(30)->setWidth(600)->setOffsetX(50)->setOffsetY(180);
            $emptyShape->createTextRun('Tidak ada data pada periode ini.')->getFont()->setItalic(true)->setSize(14)->setColor(new Color(self::TEXT_SECONDARY));

            return;
        }

        $chartShape = $slide->createChartShape();
        $chartShape->setHeight(280)->setWidth(600)->setOffsetX(60)->setOffsetY(110);

        $createdOutline = new Outline();
        $createdOutline->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color(self::CYAN));
        $createdOutline->setWidth(2);
        $createdSeries = new Series('Tickets Created', $created);
        $createdSeries->setOutline($createdOutline);

        $solvedOutline = new Outline();
        $solvedOutline->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color(self::PURPLE));
        $solvedOutline->setWidth(2);
        $solvedSeries = new Series('Tickets Solved', $solved);
        $solvedSeries->setOutline($solvedOutline);

        $type = new Line();
        $type->addSeries($createdSeries);
        $type->addSeries($solvedSeries);
        $chartShape->getPlotArea()->setType($type);

        $chartShape->getLegend()->setPosition(Legend::POSITION_BOTTOM);
        $chartShape->getLegend()->getFont()->setColor(new Color(self::TEXT_SECONDARY))->setSize(11);

        foreach ($chartShape->getPlotArea()->getAxisX() ? [$chartShape->getPlotArea()->getAxisX(), $chartShape->getPlotArea()->getAxisY()] : [] as $axis) {
            $axis->getFont()->setColor(new Color(self::TEXT_SECONDARY));
        }
    }

    private function addDetailTableSlides(PhpPresentation $pp, string $title, Collection $items, string $accentColor): void
    {
        if ($items->isEmpty()) {
            $slide = $this->newSlide($pp);
            $this->addSlideHeader($slide, $title);
            $emptyShape = $slide->createRichTextShape()->setHeight(30)->setWidth(600)->setOffsetX(50)->setOffsetY(180);
            $emptyShape->createTextRun('Tidak ada data pada periode ini.')->getFont()->setItalic(true)->setSize(14)->setColor(new Color(self::TEXT_SECONDARY));

            return;
        }

        $chunks = $items->values()->chunk(9);
        $statusColorMap = [
            'Pending' => self::AMBER,
            'In Progress' => self::BLUE,
            'Approved' => self::TEAL,
            'Completed' => self::EMERALD,
            'Rejected' => self::ROSE,
        ];

        foreach ($chunks as $pageIndex => $chunk) {
            $slide = $this->newSlide($pp);
            $pageSuffix = $chunks->count() > 1 ? ' (' . ($pageIndex + 1) . '/' . $chunks->count() . ')' : '';
            $this->addSlideHeader($slide, $title . $pageSuffix);

            $columns = ['No', 'Judul', 'Department', 'Status', 'Tanggal'];
            $colWidths = [30, 250, 150, 110, 120];

            $table = $slide->createTableShape(count($columns));
            $table->setHeight(20 * ($chunk->count() + 1))->setWidth(array_sum($colWidths))->setOffsetX(50)->setOffsetY(110);

            $headerRow = $table->createRow();
            $headerRow->setHeight(24);
            foreach ($columns as $i => $col) {
                $cell = $headerRow->getCell($i);
                $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color(self::CARD_BORDER));
                $cell->setWidth($colWidths[$i]);
                $run = $cell->createTextRun($col);
                $run->getFont()->setBold(true)->setSize(10)->setColor(new Color(self::TEXT_PRIMARY));
            }

            foreach ($chunk as $rowIndex => $item) {
                $row = $table->createRow();
                $row->setHeight(22);
                $bg = $rowIndex % 2 === 0 ? self::CARD_BG : self::BG;

                $values = [
                    (string) ($rowIndex + 1 + $pageIndex * 9),
                    (string) $item->title,
                    (string) ($item->department ?: '-'),
                    (string) $item->status,
                    $item->created_at->locale('id')->translatedFormat('d M Y'),
                ];

                foreach ($values as $i => $val) {
                    $cell = $row->getCell($i);
                    $cell->setWidth($colWidths[$i]);
                    $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color($bg));
                    $textColor = $i === 3 ? ($statusColorMap[$item->status] ?? self::TEXT_SECONDARY) : self::TEXT_SECONDARY;
                    $run = $cell->createTextRun($val);
                    $run->getFont()->setSize(9)->setColor(new Color($textColor));
                }
            }
        }
    }

    private function addClosingSlide(PhpPresentation $pp, Carbon $start, Carbon $end): void
    {
        $slide = $this->newSlide($pp);

        $text = $slide->createRichTextShape()->setHeight(60)->setWidth(600)->setOffsetX(60)->setOffsetY(160);
        $text->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $text->createTextRun('Terima Kasih')->getFont()->setBold(true)->setSize(36)->setColor(new Color(self::TEXT_PRIMARY));

        $sub = $slide->createRichTextShape()->setHeight(30)->setWidth(600)->setOffsetX(60)->setOffsetY(220);
        $sub->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sub->createTextRun('IT Support Team — PT Adhi Kartiko Pratama Tbk')->getFont()->setSize(14)->setColor(new Color(self::CYAN));
    }
}
