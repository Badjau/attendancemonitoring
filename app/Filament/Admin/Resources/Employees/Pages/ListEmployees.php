<?php

namespace App\Filament\Admin\Resources\Employees\Pages;

use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Imports\EmployeeImporter;
use App\Services\FaceServiceClient;
use App\Support\AdminAccess;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()
                ->importer(EmployeeImporter::class)
                ->icon('heroicon-o-arrow-down-tray'),

            Action::make('rebuildFaceCache')
                ->label('Rebuild Face Cache')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Rebuild face recognition cache')
                ->modalDescription('This replaces the face service SQLite cache with the current Laravel face embeddings.')
                ->visible(fn (): bool => AdminAccess::hasAnyAdminAccess())
                ->action(function (): void {
                    try {
                        $payload = app(FaceServiceClient::class)->rebuildCache()->json();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Face cache rebuild failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Face cache rebuilt')
                        ->body(sprintf(
                            '%s embeddings for %s employees are now cached.',
                            $payload['embedding_count'] ?? 0,
                            $payload['employee_count'] ?? 0,
                        ))
                        ->success()
                        ->send();
                }),

            CreateAction::make(),
        ];
    }
}
