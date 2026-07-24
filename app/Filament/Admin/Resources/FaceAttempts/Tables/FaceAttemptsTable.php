<?php

namespace App\Filament\Admin\Resources\FaceAttempts\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FaceAttemptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.name')
                    ->label('Employee')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('employee', function (Builder $query) use ($search): Builder {
                            return $query->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('employee_id', 'like', "%{$search}%");
                        });
                    })
                    ->placeholder('-'),
                TextColumn::make('candidate_employee_identifier')
                    ->label('Candidate')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('decision')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'accept' => 'success',
                        'retry' => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('reason_code')
                    ->badge()
                    ->placeholder('-'),
                IconColumn::make('suspicious')
                    ->boolean(),
                TextColumn::make('risk_score')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('frame_count')
                    ->label('Frames')
                    ->sortable(),
                TextColumn::make('attempted_at')
                    ->dateTime('M d, Y h:i A')
                    ->sortable(),
            ])
            ->defaultSort('attempted_at', 'desc')
            ->filters([
                TernaryFilter::make('suspicious'),
                TernaryFilter::make('fallback_used'),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee', 'attendance']));
    }
}
