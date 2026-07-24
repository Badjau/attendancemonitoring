<?php

namespace App\Filament\Admin\Resources\Employees\Tables;

use App\Models\Employee;
use App\Support\AdminAccess;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['branches', 'latestZktecoFingerprintTemplate']))
            ->columns([
                TextColumn::make('employee_id')
                    ->label('Employee ID')
                    ->searchable()
                    ->sortable(),

                ImageColumn::make('latestZktecoFingerprintTemplate.fingerprint_image_base64')
                    ->label('Fingerprint')
                    ->state(function (Employee $record): ?string {
                        $image = $record->latestZktecoFingerprintTemplate?->fingerprint_image_base64;

                        return filled($image)
                            ? 'data:image/png;base64,'.$image
                            : null;
                    })
                    ->imageHeight(52)
                    ->imageWidth(52)
                    ->square()
                    ->tooltip(fn (Employee $record): string => $record->latestZktecoFingerprintTemplate
                        ? 'Fingerprint enrolled'
                        : 'No Fingerprint enrolled')
                    ->placeholder('Pending Enrollment.'),

                TextColumn::make('rfid_uid')
                    ->label('RFID UID')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('branches.name')
                    ->label('Branches')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('first_name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('last_name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('middle_name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('date_of_birth')
                    ->date('F d, Y')
                    ->sortable(),

                TextColumn::make('position')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('department.name')
                    ->label('Department')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('department_id')
                    ->label('Department')
                    ->relationship('department', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('branches')
                    ->label('Branch')
                    ->relationship('branches', 'name')
                    ->searchable(),

                SelectFilter::make('position'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalDescription('Deleting employees wipes ALL historic data bundled with each employee, including attendance history, registered face images, face vectors, fingerprint data, RFID access, and related records.')
                        ->visible(fn (): bool => AdminAccess::hasAnyAdminAccess()),
                ])
                    ->visible(fn (): bool => AdminAccess::hasAnyAdminAccess()),
            ]);
    }
}
