<?php

namespace App\Filament\Admin\Resources\Branches\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BranchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Branch Details')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('code')
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        TextInput::make('latitude')
                            ->numeric()
                            ->required()
                            ->minValue(-90)
                            ->maxValue(90)
                            ->step('0.0000001')
                            ->default(0)
                            ->live(),

                        TextInput::make('longitude')
                            ->numeric()
                            ->required()
                            ->minValue(-180)
                            ->maxValue(180)
                            ->step('0.0000001')
                            ->default(0)
                            ->live(),

                        TextInput::make('radius_meters')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(999999.99)
                            ->step('0.01')
                            ->default(150)
                            ->live(),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Map Location')
                    ->schema([
                        ViewField::make('location')
                            ->view('filament.admin.resources.branches.branch-map')
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
