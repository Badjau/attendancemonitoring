<?php

namespace App\Filament\Admin\Resources\TimeclockUnlockLogs;

use App\Filament\Admin\Resources\TimeclockUnlockLogs\Pages\ListTimeclockUnlockLogs;
use App\Filament\Admin\Resources\TimeclockUnlockLogs\Pages\ViewTimeclockUnlockLog;
use App\Filament\Admin\Resources\TimeclockUnlockLogs\Schemas\TimeclockUnlockLogInfolist;
use App\Filament\Admin\Resources\TimeclockUnlockLogs\Tables\TimeclockUnlockLogsTable;
use App\Models\TimeclockUnlockLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
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
}
