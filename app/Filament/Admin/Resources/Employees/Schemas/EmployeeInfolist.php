<?php

namespace App\Filament\Admin\Resources\Employees\Schemas;

use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class EmployeeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                SpatieMediaLibraryImageEntry::make('registered_face')
                    ->label(__(null))
                    ->collection('employee-profile')
                    ->imageHeight(220)
                    ->imageWidth(220)
                    ->circular()
                    ->columnSpanFull()
                    ->placeholder('No registered face.'),

                TextEntry::make('employee_id')
                    ->label('Employee ID'),

                TextEntry::make('rfid_uid')
                    ->label('RFID UID')
                    ->placeholder('-'),

                TextEntry::make('branch')
                    ->label('Branch')
                    ->placeholder('-'),

                TextEntry::make('first_name')
                    ->label('First Name'),

                TextEntry::make('last_name')
                    ->label('Last Name'),

                TextEntry::make('middle_name')
                    ->label('Middle Name'),

                TextEntry::make('date_of_birth')
                    ->label('Date of Birth')
                    ->date('F d, Y')
                    ->placeholder('-'),

                TextEntry::make('position')
                    ->label('Position')
                    ->placeholder('-'),

                TextEntry::make('department.name')
                    ->label('Department')
                    ->placeholder('-'),
            ]);
    }
}
