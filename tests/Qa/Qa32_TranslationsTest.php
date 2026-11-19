<?php

namespace Tests\Qa;

use Tests\TestCase;

class Qa32_TranslationsTest extends TestCase
{
    public function test_common_laravel_messages_are_translated_and_never_shown_as_raw_keys(): void
    {
        app()->setLocale('id');
        foreach (['passwords.reset', 'passwords.token', 'passwords.sent', 'auth.failed', 'auth.throttle', 'validation.required', 'validation.extensions',
                  'validation.email', 'validation.unique', 'validation.max.string', 'validation.min.numeric', 'validation.mimes', 'validation.uploaded'] as $key) {
            $this->assertNotSame($key, __($key), "terjemahan hilang: {$key}");
        }
        $this->assertSame('Nama wajib diisi.', __('validation.required', ['attribute' => 'Nama']));
        $this->assertStringContainsString('csv, xlsx', __('validation.extensions', ['attribute' => 'File', 'values' => 'csv, xlsx']));
        $this->assertSame('Email atau password salah.', __('auth.failed'));
        // aturan yang tidak diterjemahkan tetap punya teks (bukan kode mentah)
        $this->assertNotSame('validation.alpha_dash', __('validation.alpha_dash', ['attribute' => 'x']));
    }
}
