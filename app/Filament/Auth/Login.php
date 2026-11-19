<?php

namespace App\Filament\Auth;

use App\Models\Employee;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/** Login memakai NIK karyawan (bukan email). Password awal = NIK; wajib diganti saat login pertama (lihat ForcePasswordChange). */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')->label('NIK')->required()->autocomplete('username')->autofocus()->extraInputAttributes(['tabindex' => 1])
            ->placeholder('Masukkan NIK Anda (angka)')->inputMode('numeric')->validationMessages(['required' => 'NIK wajib diisi.']);
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->validationMessages(['required' => 'Password wajib diisi.']);
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $nik = trim((string) ($data['email'] ?? ''));
        $email = $nik === '' ? null : Employee::where('nik', $nik)->whereNotNull('user_id')->first()?->user?->email;

        return ['email' => $email ?? 'nik-tidak-terdaftar', 'password' => $data['password']];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages(['data.email' => 'NIK atau password salah. Lupa password? Hubungi tim IT.']);
    }
}
