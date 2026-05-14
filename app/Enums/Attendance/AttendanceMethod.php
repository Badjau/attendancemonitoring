<?php

namespace App\Enums\Attendance;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum AttendanceMethod: string implements HasIcon, HasLabel
{
    case RFID = 'rfid';
    case KEYPAD = 'keypad';
    case FINGERPRINT = 'fingerprint';
    case FACE = 'face';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::RFID => 'RFID',
            self::KEYPAD => 'Keypad',
            self::FINGERPRINT => 'Fingerprint',
            self::FACE => 'Facial Recognition',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::RFID => 'heroicon-o-identification',
            self::KEYPAD => 'heroicon-o-cursor-arrow-rays',
            self::FINGERPRINT => 'heroicon-o-finger-print',
            self::FACE => 'heroicon-o-face-smile',
        };
    }
}
