<?php

namespace App\Filament\Admin\Resources\Employees\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee_id')
                    ->label('Employee ID')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('first_name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('last_name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('middle_name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('date_of_birth')
                    ->date('F d, Y')
                    ->sortable(),

                TextColumn::make('position')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('department.name')
                    ->label('Department')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('department_id')
                    ->label('Department')
                    ->relationship('department', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('position')
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
