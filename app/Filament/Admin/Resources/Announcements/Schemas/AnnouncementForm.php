<?php

namespace App\Filament\Admin\Resources\Announcements\Schemas;

use App\Enums\Announcement\Status;
use App\Enums\Announcement\Type;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class AnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),

                RichEditor::make('content')
                    ->required()
                    ->columnSpanFull(),

                Select::make('type')
                    ->options(Type::class)
                    ->default('general')
                    ->required(),

                Select::make('status')
                    ->options(Status::class)
                    ->default(Status::PUBLISHED->value)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, $state) => $state === Status::PUBLISHED->value ? $set('published_at', Carbon::now()) : null)
                    ->required(),

                Hidden::make('published_at'),

                DatePicker::make('expires_at')
                    ->required()
                    ->date('F, d Y'),

                Toggle::make('is_pinned')
                    ->default(true),

                SpatieMediaLibraryFileUpload::make('attachments')
                    ->collection('announcement_attachments'),

            ]);
    }
}
