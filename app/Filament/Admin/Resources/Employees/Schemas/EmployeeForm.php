<?php

namespace App\Filament\Admin\Resources\Employees\Schemas;

use App\Models\Employee;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Wizard\Step::make('Employee Information')
                        ->schema([
                            Select::make('department_id')
                                ->label('Department')
                                ->relationship('department', 'name')
                                ->required(),

                            TextInput::make('branch')
                                ->required()
                                ->maxLength(255)
                                ->datalist(Employee::BRANCHES),

                            TextInput::make('first_name')
                                ->required(),

                            TextInput::make('last_name')
                                ->required(),

                            TextInput::make('middle_name')
                                ->nullable(),

                            DatePicker::make('date_of_birth')
                                ->required(),

                            TextInput::make('position')
                                ->required(),
                        ])
                        ->afterValidation(function ($livewire): void {
                            if (! method_exists($livewire, 'createFromEmployeeInformationStep')) {
                                return;
                            }

                            $livewire->createFromEmployeeInformationStep();
                        }),

                    Wizard\Step::make('RFID')
                        ->schema([
                            TextInput::make('rfid_uid')
                                ->label('RFID UIDs')
                                ->rules(['numeric', 'min:1'])
                                ->autofocus()
                                ->unique(ignoreRecord: true)
                                ->helperText('Used for RFID attendance and timeclock unlock.'),
                        ]),

                    Wizard\Step::make('Keypad')
                        ->schema([
                            TextInput::make('password')
                                ->label('Keypad Password')
                                ->password()
                                ->revealable()
                                ->numeric()
                                ->maxLength(255)
                                ->autofocus()
                                ->dehydrated(fn (?string $state): bool => filled($state))
                                ->helperText('Used for manual keypad attendance. Leave blank to keep the current password.'),

                            Html::make(fn (): HtmlString => new HtmlString(
                                view('filament.admin.employees.keypad-input', [
                                    'statePath' => 'data.password',
                                ])->render(),
                            )),
                        ]),

                    Wizard\Step::make('Fingerprint')
                        ->schema([
                            Text::make('Save this employee before enrolling a fingerprint.')
                                ->visible(fn (?Employee $record = null): bool => blank($record?->getKey())),

                            Html::make(fn (Employee $record): HtmlString => new HtmlString(
                                view('filament.admin.employees.fingerprint-summary', [
                                    'employee' => $record,
                                ])->render(),
                            ))
                                ->visible(fn (?Employee $record = null): bool => filled($record?->getKey())),

                            Actions::make([
                                Action::make('enrollFingerprint')
                                    ->label('Enroll fingerprint')
                                    ->icon(Heroicon::OutlinedFingerPrint)
                                    ->modalHeading(fn (Employee $record): string => 'Enroll fingerprint for '.$record->name)
                                    ->modalSubmitAction(false)
                                    ->modalCancelActionLabel('Close')
                                    ->modalWidth('5xl')
                                    ->modalContent(fn (Employee $record) => view('filament.admin.employees.fingerprint-enrollment', [
                                        'employee' => $record,
                                    ])),
                            ])
                                ->visible(fn (?Employee $record = null): bool => filled($record?->getKey())),
                        ]),

                    Wizard\Step::make('Facial Recognition')
                        ->schema([
                            Text::make('Save this employee before registering a face.')
                                ->visible(fn (?Employee $record = null): bool => blank($record?->getKey())),

                            Html::make(fn (Employee $record): HtmlString => new HtmlString(
                                view('filament.admin.employees.face-summary', [
                                    'employee' => $record,
                                ])->render(),
                            ))
                                ->visible(fn (?Employee $record = null): bool => filled($record?->getKey())),

                            Actions::make([
                                Action::make('registerFace')
                                    ->label('Register face')
                                    ->icon(Heroicon::OutlinedCamera)
                                    ->modalHeading(fn (Employee $record): string => 'Register face for '.$record->name)
                                    ->modalSubmitAction(false)
                                    ->modalCancelActionLabel('Close')
                                    ->modalWidth('5xl')
                                    ->modalContent(fn (Employee $record) => view('filament.admin.employees.face-registration', [
                                        'employee' => $record,
                                    ])),
                            ])
                                ->visible(fn (?Employee $record = null): bool => filled($record?->getKey())),
                        ]),
                ])
                    ->columnSpanFull()
                    ->columns(),
            ]);
    }
}
