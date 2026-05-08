<?php

namespace App\Filament\Admin\Resources\Attendances\Schemas;

use App\Enums\Attendance\OvertimeStatus;
use App\Enums\Attendance\Status;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AttendanceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->first_name} {$record->last_name}")
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('rfid_uid'),

                Select::make('attendance_type')
                    ->label('Type')
                    ->options([
                        'time-in' => 'Time In',
                        'time-out' => 'Time Out',
                    ])
                    ->required(),

                Select::make('attendance_method')
                    ->label('Method')
                    ->options([
                        'rfid' => 'RFID',
                        'fingerprint' => 'Fingerprint',
                        'keypad' => 'Keypad',
                    ]),

                DatePicker::make('attendance_date')
                    ->required(),

                DateTimePicker::make('time_in'),

                DateTimePicker::make('time_out'),

                TextInput::make('total_hours')
                    ->numeric(),

                Select::make('status')
                    ->options(Status::class)
                    ->default('present')
                    ->required(),

                Toggle::make('is_late')
                    ->required(),

                TextInput::make('late_minutes')
                    ->required()
                    ->numeric()
                    ->default(0),

                Toggle::make('is_undertime')
                    ->required(),

                TextInput::make('undertime_minutes')
                    ->required()
                    ->numeric()
                    ->default(0),

                Toggle::make('is_overtime')
                    ->required(),

                TextInput::make('overtime_minutes')
                    ->required()
                    ->numeric()
                    ->default(0),

                Select::make('overtime_status')
                    ->options(OvertimeStatus::class),

                TextInput::make('location'),

                TextInput::make('latitude')
                    ->numeric(),

                TextInput::make('longitude')
                    ->numeric(),

                Textarea::make('remarks')
                    ->columnSpanFull(),

                TextInput::make('recorded_by')
                    ->numeric(),
            ]);
    }
}
