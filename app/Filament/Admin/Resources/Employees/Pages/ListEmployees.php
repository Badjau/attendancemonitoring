<?php

namespace App\Filament\Admin\Resources\Employees\Pages;

use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Imports\EmployeeImporter;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()
                ->importer(EmployeeImporter::class)
                ->icon('heroicon-o-arrow-down-tray'),

            CreateAction::make(),
        ];
    }
}
