<?php

namespace Tests\Qa;

use Tests\QaTestCase;

/** Direktif Blade (@if, @foreach, ...) yang menempel langsung pada huruf/angka tidak diproses dan bocor ke layar. */
class Qa73_BladeDirectivesTest extends QaTestCase
{
    public function test_no_view_has_a_directive_glued_to_a_word(): void
    {
        $bad = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $f) {
            if (! str_ends_with($f->getFilename(), '.blade.php')) continue;
            foreach (file($f->getPathname()) as $n => $line) {
                if (preg_match('/[A-Za-z0-9_]@(?:if|elseif|else|endif|foreach|endforeach|forelse|endforelse|unless|endunless|isset|endisset|auth|endauth|can|endcan|php|endphp)\b(?![.\w-]*\.(?:com|id|test|co)\b)/', $line)
                    && ! preg_match('/[a-z]@[a-z]+\.(com|id|test|co)/i', $line)) {
                    $bad[] = str_replace(resource_path('views') . '/', '', $f->getPathname()) . ':' . ($n + 1);
                }
            }
        }
        $this->assertSame([], $bad, 'Direktif Blade menempel pada kata: beri spasi atau pakai {{ }}');
    }

    public function test_dashboard_utilisation_line_renders_without_raw_directive(): void
    {
        $html = $this->as($this->admin())->get(\App\Filament\Pages\Dashboard::getUrl())->assertOk()->getContent();
        $this->assertStringContainsString('aset aktif milik', $html);
        $this->assertStringNotContainsString('@if(', $html);
        $this->assertStringNotContainsString('@endif', $html);
    }
}
