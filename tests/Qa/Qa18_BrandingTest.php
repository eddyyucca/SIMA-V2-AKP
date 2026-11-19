<?php

namespace Tests\Qa;

use App\Models\AssetAssignment;
use App\Models\Disposal;
use App\Support\Branding;
use Filament\Facades\Filament;
use Tests\QaTestCase;

class Qa18_BrandingTest extends QaTestCase
{
    public function test_app_name_is_it_asset_and_inventory_management(): void
    {
        $this->assertSame('IT Asset & Inventory Management', Branding::NAME);
        $this->assertSame(Branding::NAME, config('app.name'));
        $panel = Filament::getPanel('admin');
        $this->assertSame(Branding::NAME, $panel->getBrandName());
        $this->assertStringNotContainsString('Inventaris IT', $panel->getBrandName());
    }

    public function test_logo_file_is_the_akp_png_and_is_wired_to_the_panel(): void
    {
        $f = Branding::logoPath();
        $this->assertFileExists($f);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr((string) file_get_contents($f), 0, 8), 'harus berkas PNG');
        [$w, $h] = getimagesize($f);
        $this->assertGreaterThanOrEqual(200, $w);
        $this->assertGreaterThan($h, $w, 'logo landscape');
        $this->assertLessThan(200 * 1024, filesize($f), 'ukuran ringan');

        $panel = Filament::getPanel('admin');
        $brand = (string) $panel->getBrandLogo();
        $this->assertStringContainsString('/images/logo-akp.png', $brand);
        $this->assertMatchesRegularExpression('#akp-brand-text[^>]*>IT Asset &amp; Inventory Management</span>#', $brand);   // nama lengkap di samping logo
        $this->assertStringNotContainsString('<br>', $brand, 'satu baris');
        $this->assertStringContainsString('white-space:nowrap', $brand);
        $this->assertSame('2.5rem', $panel->getBrandLogoHeight());
        $this->assertStringEndsWith('/images/logo-akp.png', (string) $panel->getFavicon());
    }

    public function test_logo_has_no_nickel_mining_caption(): void
    {
        $f = Branding::logoPath();
        [$w, $h] = getimagesize($f);
        $this->assertLessThanOrEqual(60, $h, 'logo sudah dipotong (tanpa baris NICKEL MINING)');
        $img = imagecreatefrompng($f);
        imagesavealpha($img, true);
        for ($y = 0; $y < $h; $y++) {                                  // tidak ada pita kosong/baris tulisan di bawah logo
            $filled = 0;
            for ($x = 0; $x < $w; $x++) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) < 100) $filled++;
            }
            $this->assertGreaterThan(0, $filled, "baris {$y} kosong");
        }
        imagedestroy($img);
    }

    public function test_login_page_shows_only_the_logo_while_the_sidebar_keeps_logo_and_name(): void
    {
        auth()->logout();
        $login = $this->get('/admin/login')->assertOk()->getContent();
        $this->assertStringContainsString('.fi-simple-layout .akp-brand-text{display:none}', $login, 'nama disembunyikan di halaman login');
        $this->assertStringContainsString('.fi-simple-layout img.akp-mark{height:3rem', $login, 'logo login lebih besar');
        $this->assertMatchesRegularExpression('/class="[^"]*\bfi-simple-layout\b/', $login, 'halaman login memakai layout sederhana');
        $this->assertStringContainsString('class="akp-mark"', $login);

        $app = $this->as($this->admin())->get('/admin/assets')->assertOk()->getContent();
        $this->assertStringContainsString('class="akp-brand-text"', $app);                         // teks ada di halaman aplikasi
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*\bfi-simple-layout\b/', $app, 'halaman aplikasi bukan layout login');
        $this->assertStringContainsString('>IT Asset &amp; Inventory Management</span>', $app);
        $this->assertStringContainsString('@media(max-width:1279px){.akp-brand-text{font-size:1.3rem', $app, 'mengecil bertahap di layar sempit');
        $this->assertStringNotContainsString('Inventory<br>Management', $app, 'satu baris');
        $this->assertMatchesRegularExpression('/font-size:1\.6rem/', $app, 'ukuran teks lebih besar');
        $this->assertStringContainsString('<title>', $app);
        $this->assertStringContainsString('IT Asset &amp; Inventory Management', $app, 'nama lengkap tetap ada di judul tab');
        $this->assertMatchesRegularExpression('/akp-mark[^>]*style="height:2\.1rem/', $app, 'logo sedikit lebih besar');
    }

    public function test_login_page_shows_logo_name_and_favicon(): void
    {
        auth()->logout();
        $html = $this->get('/admin/login')->assertOk()->getContent();
        $this->assertStringContainsString('images/logo-akp.png', $html);                      // logo di halaman login
        $this->assertStringContainsString('IT Asset &amp; Inventory Management', $html);        // nama di judul tab / alt
        $this->assertMatchesRegularExpression('#<link rel="icon" href="[^"]*logo-akp\.png"#', $html);   // favicon
        $this->assertStringNotContainsString('Inventaris IT', $html);
    }

    public function test_every_page_has_logo_in_sidebar_and_dark_mode_background_rule(): void
    {
        foreach (['/admin', '/admin/assets', '/admin/laporan', '/admin/barang-saya'] as $p) {
            $html = $this->as($this->admin())->get($p)->assertOk()->getContent();
            $this->assertStringContainsString('images/logo-akp.png', $html, $p);
            $this->assertStringContainsString('IT Asset &amp; Inventory Management', $html, $p);
            $this->assertStringContainsString('.dark img.akp-mark', $html, 'logo tetap terbaca pada mode gelap');
            $this->assertMatchesRegularExpression('#class="akp-mark".{0,420}>IT Asset &amp; Inventory Management</span>#s', $html, 'nama lengkap persis di samping logo');
        }
        $this->as($this->plain())->get('/admin/barang-saya')->assertOk()->assertSee('logo-akp.png', false);
    }

    public function test_pdfs_carry_the_logo_header(): void
    {
        $uri = Branding::logoDataUri();
        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $this->assertGreaterThan(1000, strlen($uri));

        $this->as($this->staff());
        $a = AssetAssignment::with(['asset.category', 'from', 'to', 'location', 'creator'])->whereNotNull('bast_pdf_path')->firstOrFail();
        $html = view('pdf.bast', ['a' => $a, 'sigReceiver' => null, 'sigIt' => null])->render();
        $this->assertStringContainsString($uri, $html);
        $this->assertStringContainsString('PT. ADHI KARTIKO PRATAMA TBK', $html);                // kop BAST mengikuti formulir perusahaan
        $this->assertStringContainsString($a->bast_number, $html);                              // isi BAST tetap utuh

        $d = Disposal::with(['asset', 'requester', 'approver'])->where('status', 'approved')->firstOrFail();
        $this->assertStringContainsString($uri, view('pdf.disposal', ['d' => $d])->render());
        $this->assertStringContainsString('IT Asset &amp; Inventory Management', view('pdf.disposal', ['d' => $d])->render());
        $this->assertStringContainsString($uri, view('pdf.table', ['title' => 'Uji', 'head' => ['A'], 'rows' => [['1']]])->render());

        // PDF benar-benar terbentuk dengan kop
        foreach ([\Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.bast', ['a' => $a, 'sigReceiver' => null, 'sigIt' => null]), \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.disposal', ['d' => $d])] as $pdf) {
            $this->assertStringStartsWith('%PDF', $pdf->output());
        }
        $this->get('/reports/assets.pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('labels.print', ['kind' => 'asset', 'ids' => (string) \App\Models\Asset::first()->id]))->assertOk();     // label tidak terpengaruh
    }

    public function test_missing_logo_file_does_not_break_pdfs(): void
    {
        $this->as($this->staff());
        $backup = Branding::logoPath() . '.bak';
        rename(Branding::logoPath(), $backup);
        try {
            $html = view('pdf.partials.brand-header')->render();
            $this->assertStringContainsString('IT Asset &amp; Inventory Management', $html);
        } finally {
            rename($backup, Branding::logoPath());
        }
        $this->assertFileExists(Branding::logoPath());
    }

    public function test_bast_email_signature_uses_the_new_name(): void
    {
        $a = AssetAssignment::whereNotNull('bast_pdf_path')->firstOrFail();
        $mail = new \App\Mail\BastMail($a);
        $this->assertStringContainsString('IT Asset &amp; Inventory Management', $mail->render());
    }
}
