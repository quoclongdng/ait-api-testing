<?php

namespace App\Enums;

enum ReportFormat: string
{
    case Json = 'json';
    case Csv = 'csv';
    case Html = 'html';

    public function contentType(): string
    {
        return match ($this) {
            self::Json => 'application/json; charset=UTF-8',
            self::Csv => 'text/csv; charset=UTF-8',
            self::Html => 'text/html; charset=UTF-8',
        };
    }
}
