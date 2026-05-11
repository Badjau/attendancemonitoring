<?php

namespace App\Filament\Admin\Resources\TimeclockUnlockLogs\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TimeclockUnlockLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('authorizedUser.employee.name')
                    ->label('Employee')
                    ->searchable(['employees.first_name', 'employees.last_name'])
                    ->placeholder('-'),

                TextColumn::make('authorizedUser.employee.employee_id')
                    ->label('Employee ID')
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('method')
                    ->badge()
                    ->searchable(),

                TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('unlocked_at')
                    ->label('Unlocked At')
                    ->dateTime('M d, Y h:i A')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime('M d, Y h:i A')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('unlocked_at', 'desc')
            ->filters([
                SelectFilter::make('method')
                    ->options([
                        'password' => 'Password',
                        'rfid' => 'RFID',
                    ]),

                SelectFilter::make('timeclock_authorized_user_id')
                    ->label('Unlocker')
                    ->relationship('authorizedUser.employee', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => "{$record->employee_id} - {$record->name}")
                    ->preload()
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
