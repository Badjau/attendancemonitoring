<?php

namespace App\Filament\Admin\Resources\TimeclockAuthorizedUsers\Pages;

use App\Filament\Admin\Resources\TimeclockAuthorizedUsers\TimeclockAuthorizedUserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTimeclockAuthorizedUser extends EditRecord
{
    protected static string $resource = TimeclockAuthorizedUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
