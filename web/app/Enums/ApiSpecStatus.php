<?php

namespace App\Enums;

enum ApiSpecStatus: string
{
    case Uploaded = 'UPLOADED';
    case Parsed = 'PARSED';
    case ParseFailed = 'PARSE_FAILED';
}
