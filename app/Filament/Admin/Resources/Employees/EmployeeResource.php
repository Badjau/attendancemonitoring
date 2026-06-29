<?php

namespace App\Filament\Admin\Resources\Employees;

use App\Filament\Admin\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Admin\Resources\Employees\Pages\EditEmployee;
use App\Filament\Admin\Resources\Employees\Pages\ListEmployees;
use App\Filament\Admin\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Admin\Resources\Employees\RelationManagers\AttendancesRelationManager;
use App\Filament\Admin\Resources\Employees\Schemas\EmployeeForm;
use App\Filament\Admin\Resources\Employees\Schemas\EmployeeInfolist;
use App\Filament\Admin\Resources\Employees\Tables\EmployeesTable;
use App\Models\Employee;
use App\Support\AdminAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'employee_id';

    public static function form(Schema $schema): Schema
    {
        return EmployeeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EmployeeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AttendancesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'view' => ViewEmployee::route('/{record}'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return AdminAccess::canAccessResource('employees');
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return AdminAccess::hasAnyAdminAccess();
    }

    public static function canDeleteAny(): bool
    {
        return AdminAccess::hasAnyAdminAccess();
    }
}
