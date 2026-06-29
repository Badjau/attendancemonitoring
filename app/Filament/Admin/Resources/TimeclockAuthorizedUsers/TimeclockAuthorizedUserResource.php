<?php

namespace App\Filament\Admin\Resources\TimeclockAuthorizedUsers;

use App\Filament\Admin\Resources\TimeclockAuthorizedUsers\Pages\CreateTimeclockAuthorizedUser;
use App\Filament\Admin\Resources\TimeclockAuthorizedUsers\Pages\EditTimeclockAuthorizedUser;
use App\Filament\Admin\Resources\TimeclockAuthorizedUsers\Pages\ListTimeclockAuthorizedUsers;
use App\Filament\Admin\Resources\TimeclockAuthorizedUsers\Schemas\TimeclockAuthorizedUserForm;
use App\Filament\Admin\Resources\TimeclockAuthorizedUsers\Tables\TimeclockAuthorizedUsersTable;
use App\Models\TimeclockAuthorizedUser;
use App\Support\AdminAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class TimeclockAuthorizedUserResource extends Resource
{
    protected static ?string $model = TimeclockAuthorizedUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Administrator';

    protected static ?string $navigationLabel = 'Timeclock Unlockers';

    protected static ?string $modelLabel = 'Timeclock Unlocker';

    protected static ?string $pluralModelLabel = 'Timeclock Unlockers';

    protected static ?string $recordTitleAttribute = 'employee_id';

    public static function form(Schema $schema): Schema
    {
        return TimeclockAuthorizedUserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TimeclockAuthorizedUsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTimeclockAuthorizedUsers::route('/'),
            'create' => CreateTimeclockAuthorizedUser::route('/create'),
            'edit' => EditTimeclockAuthorizedUser::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return AdminAccess::canAccessResource('timeclock-authorized-users');
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canDeleteAny(): bool
    {
        return static::canAccess();
    }
}
