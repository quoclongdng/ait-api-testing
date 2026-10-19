{{--
    Endpoint <option>s of M08 grouped by spec version (S13-03): a project may hold several versions of
    the same API, so a flat list showed "[GET] /categories" twice with no way to tell them apart.
    Newest spec first. Expects $endpoints (with apiSpec loaded) and optional $selected (string).
--}}
@php
    $selected ??= null;
@endphp
@foreach ($endpoints->groupBy('api_spec_id') as $specEndpoints)
    @php
        $groupSpec = $specEndpoints->first()?->apiSpec;
        $groupLabel = $groupSpec
            ? 'Phiên bản '.$groupSpec->version_label.' ('.$groupSpec->source_filename.')'
            : 'Đặc tả không xác định';
    @endphp
    <optgroup label="{{ $groupLabel }}">
        @foreach ($specEndpoints as $ep)
            <option value="{{ $ep->id }}" @selected($selected !== null && $selected === (string) $ep->id)>
                [{{ is_object($ep->method) ? $ep->method->value : $ep->method }}] {{ $ep->path }}{{ $groupSpec ? ' — '.$groupSpec->version_label : '' }}
            </option>
        @endforeach
    </optgroup>
@endforeach
