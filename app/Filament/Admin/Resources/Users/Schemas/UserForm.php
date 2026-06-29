<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'employee_id')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => "{$record->employee_id} - {$record->name}")
                    ->searchable(['employee_id', 'first_name', 'last_name'])
                    ->preload()
                    ->nullable()
                    ->helperText('Choose the employee this login account belongs to.'),
                TextInput::make('name')
                    ->label('Nickname')
                    ->required(),
                TextInput::make('username')
                    ->alphaDash()
                    ->nullable()
                    ->unique(ignoreRecord: true),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->unique(ignoreRecord: true)
                    ->required(),
                DateTimePicker::make('email_verified_at'),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->formatStateUsing(fn (): ?string => null)
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                Toggle::make('is_admin')
                    ->label('Admin account')
                    ->default(true),
                Toggle::make('is_it_admin')
                    ->label('IT admin')
                    ->helperText('IT admins keep full access.'),
                Toggle::make('is_hr')
                    ->label('HR')
                    ->helperText('HR filtering takes priority and only allows Announcements, Attendances, Departments, Employees, and Zones.'),
            ]);
    }
}
