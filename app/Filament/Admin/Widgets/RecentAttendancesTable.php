<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Attendances\AttendanceResource;
use App\Models\Attendance;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class RecentAttendancesTable extends TableWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function updatedPageFilters(): void
    {
        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Attendance records')
            ->query(fn (): Builder => Attendance::query()
                ->with('employee')
                ->whereDate('attendance_date', Carbon::parse($this->pageFilters['date'] ?? today()))
                ->latest('attendance_date')
                ->latest('time_in'))
            ->columns([
                TextColumn::make('employee.name')
                    ->label('Employee')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('employee', function (Builder $query) use ($search): Builder {
                            return $query
                                ->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                    }),

                TextColumn::make('attendance_date')
                    ->label('Date')
                    ->date('M j, Y')
                    ->sortable(),

                TextColumn::make('time_in')
                    ->label('Time In')
                    ->dateTime('h:i A')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('time_out')
                    ->label('Time Out')
                    ->dateTime('h:i A')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge(),

                IconColumn::make('is_late')
                    ->label('Late')
                    ->boolean(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (Attendance $record): string => AttendanceResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultPaginationPageOption(5);
    }
}
