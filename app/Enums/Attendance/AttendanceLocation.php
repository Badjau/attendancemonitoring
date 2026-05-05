<?php

namespace App\Enums\Attendance;

enum AttendanceLocation: string
{
    case Office = 'office';
    case Remote = 'remote';
    case Field = 'field';
}
