<?php

namespace App\Filament\Admin\Resources\FaceAttempts;

use App\Filament\Admin\Resources\FaceAttempts\Pages\ListFaceAttempts;
use App\Filament\Admin\Resources\FaceAttempts\Pages\ViewFaceAttempt;
use App\Filament\Admin\Resources\FaceAttempts\Schemas\FaceAttemptInfolist;
use App\Filament\Admin\Resources\FaceAttempts\Tables\FaceAttemptsTable;
use App\Models\FaceAttempt;
use App\Support\AdminAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class FaceAttemptResource extends Resource
{
    protected static ?string $model = FaceAttempt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Face Attempts';

    protected static ?string $modelLabel = 'Face Attempt';

    protected static ?string $pluralModelLabel = 'Face Attempts';

    public static function infolist(Schema $schema): Schema
    {
        return FaceAttemptInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FaceAttemptsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFaceAttempts::route('/'),
            'view' => ViewFaceAttempt::route('/{record}'),
        ];
    }

    public static function canAccess(): bool
    {
        return AdminAccess::canAccessResource('attendances');
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
