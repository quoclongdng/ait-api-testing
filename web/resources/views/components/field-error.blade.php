{{-- Server-side validation message under a form control; pair the control with the invalid directive. --}}
@props(['field', 'id', 'when' => true])
@if ($when && $errors->has($field))
    <p id="{{ $id }}" {{ $attributes->merge(['class' => 'mt-1 text-[11px] font-medium text-rose-300']) }}>{{ $errors->first($field) }}</p>
@endif
