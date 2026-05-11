<?php

namespace App\Filament\Admin\Resources\TimeclockUnlockLogs\Schemas;

use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TimeclockUnlockLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Unlocker')
                    ->schema([
                        TextEntry::make('authorizedUser.employee.name')
                            ->label('Employee')
                            ->weight('bold')
                            ->placeholder('-'),

                        TextEntry::make('authorizedUser.employee.employee_id')
                            ->label('Employee ID')
                            ->copyable()
                            ->placeholder('-'),

                        TextEntry::make('authorizedUser.employee.rfid_uid')
                            ->label('RFID UID')
                            ->copyable()
                            ->placeholder('-'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('Unlock Details')
                    ->schema([
                        TextEntry::make('method')
                            ->badge(),

                        TextEntry::make('unlocked_at')
                            ->label('Unlocked At')
                            ->dateTime('M d, Y h:i A')
                            ->placeholder('-'),

                        TextEntry::make('ip_address')
                            ->label('IP Address')
                            ->copyable()
                            ->placeholder('-'),

                        TextEntry::make('user_agent')
                            ->label('User Agent')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('Audit Photo')
                    ->schema([
                        SpatieMediaLibraryImageEntry::make('audit_photo')
                            ->label('Captured Photo')
                            ->collection('unlock-audit-image')
                            ->imageHeight(320)
                            ->imageWidth('100%')
                            ->extraImgAttributes(['class' => 'rounded-lg object-cover']),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
