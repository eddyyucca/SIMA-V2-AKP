<?php

namespace Tests\Requisition;

use Tests\RequisitionTestCase;

/** Menu akun ITERA: Log Out ada di dalam dropdown nama (halaman ITERA tidak memuat Alpine). */
class AccountMenuTest extends RequisitionTestCase
{
    public function test_logout_lives_inside_the_name_dropdown_not_beside_it(): void
    {
        $u = $this->makeUser('user', ['name' => 'Dropdown Tester']);
        $html = $this->actingAs($u)->get('/requisition/requests/create')->assertOk()->getContent();
        $nav = substr($html, (int) strpos($html, '<nav'), (int) (strpos($html, '</nav>') - strpos($html, '<nav')));
        $this->assertStringContainsString('id="amMenu"', $nav);                                // dropdown tanpa Alpine (Alpine tidak dimuat di halaman ITERA)
        $this->assertStringNotContainsString('x-show="open"', $nav);                          // dropdown bawaan Alpine selalu tersembunyi di sini
        $drop = substr($nav, (int) strpos($nav, 'id="amMenu"'));
        $this->assertStringContainsString('Barang Saya (IT Asset)', $drop);
        $this->assertStringContainsString(route('logout'), $drop);
        $this->assertStringNotContainsString('Tombol Logout langsung terlihat', $nav);
        // tombol di bilah atas (sebelum dropdown) tidak lagi ada
        $before = substr($nav, 0, (int) strpos($nav, 'id="amMenu"'));
        $this->assertSame(0, preg_match('#<form[^>]*action="[^"]*logout"[^>]*>(?:(?!</form>).)*Log Out#s', substr($before, (int) strpos($before, 'pushNotifBtn'))));
    }

    public function test_sidebar_pages_use_the_name_dropdown_for_logout(): void
    {
        $admin = $this->makeUser('admin', ['name' => 'Admin Dropdown']);
        $pages = ['/requisition' => $this->actingAs($admin)->get('/requisition')->assertOk()->getContent(),
            '/requisition/peminjaman' => $this->actingAs($admin)->get('/requisition/peminjaman')->assertOk()->getContent()];
        foreach ($pages as $url => $html) {
            $this->assertStringContainsString('id="amBtn"', $html, $url);
            $this->assertStringContainsString('id="amMenu"', $html, $url);
            $this->assertSame(1, substr_count($html, 'Log Out'), "{$url}: hanya satu tombol keluar, di dalam dropdown");
            $this->assertStringNotContainsString('>Logout</button>', $html, $url);
        }
    }
}
