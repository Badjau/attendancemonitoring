<?php

namespace App\Filament\Admin\Resources\Attendances\Schemas;

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AttendanceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('employee.id')
                    ->label('Employee'),

                TextEntry::make('rfid_uid')
                    ->placeholder('-'),

                TextEntry::make('attendance_date')
                    ->date(),

                TextEntry::make('time_in')
                    ->dateTime()
                    ->placeholder('-'),

                TextEntry::make('time_out')
                    ->dateTime()
                    ->placeholder('-'),

                TextEntry::make('total_hours')
                    ->numeric()
                    ->placeholder('-'),

                TextEntry::make('status')
                    ->badge(),

                IconEntry::make('is_late')
                    ->boolean(),

                TextEntry::make('late_minutes')
                    ->numeric(),

                IconEntry::make('is_undertime')
                    ->boolean(),

                TextEntry::make('undertime_minutes')
                    ->numeric(),

                IconEntry::make('is_overtime')
                    ->boolean(),

                TextEntry::make('overtime_minutes')
                    ->numeric(),

                TextEntry::make('overtime_status')
                    ->badge()
                    ->placeholder('-'),

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

                TextEntry::make('recorded_by')
                    ->numeric()
                    ->placeholder('-'),

                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),

                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),

                SpatieMediaLibraryImageEntry::make('attendance_image')
                    ->collection('attendance-image')
            ]);
    }
}
