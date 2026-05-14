<?php

namespace App\Filament\Admin\Resources\Attendances\Pages;

use App\Filament\Admin\Resources\Attendances\AttendanceResource;
use Filament\Resources\Pages\ListRecords;

class ListAttendances extends ListRecords
{
    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
