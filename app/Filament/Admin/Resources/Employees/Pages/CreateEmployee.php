<?php

namespace App\Filament\Admin\Resources\Employees\Pages;

use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    public function createFromEmployeeInformationStep(): void
    {
        $this->authorizeAccess();

        if ($this->record?->exists) {
            return;
        }

        $data = $this->form->getRawState();

        $this->record = Employee::create([
            'employee_id' => $this->generateEmployeeId(),
            'department_id' => $data['department_id'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'middle_name' => $data['middle_name'],
            'date_of_birth' => $data['date_of_birth'],
            'position' => $data['position'],
        ]);

        $this->form->model($this->record);

        Notification::make()
            ->success()
            ->title('Employee information saved.')
            ->send();
    }

    protected function handleRecordCreation(array $data): Model
    {
        if (! $this->record?->exists) {
            $data['employee_id'] ??= $this->generateEmployeeId();

            return parent::handleRecordCreation($data);
        }

        $this->record->fill($data);
        $this->record->save();

        return $this->record;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->record?->wasRecentlyCreated
            ? parent::getCreatedNotificationTitle()
            : 'Employee saved.';
    }

    private function generateEmployeeId(): string
    {
        $nextId = Employee::query()
            ->pluck('employee_id')
            ->filter(fn (string $employeeId): bool => ctype_digit($employeeId))
            ->map(fn (string $employeeId): int => (int) $employeeId)
            ->max() + 1;

        do {
            $employeeId = str_pad((string) $nextId, 10, '0', STR_PAD_LEFT);
            $nextId++;
        } while (Employee::query()->where('employee_id', $employeeId)->exists());

        return $employeeId;
    }
}
