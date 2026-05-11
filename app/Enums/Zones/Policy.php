<?php

namespace App\Enums\Zones;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum Policy: string implements HasLabel, HasColor, HasIcon
{
    case STRICT = 'strict';
    case RELAXED = 'relaxed';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::STRICT => "Strict - must be inside this zone",
            self::RELAXED => "Relaxed - log only, never enforced",
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::STRICT => 'danger',
            self::RELAXED => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::STRICT => 'heroicon-o-no-symbol',
            self::RELAXED => 'heroicon-o-check-circle',
        };
    }
}
