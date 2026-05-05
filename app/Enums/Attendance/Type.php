<?php

namespace App\Enums\Attendance;

enum Type: string
{
    case TimeIn = 'time-in';
    case TimeOut = 'time-out';
}
