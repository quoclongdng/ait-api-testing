<?php

namespace App\Enums;

enum TestVerdict: string
{
    case Pass = 'PASS';
    case Fail = 'FAIL';
    case Error = 'ERROR';
    case Skipped = 'SKIPPED';
}
