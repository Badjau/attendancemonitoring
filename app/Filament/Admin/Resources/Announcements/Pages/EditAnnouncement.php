<?php

namespace App\Filament\Admin\Resources\Announcements\Pages;

use App\Enums\Announcement\Status;
use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use Carbon\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['status'] ?? null) === Status::PUBLISHED->value && blank($data['published_at'] ?? null)) {
            $data['published_at'] = Carbon::now();
        }

        return $data;
    }
}
