<?php

declare(strict_types=1);

namespace App\Feedback\Measurements;

enum ReadStatus: string
{
    case Reading = 'reading';
    case Complete = 'complete';
    case Partial = 'partial';
    case Unavailable = 'unavailable';
    case Incompatible = 'incompatible';
    case Failed = 'failed';
}
