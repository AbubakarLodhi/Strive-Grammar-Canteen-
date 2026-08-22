<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\EnsureStaffIsVerified;
use App\Http\Middleware\SetPanelAuthDefaults;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class UserPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('user')
            ->path('staff')
            ->authGuard('staff')
            ->authPasswordBroker('staffs')
            ->login(Login::class)
            ->passwordReset()
            ->colors([
                'primary' => Color::generatePalette(config('branding.colors.primary')),
                'gray' => Color::Slate,
            ])
            ->defaultThemeMode(ThemeMode::Dark)
            ->favicon(asset(config('branding.favicon')))
            ->brandLogo(function () {
                $path = Filament::auth()->user()?->merchant?->logo?->photo_url;

                if ($path && Storage::disk('public')->exists($path)) {
                    return asset('storage/'.$path);
                }

                return asset(config('branding.logo'));
            })
            ->brandName(fn () => Filament::auth()->user()?->merchant?->name ?? config('branding.name'))
            ->brandLogoHeight('2.25rem')
            ->darkModeBrandLogo(asset(config('branding.logo_dark')))
            ->viteTheme('resources/css/filament/merchant/theme.css')
            ->navigationGroups([
                'Sales',
                'Purchase',
                'Inventory',
                'Finance',
                'HR',
                'Assets',
                'Reportings',
                'Configurations',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetPanelAuthDefaults::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                EnsureStaffIsVerified::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])

            ->renderHook(
                'panels::head.end',
                function () {
                    $user = Filament::auth()->user();
                    $settings = $user?->merchant?->settings;

                    return view('filament.merchant.theme-vars', [
                        'primary' => Color::generatePalette($settings?->primary_color ?? config('branding.colors.primary')),
                        'success' => Color::generatePalette($settings?->success_color ?? config('branding.colors.success')),
                        'secondary' => Color::generatePalette($settings?->secondary_color ?? config('branding.colors.secondary')),
                        'danger' => Color::generatePalette($settings?->danger_color ?? config('branding.colors.danger')),
                        'warning' => Color::generatePalette($settings?->warning_color ?? config('branding.colors.warning')),
                        'default' => Color::generatePalette($settings?->default_color ?? config('branding.colors.default')),
                        'sidebarPrimary' => $settings?->primary_color ?? config('branding.colors.primary'),
                        'sidebarSecondary' => $settings?->secondary_color ?? config('branding.colors.accent'),
                    ]);
                }
            )
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn () => view('filament.auth.forgot-password-link')
            )
            ->renderHook(
                'panels::body.end',
                fn () => view('filament.sidebar-hover')
            )
            ->renderHook(
                'panels::body.end',
                fn () => view('filament.dashboard-product-variant-select-script')
            )

            ->globalSearch(false);
    }
}
