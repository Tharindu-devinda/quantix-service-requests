<?php

namespace App\Enums;

enum RequestPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}