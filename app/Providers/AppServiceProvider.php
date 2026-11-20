<?php

namespace App\Providers;

use App\Policies\InventoryPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // PostgreSQL: LIKE harus tidak peka huruf besar/kecil seperti di MariaDB (pencarian nama, NIK, dll.)
        // PostgreSQL membedakan huruf pada "=", SIMA/MariaDB tidak. whereCi() = pencocokan persis tanpa membedakan huruf untuk input pengguna (email, nama, kode).
        \Illuminate\Database\Eloquent\Builder::macro('whereCi', function (string $column, $value) {
            /** @var \Illuminate\Database\Eloquent\Builder $this */
            $wrapped = $this->getQuery()->getGrammar()->wrap($this->getModel()->qualifyColumn($column));
            return $this->whereRaw("lower({$wrapped}::text) = ?", [mb_strtolower(trim((string) $value))]);
        });
        \Illuminate\Database\Connection::resolverFor('pgsql', fn ($pdo, $database, $prefix, $config) => new \App\Support\Database\CaseInsensitivePostgresConnection($pdo, $database, $prefix, $config));
        $this->app->bind(\Filament\Auth\Http\Responses\Contracts\LoginResponse::class, \App\Http\Responses\LoginResponse::class);
    }

    public function boot(): void
    {
        // Batas laju API agent (sama dengan SIMA): per token, per token helper, dan per IP
        \Illuminate\Support\Facades\RateLimiter::for('agent', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(20)->by('agent:' . sha1((string) $r->bearerToken())));
        \Illuminate\Support\Facades\RateLimiter::for('agent-helper', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(90)->by('agent-helper:' . sha1((string) $r->bearerToken())));
        \Illuminate\Support\Facades\RateLimiter::for('agent-ip', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(3000)->by('agent-ip:' . $r->ip()));
        foreach ([
            \App\Models\Category::class, \App\Models\Department::class, \App\Models\Location::class, \App\Models\Vendor::class,
            \App\Models\Employee::class, \App\Models\Asset::class, \App\Models\Accessory::class, \App\Models\Consumable::class,
            \App\Models\Component::class, \App\Models\License::class, \App\Models\Repair::class, \App\Models\Disposal::class,
            \App\Models\StockOpname::class, \App\Models\AssetAssignment::class, \App\Models\StockMovement::class,
            \App\Models\Brand::class, \App\Models\AssetModel::class, \App\Models\AccessoryHandover::class, \App\Models\StockReceipt::class, \App\Models\ArrivalPlan::class, \App\Models\ArrivalPlanItem::class,
        ] as $model) {
            Gate::policy($model, InventoryPolicy::class);
        }
        Gate::policy(\App\Models\ArrivalPlan::class, \App\Policies\ArrivalPlanPolicy::class);
        Gate::policy(\App\Models\IpAddress::class, \App\Policies\IpAddressPolicy::class);
        foreach ([\App\Models\Category::class, \App\Models\Department::class, \App\Models\Location::class, \App\Models\Vendor::class] as $master) {
            Gate::policy($master, \App\Policies\MasterPolicy::class);
        }
        // Aksi per baris tabel: hanya ikon, nama tampil sebagai tooltip saat kursor di atas ikon.
        \Filament\Tables\Table::configureUsing(fn (\Filament\Tables\Table $table) => $table->modifyUngroupedRecordActionsUsing(
            fn (\Filament\Actions\Action|\Filament\Actions\ActionGroup $action) => $action instanceof \Filament\Actions\Action
                ? $action->iconButton()->tooltip(fn () => $action->getLabel())
                : $action
        ));
        // Error database di aksi / form Filament: tampil sebagai notifikasi yang jelas, bukan "Terjadi error ketika memuat halaman".
        // Di pengujian otomatis tetap dilempar agar bug terlihat.
        \Livewire\on('exception', function ($target, $e, $stopPropagation) {
            if (! $e instanceof \Illuminate\Database\QueryException || (app()->runningUnitTests() && ! config('app.friendly_errors_in_tests'))) return;
            report($e);
            [$title, $body] = \App\Support\FriendlyErrors::fromQuery($e);
            \Filament\Notifications\Notification::make()->title($title)->body($body)->danger()->persistent()->send();
            $stopPropagation();
        });
        if (str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
