<?php

namespace App\Filament\Admin\Resources\Zones\Pages;

use App\Filament\Admin\Resources\Zones\ZoneResource;
use Filament\Resources\Pages\CreateRecord;

class CreateZone extends CreateRecord
{
    protected static string $resource = ZoneResource::class;

    protected array $assignments = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->assignments = $data['assignments'] ?? [];
        unset($data['assignments'], $data['location']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->employees()->sync($this->assignmentSyncPayload());
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
