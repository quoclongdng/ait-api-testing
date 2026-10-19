{{--
    Summary of server-side validation errors rendered inside a form or modal (not only in the layout
    banner, which a modal backdrop covers). Shown only when "when" is true, i.e. the errors belong to
    this form.
--}}
@props(['when' => true, 'title' => 'Chưa lưu được, vui lòng kiểm tra lại:'])
@if ($when && $errors->any())
    <div role="alert" data-testid="form-errors" {{ $attributes->merge(['class' => 'rounded-xl border border-rose-500/40 bg-rose-950/60 p-3 text-xs text-rose-200']) }}>
        <p class="font-semibold">{{ $title }}</p>
        <ul class="mt-1 list-inside list-disc space-y-0.5 text-rose-300">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
