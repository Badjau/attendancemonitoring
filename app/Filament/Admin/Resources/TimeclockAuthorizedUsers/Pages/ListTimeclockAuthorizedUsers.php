<?php

namespace App\Filament\Admin\Resources\TimeclockAuthorizedUsers\Pages;

use App\Filament\Admin\Resources\TimeclockAuthorizedUsers\TimeclockAuthorizedUserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTimeclockAuthorizedUsers extends ListRecords
{
    protected static string $resource = TimeclockAuthorizedUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
