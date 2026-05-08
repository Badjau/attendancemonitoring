<?php

namespace App\Filament\Admin\Pages;

use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('date')
                    ->label('Attendance date')
                    ->default(today())
                    ->native(false)
                    ->suffixIcon(Heroicon::Calendar)
                    ->closeOnDateSelection(),
            ]);
    }

    public function persistsFiltersInSession(): bool
    {
        return false;
    }
}
