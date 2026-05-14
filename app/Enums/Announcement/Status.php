<?php

namespace App\Enums\Announcement;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum Status: string implements HasColor, HasIcon, HasLabel
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PUBLISHED => 'Published',
            self::ARCHIVED => 'Archived',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::DRAFT => 'secondary',
            self::PUBLISHED => 'success',
            self::ARCHIVED => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::DRAFT => 'heroicon-o-pencil',
            self::PUBLISHED => 'heroicon-o-calendar',
            self::ARCHIVED => 'heroicon-o-archive-box',
        };
    }
}
