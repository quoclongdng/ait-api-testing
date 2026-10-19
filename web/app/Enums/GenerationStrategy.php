<?php

namespace App\Enums;

enum GenerationStrategy: string
{
    case Rule = 'RULE';
    case Llm = 'LLM';
    case Hybrid = 'HYBRID';
}
