<?php

namespace App\Filament\Admin\Resources\Employees\Pages;

use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Support\AdminAccess;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->modalDescription('Deleting this employee wipes ALL historic data bundled with the employee, including attendance history, registered face images, face vectors, fingerprint data, RFID access, and related records.')
                ->visible(fn (): bool => AdminAccess::hasAnyAdminAccess()),
        ];
    }
}
