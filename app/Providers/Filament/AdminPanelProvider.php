<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(\App\Filament\Auth\Login::class)
            ->font('Inter')
            ->brandName(\App\Support\Branding::NAME)
            ->brandLogo(fn () => \App\Support\Branding::brandHtml())
            ->brandLogoHeight('2.5rem')
            ->favicon(fn () => \App\Support\Branding::logoUrl())
            ->colors([
                'primary' => Color::hex('#1f6fb5'),
                'info' => Color::hex('#1f6fb5'),
                'success' => Color::hex('#2fa99b'),
                'warning' => Color::hex('#d9a02b'),
                'danger' => Color::hex('#d64545'),
                'gray' => Color::Slate,
            ])
            ->maxContentWidth(\Filament\Support\Enums\Width::Full)
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->navigationGroups(['Inventaris', 'Transaksi', 'Master Data', 'Laporan'])
            ->globalSearch()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => '<style>.dark img.akp-mark{background:#fff;border-radius:6px;padding:3px 7px;box-sizing:content-box}@media(max-width:1279px){.akp-brand-text{font-size:1.3rem!important}}@media(max-width:1023px){.akp-brand-text{font-size:1.1rem!important}}@media(max-width:640px){.akp-brand-text{font-size:.85rem!important}.akp-mark{height:1.5rem!important}}.fi-simple-layout .akp-brand-text{display:none}.fi-simple-layout img.akp-mark{height:3rem!important}</style>')
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => '<link rel="stylesheet" href="' . asset('css/akp-theme.css') . '?v=' . @filemtime(public_path('css/akp-theme.css')) . '">')
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => '<script src="' . asset('vendor/html5-qrcode.min.js') . '"></script>')
            // menu teks ITERA di samping logo/nama aplikasi pada topbar (menuju System Requisition IT)
            ->renderHook(PanelsRenderHook::TOPBAR_LOGO_AFTER, fn (): string => view('filament.requisition-button')->render())
            // salinan ITERA untuk layar kecil (logo topbar disembunyikan Filament di bawah lg)
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn (): string => view('filament.requisition-button', ['mobile' => true])->render())
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->userMenuItems(array_filter([
                \Filament\Navigation\MenuItem::make()->label('Ganti Password')->icon('heroicon-o-key')->url(fn () => \App\Filament\Pages\ChangePassword::getUrl())->visible(fn () => ! config('sso.enabled') || session('sso.local')),
                // SSO aktif: keluar = akhiri sesi aplikasi lalu kembali ke Portal
                'logout' => config('sso.enabled') ? \Filament\Actions\Action::make('logout')->label('Keluar')->icon('heroicon-o-arrow-left-on-rectangle')->url(fn () => route('portal.logout'))->postToUrl() : null,
            ]))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): string => '<p class="text-center text-sm" style="margin-top:.75rem;opacity:.75">Lupa password? Hubungi tim IT.</p>')
            ->authMiddleware([Authenticate::class, \App\Http\Middleware\EnforcePortalSession::class, \App\Http\Middleware\ForcePasswordChange::class]);
    }
}
