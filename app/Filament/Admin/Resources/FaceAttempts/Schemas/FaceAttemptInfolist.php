<?php

namespace App\Filament\Admin\Resources\FaceAttempts\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FaceAttemptInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Decision')
                    ->schema([
                        TextEntry::make('decision')->badge(),
                        TextEntry::make('reason_code')->badge()->placeholder('-'),
                        IconEntry::make('suspicious')->boolean(),
                        TextEntry::make('risk_score')->numeric(decimalPlaces: 4)->placeholder('-'),
                        TextEntry::make('match_score')->numeric(decimalPlaces: 4)->placeholder('-'),
                        TextEntry::make('liveness_score')->numeric(decimalPlaces: 4)->placeholder('-'),
                        TextEntry::make('quality_score')->numeric(decimalPlaces: 4)->placeholder('-'),
                        TextEntry::make('frame_count')->label('Frames'),
                        TextEntry::make('usable_frame_count')->label('Usable Frames'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('Subject')
                    ->schema([
                        TextEntry::make('employee.name')->label('Employee')->placeholder('-'),
                        TextEntry::make('employee.employee_id')->label('Employee ID')->copyable()->placeholder('-'),
                        TextEntry::make('attendance.id')->label('Linked Attendance')->placeholder('-'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('Device')
                    ->schema([
                        TextEntry::make('device_id')->placeholder('-'),
                        TextEntry::make('session_id')->placeholder('-'),
                        TextEntry::make('ip_address')->label('IP Address')->placeholder('-'),
                        TextEntry::make('user_agent')->label('User Agent')->placeholder('-')->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('Evidence')
                    ->schema([
                        SpatieMediaLibraryImageEntry::make('evidence')
                            ->collection('face-attempt-evidence')
                            ->imageHeight(360)
                            ->imageWidth('100%')
                            ->extraImgAttributes(['class' => 'rounded-lg object-cover']),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
