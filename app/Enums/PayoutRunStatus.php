<?php

namespace App\Enums;

enum PayoutRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
}
