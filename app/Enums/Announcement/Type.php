<?php

namespace App\Enums\Announcement;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum Type: string implements HasColor, HasLabel
{
    case GENERAL = 'general';
    case URGENT = 'urgent';
    case EVENT = 'event';
    case HOLIDAY = 'holiday';
    case POLICY = 'policy';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::GENERAL => 'General',
            self::URGENT => 'Urgent',
            self::EVENT => 'Event',
            self::HOLIDAY => 'Holiday',
            self::POLICY => 'Policy',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::GENERAL => 'primary',
            self::URGENT => 'secondary',
            self::EVENT => 'success',
            self::HOLIDAY => 'danger',
            self::POLICY => 'warning',
        };
    }
}
