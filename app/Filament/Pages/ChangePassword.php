<?php

namespace App\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;

/** Ganti password sendiri. Wajib pada login pertama (password awal = NIK), dan bisa dibuka kapan saja dari menu pengguna. */
class ChangePassword extends Page
{
    protected static ?string $title = 'Ganti Password';
    protected static ?string $slug = 'ganti-password';
    protected string $view = 'filament.pages.change-password';

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public static function shouldRegisterNavigation(): bool { return false; }

    public static function canAccess(): bool { return auth()->check(); }

    public function forced(): bool { return (bool) auth()->user()?->must_change_password; }

    public function save()
    {
        $user = auth()->user();
        $nik = (string) $user->employee?->nik;
        $this->validate([
            'current_password' => ['required'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'regex:/[A-Za-z]/', 'regex:/[0-9]/', 'confirmed', 'different:current_password',
                fn ($attr, $value, $fail) => ($nik !== '' && mb_strtolower((string) $value) === mb_strtolower($nik)) ? $fail('Password baru tidak boleh sama dengan NIK.') : null],
        ], [
            'current_password.required' => 'Password saat ini wajib diisi (saat pertama kali, isi dengan NIK Anda).',
            'password.required' => 'Password baru wajib diisi.', 'password.min' => 'Password baru minimal 8 karakter.', 'password.max' => 'Password baru maksimal 100 karakter.',
            'password.regex' => 'Password baru harus mengandung huruf dan angka.', 'password.confirmed' => 'Konfirmasi password tidak sama dengan password baru.',
            'password.different' => 'Password baru harus berbeda dari password saat ini.',
        ], ['password' => 'Password baru']);

        if (! Hash::check($this->current_password, $user->password)) {
            $this->addError('current_password', 'Password saat ini salah.');
            return null;
        }

        $user->forceFill(['password' => $this->password, 'must_change_password' => false])->save();
        // sesi ini tetap masuk; sesi di perangkat lain (dengan password lama) berakhir
        session()->put('password_hash_' . Filament::getAuthGuard(), $user->getAuthPassword());
        \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete();

        Notification::make()->success()->title('Password berhasil diganti')->body('Gunakan password baru pada login berikutnya.')->send();
        return redirect($user->can('view inventory') ? Filament::getUrl() : route('requests.create'));
    }
}
