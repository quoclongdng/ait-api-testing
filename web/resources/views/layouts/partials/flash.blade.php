@if (session('success'))
    <div x-data="{ show: true }" x-show="show" role="status" data-testid="flash-success" class="mb-4 flex items-center justify-between p-4 rounded-xl bg-emerald-950/80 border border-emerald-500/40 text-emerald-200 shadow-lg">
        <div class="flex items-center gap-3">
            <svg aria-hidden="true" class="w-5 h-5 text-emerald-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            <span class="text-sm font-medium">{{ session('success') }}</span>
        </div>
        <button type="button" @click="show = false" aria-label="Đóng thông báo" class="text-emerald-400 hover:text-emerald-200">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
@endif

@if (session('error'))
    <div x-data="{ show: true }" x-show="show" role="alert" data-testid="flash-error" class="mb-4 flex items-center justify-between p-4 rounded-xl bg-rose-950/80 border border-rose-500/40 text-rose-200 shadow-lg">
        <div class="flex items-center gap-3">
            <svg aria-hidden="true" class="w-5 h-5 text-rose-400 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
            </svg>
            <span class="text-sm font-medium">{{ session('error') }}</span>
        </div>
        <button type="button" @click="show = false" aria-label="Đóng thông báo" class="text-rose-400 hover:text-rose-200">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
@endif

@if (session('warning'))
    <div x-data="{ show: true }" x-show="show" role="alert" data-testid="flash-warning" class="mb-4 flex items-center justify-between p-4 rounded-xl bg-amber-950/80 border border-amber-500/40 text-amber-200 shadow-lg">
        <span class="text-sm font-medium">{{ session('warning') }}</span>
        <button type="button" @click="show = false" aria-label="Đóng thông báo" class="text-amber-400 hover:text-amber-200">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
@endif

{{-- A page that renders the errors inside the form that caused them (a reopened modal) sets
     the section inline_form_errors, so the banner hidden behind the modal backdrop is skipped. --}}
@if ($errors->any() && ! $__env->hasSection('inline_form_errors'))
    <div x-data="{ show: true }" x-show="show" role="alert" data-testid="validation-banner" class="mb-4 p-4 rounded-xl bg-rose-950/80 border border-rose-500/40 text-rose-200 shadow-lg">
        <div class="flex items-start justify-between">
            <div class="flex items-start gap-3">
                <svg aria-hidden="true" class="w-5 h-5 text-rose-400 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <div>
                    <p class="text-sm font-semibold">Đã xảy ra lỗi, vui lòng kiểm tra lại:</p>
                    <ul class="mt-1 list-disc list-inside text-xs space-y-0.5 text-rose-300">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <button type="button" @click="show = false" aria-label="Đóng thông báo" class="text-rose-400 hover:text-rose-200">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </div>
@endif
