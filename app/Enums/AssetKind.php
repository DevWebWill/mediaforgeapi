<?php

namespace App\Enums;

enum AssetKind: string
{
    case Original = 'original';
    case Result = 'result';
    case Thumbnail = 'thumbnail';
}
