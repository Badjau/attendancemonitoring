<?php

namespace App\Filament\Admin\Resources\TimeclockUnlockLogs\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TimeclockUnlockLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('authorizedUser.employee.name')
                    ->label('Employee')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('authorizedUser.employee', function (Builder $query) use ($search): Builder {
                            return $query->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                    })
                    ->placeholder('-'),

                TextColumn::make('authorizedUser.employee.employee_id')
                    ->label('Employee ID')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('authorizedUser.employee', function (Builder $query) use ($search): Builder {
                            return $query->where('employee_id', 'like', "%{$search}%");
                        });
                    })
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
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('authorizedUser.employee'));
    }
}
