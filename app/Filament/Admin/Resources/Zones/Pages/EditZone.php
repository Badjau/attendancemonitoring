<?php

namespace App\Filament\Admin\Resources\Zones\Pages;

use App\Filament\Admin\Resources\Zones\ZoneResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditZone extends EditRecord
{
    protected static string $resource = ZoneResource::class;

    protected array $assignments = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['assignments'] = $this->getRecord()
            ->employees()
            ->get()
            ->map(fn (Model $employee): array => [
                'employee_ids' => [$employee->id],
                'is_temporary' => (bool) $employee->pivot->is_temporary,
                'effective_date' => $employee->pivot->effective_date,
                'expiry_date' => $employee->pivot->expiry_date,
            ])
            ->values()
            ->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->assignments = $data['assignments'] ?? [];
        unset($data['assignments'], $data['location']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->getRecord()->employees()->sync($this->assignmentSyncPayload());
    }

    private function assignmentSyncPayload(): array
    {
        $payload = [];

        foreach ($this->assignments as $assignment) {
            foreach (($assignment['employee_ids'] ?? []) as $employeeId) {
                $payload[$employeeId] = [
                    'is_temporary' => $assignment['is_temporary'] ?? false,
                    'effective_date' => ($assignment['is_temporary'] ?? false) ? ($assignment['effective_date'] ?? null) : null,
                    'expiry_date' => ($assignment['is_temporary'] ?? false) ? ($assignment['expiry_date'] ?? null) : null,
                ];
            }
        }

        return $payload;
    }
}
