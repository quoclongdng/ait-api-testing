@extends('layouts.app')

@section('title', 'Lần chạy — ' . $suite->name)

@section('content')
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    window.aitStartRunForm = function (config) {
        return {
            // Prefill the project's default target when set, else the first allowlist suggestion.
            target: config.defaultTarget ? config.defaultTarget : (config.targets.length > 0 ? config.targets[0].url : ''),
            variant: '',
            submitting: false,
            errorMessage: '',
            errorDetails: [],
            async submit() {
                this.errorMessage = '';
                this.errorDetails = [];
                this.target = this.target.trim();
                if (this.target === '') {
                    this.errorMessage = 'Vui lòng chọn hoặc nhập hệ thống đích.';
                    return;
                }
                this.submitting = true;
                try {
                    const payload = { target_base_url: this.target };
                    if (this.variant.trim() !== '') {
                        payload.benchmark_variant = this.variant.trim();
                    }
                    const res = await fetch(config.storeUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(payload),
                    });
                    const body = await res.json().catch(() => null);
                    if (res.ok && body && body.data) {
                        // Keep the button disabled until the browser leaves the page: a second click
                        // during navigation must not create a duplicate TestRun.
                        window.location.href = config.showUrlTemplate.replace('__RUN__', String(body.data.id));
                        return;
                    }
                    this.errorMessage = (body && body.error && body.error.message) ? body.error.message : 'Không thể tạo lần chạy (HTTP ' + res.status + ').';
                    // Only VALIDATION_FAILED details are field messages; other codes carry context (S14-06).
                    const details = body && body.error && body.error.code === 'VALIDATION_FAILED' && body.error.details ? body.error.details : {};
                    this.errorDetails = Object.values(details).flat().map(String);
                    this.submitting = false;
                } catch (e) {
                    this.errorMessage = 'Không kết nối được máy chủ. Vui lòng thử lại.';
                    this.submitting = false;
                }
            },
        };
    };
</script>

<div class="space-y-6">

    <!-- Header & Breadcrumbs -->
    <div class="flex flex-col gap-4 border-b border-slate-800 pb-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400 mb-1 min-w-0 break-all">
                <a href="{{ route('projects.index') }}" class="hover:text-white transition">Dự án</a>
                <span>/</span>
                <a href="{{ route('projects.suites.index', $project) }}" class="hover:text-white transition">{{ $project->name }}</a>
                <span>/</span>
                <a href="{{ route('suites.show', $suite) }}" class="hover:text-white transition">{{ $suite->name }}</a>
                <span>/</span>
                <span class="text-slate-200 font-medium">Lần chạy</span>
            </div>
            <h1 class="flex items-center gap-3 text-xl font-bold tracking-tight text-white sm:text-2xl">
                <span>Lần chạy</span>
            </h1>
            <p class="mt-1 text-xs text-slate-400 sm:text-sm">
                Bộ kiểm thử: <span class="text-white font-semibold">{{ $suite->name }}</span> · Đặc tả <span class="text-cyan-400 font-mono">{{ $suite->apiSpec->version_label }}</span>
            </p>
        </div>

        <a href="{{ route('suites.show', $suite) }}"
           class="flex items-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800/80 px-4 py-2.5 text-xs font-semibold text-slate-300 hover:text-white transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Về bộ kiểm thử
        </a>
    </div>

    <!-- Start a run -->
    <div x-data="aitStartRunForm({{ \Illuminate\Support\Js::from([
            'storeUrl' => route('api.v1.suites.runs.store', $suite),
            'showUrlTemplate' => route('runs.show', ['run' => '__RUN__']),
            'targets' => array_map(fn ($t) => ['url' => $t->url, 'label' => $t->label], $targets),
            'defaultTarget' => $project->default_target_url,
        ]) }})"
         class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl space-y-4">
        <div>
            <h2 class="text-sm font-bold uppercase tracking-wider text-white">Tạo lần chạy mới</h2>
            <p class="mt-1 text-xs text-slate-400">
                Chỉ các test case ở trạng thái <span class="font-semibold text-emerald-300">Hợp lệ (VALID)</span> hoặc <span class="font-semibold {{ \App\Support\AccentClasses::label(auth()->user()) }}">Đã duyệt (APPROVED)</span> được thực thi
                (hiện có <strong id="runnable-count" class="text-white">{{ $runnableCount }}</strong> case). Hệ thống đích phải thuộc danh sách cho phép (allowlist).
            </p>
            @if (count($resetBenchmarkHosts) > 0)
                <p id="benchmark-reset-note" class="mt-1 text-xs text-slate-400">
                    Benchmark của nền tảng (<span class="font-mono text-slate-300">{{ implode(', ', $resetBenchmarkHosts) }}</span>) được dùng chung:
                    trước từng test case, dữ liệu benchmark được đặt lại về dữ liệu mẫu, nên chạy lại cùng bộ test cho cùng kết quả.
                    Hệ thống đích khác không bị đặt lại.
                </p>
            @endif
        </div>

        @if (count($targets) === 0)
            <div class="rounded-xl border border-amber-500/30 bg-amber-950/30 p-3 text-xs text-amber-200">
                Chưa có hệ thống đích nào trong danh sách cho phép. Vui lòng liên hệ ADMIN để thêm target.
            </div>
        @else
            <form @submit.prevent="submit()" class="grid gap-4 md:grid-cols-[2fr_1fr_auto] md:items-end">
                <label class="block">
                    <span class="text-xs font-medium text-slate-300">Hệ thống đích (target)</span>
                    <input x-model="target" type="text" inputmode="url" name="target_base_url" list="run-target-suggestions"
                           maxlength="500" autocomplete="off" spellcheck="false" placeholder="http://benchmark:8000"
                           class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2.5 font-mono text-sm text-white placeholder-slate-500 {{ \App\Support\AccentClasses::focusBorder(auth()->user()) }} focus:outline-none">
                    <datalist id="run-target-suggestions">
                        @if ($project->default_target_url)
                            <option value="{{ $project->default_target_url }}">Target mặc định của dự án</option>
                        @endif
                        @foreach ($targets as $target)
                            <option value="{{ $target->url }}">{{ $target->label }}</option>
                        @endforeach
                    </datalist>
                    <span class="mt-1 block text-[11px] text-slate-400">
                        Chọn gợi ý từ allowlist hoặc nhập URL đầy đủ (có thể thêm cổng/đường dẫn, ví dụ <span class="font-mono">http://benchmark:8000</span>). Host không kèm cổng trong allowlist cho phép mọi cổng.
                    </span>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-300">Biến thể benchmark (tùy chọn)</span>
                    <input x-model="variant" type="text" name="benchmark_variant" maxlength="100" placeholder="clean"
                           class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-sm text-white placeholder-slate-500 {{ \App\Support\AccentClasses::focusBorder(auth()->user()) }} focus:outline-none">
                </label>
                <button type="submit" :disabled="submitting || {{ $runnableCount === 0 ? 'true' : 'false' }}"
                        data-testid="btn-start-run"
                        class="flex items-center justify-center gap-2 rounded-xl px-5 py-2.5 text-xs font-semibold shadow-lg transition focus-visible:outline-none focus-visible:ring-2 disabled:cursor-not-allowed disabled:opacity-50 {{ \App\Support\AccentClasses::primaryButton(auth()->user()) }}">
                    <span x-show="!submitting">Bắt đầu chạy</span>
                    <span x-show="submitting" x-cloak>Đang tạo…</span>
                </button>
            </form>
            @if ($runnableCount === 0)
                <p data-testid="runs-no-runnable-cases" class="text-xs text-amber-300">Bộ kiểm thử chưa có test case hợp lệ hoặc đã duyệt để chạy. Hãy sinh test trước.</p>
            @endif
        @endif

        <div x-show="errorMessage !== ''" x-cloak role="alert" aria-live="assertive" class="rounded-xl border border-rose-500/30 bg-rose-950/30 p-3 text-xs text-rose-300">
            <p class="font-semibold" x-text="errorMessage"></p>
            <ul class="mt-1 list-disc list-inside" x-show="errorDetails.length > 0">
                <template x-for="(d, i) in errorDetails" :key="i"><li x-text="d"></li></template>
            </ul>
        </div>
    </div>

    <!-- Runs list -->
    <div class="rounded-2xl border border-slate-800 bg-slate-800/40 shadow-xl overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-800 px-5 py-3">
            <h2 class="text-sm font-bold uppercase tracking-wider text-white">Lịch sử lần chạy</h2>
            <span class="text-xs text-slate-400">{{ $runs->total() }} lần chạy</span>
        </div>

        @if ($runs->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-slate-400">Chưa có lần chạy nào cho bộ kiểm thử này.</p>
        @else
            <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-900/60 text-left text-[11px] uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-4 py-3">ID</th>
                            <th class="px-4 py-3">Trạng thái</th>
                            <th class="px-4 py-3">Hệ thống đích</th>
                            <th class="px-4 py-3">Biến thể</th>
                            <th class="px-4 py-3">Đạt / Không đạt / Lỗi / Bỏ qua</th>
                            <th class="px-4 py-3">Người chạy</th>
                            <th class="px-4 py-3">Bắt đầu</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @foreach ($runs as $run)
                            @php $totals = is_array($run->totals) ? $run->totals : []; @endphp
                            <tr class="hover:bg-slate-800/40">
                                <td class="px-4 py-3 font-mono text-slate-300">#{{ $run->id }}</td>
                                <td class="px-4 py-3"><x-run-status-badge :status="$run->status" /></td>
                                <td class="px-4 py-3 font-mono text-xs text-cyan-300 break-all">{{ $run->target_base_url }}</td>
                                <td class="px-4 py-3 text-xs text-slate-300"><span class="whitespace-nowrap">{{ \App\Support\VariantLabel::display($run->benchmark_variant) }}</span></td>
                                <td class="px-4 py-3 font-mono text-xs">
                                    @if ($totals !== [])
                                        <span class="text-emerald-300">{{ $totals['pass'] ?? 0 }}</span> /
                                        <span class="text-rose-300">{{ $totals['fail'] ?? 0 }}</span> /
                                        <span class="text-amber-300">{{ $totals['error'] ?? 0 }}</span> /
                                        <span class="text-slate-400">{{ $totals['skipped'] ?? 0 }}</span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-300">{{ $run->triggerer->name }}</td>
                                {{-- Start time, like M12/M13: created_at is the import time of demo-imported official runs (S14-06). --}}
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-slate-400"><x-datetime :value="$run->started_at ?? $run->created_at" format="H:i d/m/Y" /></td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('runs.show', $run) }}" data-testid="run-results-link" class="text-xs font-semibold {{ \App\Support\AccentClasses::link(auth()->user()) }}">Xem kết quả →</a>
                                    @if ($run->status->isFinished())
                                        <a href="{{ route('runs.report', $run) }}" class="ml-3 text-xs font-semibold text-emerald-300 hover:text-white">Xem báo cáo</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($runs->hasPages())
                <div class="px-5 py-3">{{ $runs->links() }}</div>
            @endif
        @endif
    </div>
</div>
@endsection
