<?php

namespace App\Filament\Admin\Resources\Zones\Tables;

use App\Models\Zone;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ZonesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->searchable(['name', 'latitude', 'longitude'])
            ->searchPlaceholder('Search zones')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('latitude')
                    ->label('Latitude')
                    ->numeric(decimalPlaces: 7)
                    ->sortable(),

                TextColumn::make('longitude')
                    ->label('Longitude')
                    ->numeric(decimalPlaces: 7)
                    ->sortable(),

                TextColumn::make('radius_meters')
                    ->label('Radius')
                    ->suffix(' m')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('policy')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'strict' ? 'danger' : 'success')
                    ->sortable(),

                TextColumn::make('is_active')
                    ->label('Active')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),

                TextColumn::make('assigned_employees')
                    ->label('Assigned Employees')
                    ->getStateUsing(fn (Zone $record): int => $record->employees()->count())
                    ->sortable(false),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
