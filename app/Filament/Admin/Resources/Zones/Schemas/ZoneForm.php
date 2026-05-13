<?php

namespace App\Filament\Admin\Resources\Zones\Schemas;

use App\Enums\Zones\Policy;
use App\Models\Employee;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ZoneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Zone Details')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('latitude')
                            ->numeric()
                            ->required()
                            ->minValue(-90)
                            ->maxValue(90)
                            ->step('0.0000001')
                            ->live()
                            ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                                $set('location', [
                                    'lat' => (float) $state,
                                    'lng' => (float) $get('longitude'),
                                ]);
                            }),

                        TextInput::make('longitude')
                            ->numeric()
                            ->required()
                            ->minValue(-180)
                            ->maxValue(180)
                            ->step('0.0000001')
                            ->live()
                            ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                                $set('location', [
                                    'lat' => (float) $get('latitude'),
                                    'lng' => (float) $state,
                                ]);
                            }),

                        TextInput::make('radius_meters')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(999999.99)
                            ->step('0.01')
                            ->default(100)
                            ->live(),

                        Select::make('policy')
                            ->options(Policy::class)
                            ->required()
                            ->default(Policy::RELAXED->value),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),

                        Repeater::make('assignments')
                            ->label('Employee Assignments')
                            ->schema([
                                Select::make('employee_ids')
                                    ->label('Employees')
                                    ->options(fn (): array => Employee::query()
                                        ->orderBy('last_name')
                                        ->orderBy('first_name')
                                        ->get()
                                        ->mapWithKeys(fn (Employee $employee): array => [
                                            $employee->id => "{$employee->employee_id} - {$employee->name}",
                                        ])
                                        ->all())
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->columnSpanFull()
                                    ->required(),

                                Toggle::make('is_temporary')
                                    ->label('Temporary assignment')
                                    ->live()
                                    ->default(false),

                                DatePicker::make('effective_date')
                                    ->label('Effective date')
                                    ->hidden(fn (callable $get): bool => ! $get('is_temporary')),

                                DatePicker::make('expiry_date')
                                    ->label('Expiry date')
                                    ->hidden(fn (callable $get): bool => ! $get('is_temporary')),
                            ])
                            ->columns(2)
                            ->dehydrated()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Map')
                    ->schema([
                        ViewField::make('location')
                            ->view('filament.admin.resources.zones.zone-map')
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
            ]);

    }
}
