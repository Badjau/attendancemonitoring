<?php

namespace App\Filament\Admin\Resources\TimeclockUnlockLogs;

use App\Filament\Admin\Resources\TimeclockUnlockLogs\Pages\ListTimeclockUnlockLogs;
use App\Filament\Admin\Resources\TimeclockUnlockLogs\Pages\ViewTimeclockUnlockLog;
use App\Filament\Admin\Resources\TimeclockUnlockLogs\Schemas\TimeclockUnlockLogInfolist;
use App\Filament\Admin\Resources\TimeclockUnlockLogs\Tables\TimeclockUnlockLogsTable;
use App\Models\TimeclockUnlockLog;
use App\Support\AdminAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class TimeclockUnlockLogResource extends Resource
{
    protected static ?string $model = TimeclockUnlockLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Administrator';

    protected static ?string $navigationLabel = 'Unlock Logs';

    protected static ?string $modelLabel = 'Unlock Log';

    protected static ?string $pluralModelLabel = 'Unlock Logs';

    public static function infolist(Schema $schema): Schema
    {
        return TimeclockUnlockLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TimeclockUnlockLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTimeclockUnlockLogs::route('/'),
            'view' => ViewTimeclockUnlockLog::route('/{record}'),
        ];
    }

    public static function canAccess(): bool
    {
        return AdminAccess::canAccessResource('timeclock-unlock-logs');
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return static::canAccess();
    }
}
