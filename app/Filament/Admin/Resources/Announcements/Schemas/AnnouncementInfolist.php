<?php

namespace App\Filament\Admin\Resources\Announcements\Schemas;

use App\Models\Announcement;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AnnouncementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('title'),

                TextEntry::make('content')
                    ->html()
                    ->columnSpanFull(),

                TextEntry::make('type'),

                TextEntry::make('status'),

                TextEntry::make('created_by'),

                TextEntry::make('published_at')
                    ->dateTime()
                    ->placeholder('-'),

                TextEntry::make('expires_at')
                    ->dateTime()
                    ->placeholder('-'),

                IconEntry::make('is_pinned')
                    ->boolean(),

                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),

                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),

                TextEntry::make('deleted_at')
                    ->dateTime()
                    ->visible(fn (Announcement $record): bool => $record->trashed()),
            ]);
    }
}
