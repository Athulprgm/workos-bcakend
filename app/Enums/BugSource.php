<?php

namespace App\Enums;

enum BugSource: string
{
    case Manual = 'manual';
    case GoogleSheets = 'google_sheets';
    case Github = 'github';
}
