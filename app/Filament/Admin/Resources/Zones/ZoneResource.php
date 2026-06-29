<?php

namespace App\Filament\Admin\Resources\Zones;

use App\Filament\Admin\Resources\Zones\Pages\CreateZone;
use App\Filament\Admin\Resources\Zones\Pages\EditZone;
use App\Filament\Admin\Resources\Zones\Pages\ListZones;
use App\Filament\Admin\Resources\Zones\Schemas\ZoneForm;
use App\Filament\Admin\Resources\Zones\Tables\ZonesTable;
use App\Models\Zone;
use App\Support\AdminAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ZoneResource extends Resource
{
    protected static ?string $model = Zone::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ZoneForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ZonesTable::configure($table);
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
            'index' => ListZones::route('/'),
            'create' => CreateZone::route('/create'),
            'edit' => EditZone::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return AdminAccess::canAccessResource('zones');
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
