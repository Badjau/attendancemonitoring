<?php

namespace App\Filament\Admin\Resources\Announcements;

use App\Filament\Admin\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Admin\Resources\Announcements\Pages\ViewAnnouncement;
use App\Filament\Admin\Resources\Announcements\Schemas\AnnouncementForm;
use App\Filament\Admin\Resources\Announcements\Schemas\AnnouncementInfolist;
use App\Filament\Admin\Resources\Announcements\Tables\AnnouncementsTable;
use App\Models\Announcement;
use App\Support\AdminAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return AnnouncementForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AnnouncementInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AnnouncementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'view' => ViewAnnouncement::route('/{record}'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return AdminAccess::canAccessResource('announcements');
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
