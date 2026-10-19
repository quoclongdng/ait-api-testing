{{--
    Visible list of OpenAPI parse errors (S13-03): the reason used to exist only as a title tooltip
    (unreachable by touch/keyboard) or not at all. Expects $errorsList (mixed: list of strings,
    {message: ...} or anything JSON-encodable).
--}}
@php
    $parseMessages = collect(is_array($errorsList) ? $errorsList : [$errorsList])
        ->flatten()
        ->filter(fn (mixed $item): bool => $item !== null && $item !== '')
        ->map(fn (mixed $item): string => is_scalar($item) ? (string) $item : (string) json_encode($item, JSON_UNESCAPED_UNICODE))
        ->values();
@endphp
@if ($parseMessages->isNotEmpty())
    <ul data-testid="spec-parse-errors" class="list-disc space-y-1 pl-5 text-xs text-rose-200">
        @foreach ($parseMessages as $parseMessage)
            <li class="break-words">{{ $parseMessage }}</li>
        @endforeach
    </ul>
@endif
