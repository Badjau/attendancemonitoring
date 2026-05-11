<?php

namespace App\Enums\Attendance;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum Type: string implements HasLabel, HasIcon
{
    case TimeIn = 'time-in';
    case TimeOut = 'time-out';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::TimeIn => 'Time In',
            self::TimeOut => 'Time Out',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::TimeIn => 'heroicon-o-arrow-right-end-on-rectangle',
            self::TimeOut => 'heroicon-o-arrow-right-start-on-rectangle',
        };
    }
}
