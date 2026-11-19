<?php

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LoginResponse as Responsable;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/** Setelah login: karyawan biasa (role User) langsung ke halaman kartu System Requisition; tim IT ke dashboard IT Asset. */
class LoginResponse implements Responsable
{
    public function toResponse($request): RedirectResponse | Redirector
    {
        $user = $request->user();
        if ($user && ! $user->can('view inventory')) {
            return redirect()->intended(route('requests.create'));
        }

        return redirect()->intended(Filament::getUrl());
    }
}
