<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FilamentUIServiceProvider;
use Laragear\WebAuthn\WebAuthnServiceProvider;

return [
    AppServiceProvider::class,
    FilamentUIServiceProvider::class,
    AdminPanelProvider::class,
    WebAuthnServiceProvider::class,
];
