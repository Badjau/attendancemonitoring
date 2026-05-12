<?php

namespace App\Filament\Admin\Resources\Employees\RelationManagers;

use App\Enums\Attendance\Status;
use App\Filament\Admin\Resources\Attendances\AttendanceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;

class AttendancesRelationManager extends RelationManager
{
    protected static string $relationship = 'attendances';

    protected static ?string $relatedResource = AttendanceResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('attendance_date')
                    ->date()
                    ->sortable(),

                TextColumn::make('time_in')
                    ->dateTime('h:i A')
                    ->sortable(),

                TextColumn::make('time_out')
                    ->dateTime('h:i A')
                    ->sortable(),

                TextColumn::make('attendance_type')
                    ->label('Type')
                    ->badge()
                    ->searchable(),

                TextColumn::make('attendance_method')
                    ->label('Method')
                    ->badge()
                    ->searchable(),

                TextColumn::make('total_hours')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->searchable(),

                IconColumn::make('is_late')
                    ->boolean(),

                TextColumn::make('late_minutes')
                    ->numeric()
                    ->sortable(),

                IconColumn::make('is_undertime')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('undertime_minutes')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_overtime')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('overtime_minutes')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('overtime_status')
                    ->badge()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('location')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('latitude')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('longitude')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Status::class)
                    ->searchable(),

                DateRangeFilter::make('attendance_date')
                    ->autoApply()
                    ->linkedCalendars()
                    ->withIndicator(),
            ])
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
