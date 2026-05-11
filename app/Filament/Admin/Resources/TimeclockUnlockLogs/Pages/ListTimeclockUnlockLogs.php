<?php

namespace App\Filament\Admin\Resources\TimeclockUnlockLogs\Pages;

use App\Filament\Admin\Resources\TimeclockUnlockLogs\TimeclockUnlockLogResource;
use Filament\Resources\Pages\ListRecords;

class ListTimeclockUnlockLogs extends ListRecords
{
    protected static string $resource = TimeclockUnlockLogResource::class;
}
