<?php

namespace App\Filament\Admin\Resources\Attendances\Schemas;

use App\Enums\Attendance\OvertimeStatus;
use App\Enums\Attendance\Status;
use App\Models\Attendance;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AttendanceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Employee')
                    ->schema([
                        TextEntry::make('employee_name')
                            ->label('Name')
                            ->state(fn (Attendance $record): string => trim("{$record->employee?->first_name} {$record->employee?->middle_name} {$record->employee?->last_name}") ?: '-')
                            ->weight('bold'),

                        TextEntry::make('employee.employee_id')
                            ->label('Employee ID')
                            ->placeholder('-')
                            ->copyable(),

                        TextEntry::make('rfid_uid')
                            ->label('Submitted ID / RFID')
                            ->placeholder('-')
                            ->copyable(),

                        TextEntry::make('attendance_type')
                            ->label('Type')
                            ->badge(),

                        TextEntry::make('attendance_method')
                            ->label('Method')
                            ->badge()
                            ->placeholder('-'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Time Log')
                    ->schema([
                        TextEntry::make('attendance_date')
                            ->label('Attendance Date')
                            ->date('M d, Y'),

                        TextEntry::make('time_in')
                            ->label('Time In')
                            ->dateTime('M d, Y h:i A')
                            ->placeholder('-'),

                        TextEntry::make('time_out')
                            ->label('Time Out')
                            ->dateTime('M d, Y h:i A')
                            ->placeholder('-'),

                        TextEntry::make('total_hours')
                            ->label('Total Hours')
                            ->state(fn (Attendance $record): string => $record->total_hours === null ? '-' : number_format((float) $record->total_hours, 2).' hrs'),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),

                Section::make('Status')
                    ->schema([
                        TextEntry::make('status')
                            ->badge(),

                        IconEntry::make('is_late')
                            ->label('Late')
                            ->boolean(),

                        TextEntry::make('late_minutes')
                            ->label('Late Minutes')
                            ->state(fn (Attendance $record): string => "{$record->late_minutes} min"),

                        IconEntry::make('is_undertime')
                            ->label('Undertime')
                            ->boolean(),

                        TextEntry::make('undertime_minutes')
                            ->label('Undertime Minutes')
                            ->state(fn (Attendance $record): string => "{$record->undertime_minutes} min"),

                        IconEntry::make('is_overtime')
                            ->label('Overtime')
                            ->boolean(),

                        TextEntry::make('overtime_minutes')
                            ->label('Overtime Minutes')
                            ->state(fn (Attendance $record): string => "{$record->overtime_minutes} min"),

                        TextEntry::make('overtime_status')
                            ->label('Overtime Status')
                            ->badge()
                            ->placeholder('-'),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),

                Section::make('Captured Photos')
                    ->schema([
                        SpatieMediaLibraryImageEntry::make('time_in_photo')
                            ->label('Time In Photo')
                            ->collection('time-in-image')
                            ->imageHeight(220)
                            ->imageWidth('100%')
                            ->extraImgAttributes(['class' => 'rounded-lg object-cover']),

                        SpatieMediaLibraryImageEntry::make('time_out_photo')
                            ->label('Time Out Photo')
                            ->collection('time-out-image')
                            ->imageHeight(220)
                            ->imageWidth('100%')
                            ->extraImgAttributes(['class' => 'rounded-lg object-cover']),

                        SpatieMediaLibraryImageEntry::make('legacy_attendance_photo')
                            ->label('Legacy Attendance Photo')
                            ->collection('attendance-image')
                            ->imageHeight(220)
                            ->imageWidth('100%')
                            ->extraImgAttributes(['class' => 'rounded-lg object-cover'])
                            ->visible(fn (Attendance $record): bool => $record->hasMedia('attendance-image')),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('Location & Remarks')
                    ->schema([
                        TextEntry::make('location')
                            ->placeholder('-'),

                        TextEntry::make('latitude')
                            ->numeric()
                            ->placeholder('-'),

                        TextEntry::make('longitude')
                            ->numeric()
                            ->placeholder('-'),

                        TextEntry::make('remarks')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('System')
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime('M d, Y h:i A')
                            ->placeholder('-'),

                        TextEntry::make('updated_at')
                            ->label('Last Updated')
                            ->dateTime('M d, Y h:i A')
                            ->placeholder('-'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
