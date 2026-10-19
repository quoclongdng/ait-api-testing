@props(['value' => null, 'format' => 'd/m/Y H:i', 'fallback' => '—'])
{{-- Every server-rendered timestamp goes through ait.display_timezone so M11/M12/M13 and exports agree. --}}
@if ($value instanceof \DateTimeInterface)<time datetime="{{ \Illuminate\Support\Carbon::instance($value)->toIso8601String() }}" title="{{ config('ait.display_timezone') }}">{{ \Illuminate\Support\Carbon::instance($value)->setTimezone((string) config('ait.display_timezone'))->format($format) }}</time>@else{{ $fallback }}@endif
