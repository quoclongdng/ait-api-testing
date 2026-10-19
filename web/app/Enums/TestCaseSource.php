<?php

namespace App\Enums;

enum TestCaseSource: string
{
    case Rule = 'RULE';
    case Llm = 'LLM';
    case Hybrid = 'HYBRID';
    case Manual = 'MANUAL';
}
