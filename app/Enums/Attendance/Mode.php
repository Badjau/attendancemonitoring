<?php

namespace App\Enums\Attendance;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum Mode: string implements HasLabel
{
    case AutoToggle = 'auto-toggle';
    case ManualButton = 'manual-button';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::AutoToggle => 'Auto-toggle',
            self::ManualButton => 'Manual button',
        };
    }
}
