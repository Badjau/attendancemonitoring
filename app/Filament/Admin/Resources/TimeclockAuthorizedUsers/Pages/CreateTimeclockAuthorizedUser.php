<?php

namespace App\Filament\Admin\Resources\TimeclockAuthorizedUsers\Pages;

use App\Filament\Admin\Resources\TimeclockAuthorizedUsers\TimeclockAuthorizedUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTimeclockAuthorizedUser extends CreateRecord
{
    protected static string $resource = TimeclockAuthorizedUserResource::class;
}
