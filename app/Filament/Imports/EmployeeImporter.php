<?php

namespace App\Filament\Imports;

use App\Models\Employee;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class EmployeeImporter extends Importer
{
    protected static ?string $model = Employee::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('employee_id')
                ->label('Employee ID')
                ->requiredMapping()
                ->rules(['required', 'string', 'max:255'])
                ->example('EMP-0001')
                ->guess(['employee number', 'employee no', 'id number']),

            ImportColumn::make('rfid_uid')
                ->label('RFID UID')
                ->rules(['nullable', 'string', 'max:255'])
                ->ignoreBlankState()
                ->example('0003097269')
                ->guess(['rfid', 'rfid id', 'card id']),

            ImportColumn::make('password')
                ->label('Password')
                ->rules(['nullable', 'string', 'max:255'])
                ->ignoreBlankState()
                ->sensitive()
                ->guess(['pin', 'keypad password', 'employee password']),

            ImportColumn::make('first_name')
                ->label('First Name')
                ->requiredMapping()
                ->rules(['required', 'string', 'max:255'])
                ->example('James')
                ->guess(['firstname', 'given name']),

            ImportColumn::make('last_name')
                ->label('Last Name')
                ->requiredMapping()
                ->rules(['required', 'string', 'max:255'])
                ->example('Philip')
                ->guess(['lastname', 'surname', 'family name']),

            ImportColumn::make('middle_name')
                ->label('Middle Name')
                ->rules(['nullable', 'string', 'max:255'])
                ->ignoreBlankState()
                ->example('Santos')
                ->guess(['middlename', 'middle initial']),

            ImportColumn::make('date_of_birth')
                ->label('Date of Birth')
                ->requiredMapping()
                ->rules(['required', 'date'])
                ->example('1998-05-15')
                ->guess(['birthdate', 'birthday', 'dob']),

            ImportColumn::make('position')
                ->label('Position')
                ->requiredMapping()
                ->rules(['required', 'string', 'max:255'])
                ->example('IT Staff')
                ->guess(['job title', 'designation']),

            ImportColumn::make('department')
                ->label('Department')
                ->requiredMapping()
                ->relationship('department', 'name')
                ->rules(['required', 'string', 'max:255'])
                ->example('IT')
                ->guess(['department name', 'team']),

            ImportColumn::make('branch')
                ->label('Branch')
                ->rules(['nullable', 'string', 'max:255'])
                ->ignoreBlankState()
                ->example('Esquivel')
                ->guess(['site', 'office', 'location', 'branch name']),
        ];
    }

    public function resolveRecord(): ?Model
    {
        $employeeId = $this->data['employee_id'] ?? null;

        if (blank($employeeId)) {
            return new Employee;
        }

        return Employee::query()->firstOrNew([
            'employee_id' => $employeeId,
        ]);
    }

    public function beforeValidate(): void
    {
        $this->data['employee_id'] = $this->sanitizeText($this->data['employee_id'] ?? null);
        $this->data['rfid_uid'] = $this->sanitizeText($this->data['rfid_uid'] ?? null);
        $this->data['first_name'] = $this->sanitizeText($this->data['first_name'] ?? null);
        $this->data['last_name'] = $this->sanitizeText($this->data['last_name'] ?? null);
        $this->data['middle_name'] = $this->sanitizeText($this->data['middle_name'] ?? null);
        $this->data['position'] = $this->sanitizeText($this->data['position'] ?? null);
        $this->data['department'] = $this->sanitizeText($this->data['department'] ?? null);
        $this->data['branch'] = $this->sanitizeText($this->data['branch'] ?? null);
    }

    public function getValidationRules(): array
    {
        $rules = parent::getValidationRules();

        $rules['employee_id'][] = Rule::unique('employees', 'employee_id')
            ->ignore($this->record?->getKey());

        if (filled($this->data['rfid_uid'] ?? null)) {
            $rules['rfid_uid'][] = Rule::unique('employees', 'rfid_uid')
                ->ignore($this->record?->getKey());
        }

        return $rules;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Employee import completed. '.number_format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }

    private function sanitizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
