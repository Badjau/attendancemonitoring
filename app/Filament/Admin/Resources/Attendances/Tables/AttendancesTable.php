<?php

namespace App\Filament\Admin\Resources\Attendances\Tables;

use App\Enums\Attendance\Status;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;
use pxlrbt\FilamentExcel\Actions\ExportBulkAction;

class AttendancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.first_name')
                    ->label('Employee')
                    ->formatStateUsing(fn($record) => "{$record->employee->first_name} {$record->employee->last_name}")
                    ->searchable(query: function (Builder $query, string $search) {
                        $query->whereHas('employee', function (Builder $query) use ($search) {
                            $query->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                    }),

                TextColumn::make('rfid_uid')
                    ->label('RFID')
                    ->searchable(),

                TextColumn::make('attendance_date')
                    ->date()
                    ->sortable(),

                TextColumn::make('time_in')
                    ->dateTime('h:i A')
                    ->sortable(),

                TextColumn::make('time_out')
                    ->dateTime('h:i A')
                    ->sortable(),

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
                    ->options(Status::class),

                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship(
                        'employee',
                        'first_name',
                        fn($query) => $query->orderBy('first_name')->orderBy('last_name')
                    )
                    ->getOptionLabelFromRecordUsing(fn($record) => "{$record->first_name} {$record->last_name}")
                    ->preload()
                    ->searchable(),

                DateRangeFilter::make('attendance_date')
                    ->autoApply()
                    ->linkedCalendars()
                    ->withIndicator()

            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),

                    ExportBulkAction::make()
                ]),
            ]);
    }
}
