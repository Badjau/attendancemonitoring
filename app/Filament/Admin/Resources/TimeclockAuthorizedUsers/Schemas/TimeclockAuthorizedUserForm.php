<?php

namespace App\Filament\Admin\Resources\TimeclockAuthorizedUsers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TimeclockAuthorizedUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Unlock Access')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Employee')
                            ->relationship('employee', 'employee_id')
                            ->getOptionLabelFromRecordUsing(fn ($record): string => "{$record->employee_id} - {$record->name}")
                            ->searchable(['employee_id', 'first_name', 'last_name', 'rfid_uid'])
                            ->preload()
                            ->unique(ignoreRecord: true)
                            ->required()
                            ->helperText('Password and RFID are taken from the selected employee record.'),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
