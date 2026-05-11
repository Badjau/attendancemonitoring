<?php

namespace App\Filament\Admin\Resources\Announcements\Schemas;

use App\Models\Announcement;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AnnouncementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Announcement')
                    ->schema([
                        TextEntry::make('title')
                            ->label('Title')
                            ->weight('bold')
                            ->columnSpanFull(),

                        TextEntry::make('content')
                            ->label('Content')
                            ->html()
                            ->prose()
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columnSpan(['lg' => 2]),

                Section::make('Publishing')
                    ->schema([
                        TextEntry::make('type')
                            ->badge(),

                        TextEntry::make('status')
                            ->badge(),

                        IconEntry::make('is_pinned')
                            ->label('Pinned')
                            ->boolean(),

                        TextEntry::make('createdBy.name')
                            ->label('Created By')
                            ->placeholder('-'),

                        TextEntry::make('published_at')
                            ->label('Published')
                            ->dateTime('M d, Y h:i A')
                            ->placeholder('-'),

                        TextEntry::make('expires_at')
                            ->label('Expires')
                            ->dateTime('M d, Y h:i A')
                            ->placeholder('-'),
                    ])
                    ->columns(2)
                    ->columnSpan(['lg' => 1]),

                Section::make('Attachments')
                    ->schema([
                        SpatieMediaLibraryImageEntry::make('attachment_previews')
                            ->label('Preview')
                            ->collection('announcement_attachments')
                            ->height(180)
                            ->width('100%')
                            ->extraImgAttributes(['class' => 'rounded-lg object-cover'])
                            ->visible(fn (Announcement $record): bool => $record->hasMedia('announcement_attachments')),

                        TextEntry::make('attachment_files')
                            ->label('Files')
                            ->state(fn (Announcement $record): array => $record
                                ->getMedia('announcement_attachments')
                                ->map(fn ($media): string => $media->file_name)
                                ->all())
                            ->bulleted()
                            ->placeholder('No attachments.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('System')
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime('M d, Y h:i A')
                            ->placeholder('-'),

                        TextEntry::make('updated_at')
                            ->label('Last Updated')
                            ->dateTime('M d, Y h:i A')
                            ->placeholder('-'),

                        TextEntry::make('deleted_at')
                            ->label('Deleted')
                            ->dateTime('M d, Y h:i A')
                            ->visible(fn (Announcement $record): bool => $record->trashed()),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
