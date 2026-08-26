<?php

declare(strict_types=1);

namespace App\Enums;

enum ComposerSourceAuthType: string
{
    case NONE = 'none';
    case BASIC = 'basic';
    case BEARER = 'bearer';
}
