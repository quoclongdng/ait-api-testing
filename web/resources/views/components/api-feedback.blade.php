{{--
    Inline feedback for Alpine screens that call /api/v1 with fetch.
    Expects the enclosing x-data to expose: feedback = { type: 'success'|'warning'|'error'|null, message: string, errors: string[] }.
    Untrusted text (error.message, validation errors) is bound with x-text only, never as HTML.
--}}
<div
    x-show="feedback.type"
    x-cloak
    role="alert"
    {{ $attributes->merge(['class' => 'rounded-xl border p-4 text-xs space-y-2']) }}
    :class="{
        'border-emerald-500/30 bg-emerald-950/20 text-emerald-300': feedback.type === 'success',
        'border-amber-500/30 bg-amber-950/20 text-amber-300': feedback.type === 'warning',
        'border-rose-500/30 bg-rose-950/20 text-rose-300': feedback.type === 'error',
    }"
>
    <p class="font-semibold" x-text="feedback.message"></p>
    <ul x-show="feedback.errors.length > 0" class="list-disc space-y-1 pl-5 font-mono text-[11px]">
        <template x-for="(item, index) in feedback.errors" :key="index">
            <li x-text="item"></li>
        </template>
    </ul>
</div>
