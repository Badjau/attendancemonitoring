<?php

namespace App\Filament\Admin\Resources\Employees\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('department_id')
                    ->label('Department')
                    ->relationship('department', 'name')
                    ->required(),

                TextInput::make('first_name')
                    ->required(),

                TextInput::make('last_name')
                    ->required(),

                TextInput::make('middle_name')
                    ->required(),

                DatePicker::make('date_of_birth')
                    ->required(),

                TextInput::make('position')
                    ->required(),

                TextInput::make('rfid_uid')
                    ->label('RFID UID')
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('Used for RFID attendance and timeclock unlock.'),

                TextInput::make('password')
                    ->label('Keypad Password')
                    ->password()
                    ->revealable()
                    ->maxLength(255)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('Used for manual keypad attendance. Leave blank to keep the current password.'),
            ]);
    }
}
