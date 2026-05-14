<?php

namespace App\Filament\Admin\Resources\Employees\Pages;

use App\Filament\Admin\Resources\Attendances\Widgets\AttendanceOverview;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //            Action::make('registerFace')
            //                ->label('Register face')
            //                ->icon(Heroicon::OutlinedCamera)
            //                ->modalHeading(fn (): string => 'Register face for '.$this->record->name)
            //                ->modalSubmitAction(false)
            //                ->modalCancelActionLabel('Close')
            //                ->modalWidth('5xl')
            //                ->modalContent(fn () => view('filament.admin.employees.face-registration', [
            //                    'employee' => $this->record,
            //                ])),
            //
            //            Action::make('enrollFingerprint')
            //                ->label('Enroll fingerprint')
            //                ->icon(Heroicon::OutlinedFingerPrint)
            //                ->modalHeading(fn (): string => 'Enroll fingerprint for '.$this->record->name)
            //                ->modalSubmitAction(false)
            //                ->modalCancelActionLabel('Close')
            //                ->modalContent(fn () => view('filament.admin.employees.fingerprint-enrollment', [
            //                    'employee' => $this->record,
            //                ])),

            EditAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AttendanceOverview::class,
        ];
    }
}
