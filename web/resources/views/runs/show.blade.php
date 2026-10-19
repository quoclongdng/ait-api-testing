@extends('layouts.app')

@section('title', 'Lần chạy #' . $run->id . ' — ' . $suite->name)

@section('content')
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    window.aitRunScreen = function (config) {
        const TERMINAL = ['COMPLETED', 'FAILED', 'CANCELLED'];
        const BODY_PREVIEW_CHARS = 2000;
        // A CANCELLED run whose worker died never gets totals: stop waiting after this many polls.
        const MAX_CANCEL_SETTLE_POLLS = 30;

        const jsonHeaders = () => ({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        });

        // Error carrying the envelope's error.message and the HTTP status of an API call.
        class ApiError extends Error {
            constructor(message, status) {
                super(message);
                this.status = status;
            }
        }

        const apiErrorMessage = (body, fallback) =>
            (body && body.error && body.error.message) ? body.error.message : fallback;

        // 4xx answers other than 408/429 will not change by polling again.
        const isNonRetryable = (status) =>
            typeof status === 'number' && status >= 400 && status < 500 && status !== 408 && status !== 429;

        return {
            run: config.run,
            cases: config.cases,
            runnableCount: config.runnableCount,
            results: [],
            resultsTotal: 0,
            resultsLoaded: false,
            verdictFilter: '',
            expanded: {},
            errorMessage: '',
            cancelling: false,
            timer: null,
            loading: true,
            cancelSettlePolls: 0,
            statusLabels: config.statusLabels,
            verdictLabels: config.verdictLabels,

            init() {
                this.refresh();
            },

            get isTerminal() {
                return TERMINAL.includes(this.run.status);
            },

            /**
             * True once the screen can stop polling. A run cancelled while RUNNING turns
             * CANCELLED immediately, but the worker still finishes the case in progress,
             * then stores SKIPPED results for the rest and writes totals. Keep polling
             * until that happens (started_at set, totals still null => not settled).
             */
            get isSettled() {
                if (!this.isTerminal) {
                    return false;
                }
                if (this.run.status === 'CANCELLED' && this.run.started_at && !this.run.totals
                    && this.cancelSettlePolls < MAX_CANCEL_SETTLE_POLLS) {
                    return false;
                }
                return true;
            },

            get expectedTotal() {
                if (this.run.totals && typeof this.run.totals.total === 'number') {
                    return this.run.totals.total;
                }
                return Math.max(this.runnableCount, this.resultsTotal);
            },

            /**
             * A finished run that stores only its totals, no per-case result (official experiment
             * record of the demo showcase): the counters come from run.totals (QA S14-06). Only once
             * the results were actually loaded: a failed results request is not a totals-only run.
             */
            get totalsOnly() {
                return this.isSettled && this.resultsLoaded && this.resultsTotal === 0 && !!this.run.totals
                    && typeof this.run.totals.total === 'number' && this.run.totals.total > 0;
            },

            get processedCount() {
                return this.totalsOnly ? this.expectedTotal : this.resultsTotal;
            },

            get percent() {
                if (this.totalsOnly) {
                    return 100;
                }
                if (this.isSettled) {
                    // Share of cases actually processed: a run cancelled while still QUEUED has no
                    // result at all and must not show "100% (0/15 case)" (S13-03).
                    if (this.expectedTotal === 0) {
                        return 100;
                    }
                    return Math.min(100, Math.round(this.resultsTotal / this.expectedTotal * 100));
                }
                if (this.expectedTotal === 0) {
                    return this.run.status === 'RUNNING' ? 5 : 0;
                }
                return Math.min(99, Math.round(this.resultsTotal / this.expectedTotal * 100));
            },

            get cancelledBeforeStart() {
                return this.run.status === 'CANCELLED' && this.isSettled && this.resultsTotal === 0;
            },

            get counts() {
                const c = { PASS: 0, FAIL: 0, ERROR: 0, SKIPPED: 0 };
                if (this.totalsOnly) {
                    const t = this.run.totals;
                    c.PASS = Number(t.pass) || 0;
                    c.FAIL = Number(t.fail) || 0;
                    c.ERROR = Number(t.error) || 0;
                    c.SKIPPED = Number(t.skipped) || 0;
                    return c;
                }
                this.results.forEach((r) => { if (c[r.verdict] !== undefined) { c[r.verdict]++; } });
                return c;
            },

            get filteredResults() {
                if (this.verdictFilter === '') {
                    return this.results;
                }
                return this.results.filter((r) => r.verdict === this.verdictFilter);
            },

            async refresh() {
                // HTTP status of the last failed API call; null when the call succeeded
                // or never got an HTTP answer (network loss => keep retrying).
                let failedStatus = null;
                try {
                    const res = await fetch(config.showUrl, { headers: jsonHeaders(), credentials: 'same-origin' });
                    const body = await res.json().catch(() => null);
                    if (!res.ok || !body || !body.data) {
                        failedStatus = res.ok ? null : res.status;
                        this.errorMessage = apiErrorMessage(body, 'Không tải được trạng thái lần chạy.');
                    } else {
                        this.run = body.data;
                        if (this.run.status === 'CANCELLED' && !this.run.totals) {
                            this.cancelSettlePolls++;
                        }
                        await this.loadResults();
                        this.errorMessage = '';
                    }
                } catch (e) {
                    if (e instanceof ApiError) {
                        failedStatus = e.status;
                        this.errorMessage = e.message;
                    } else {
                        this.errorMessage = 'Mất kết nối tới máy chủ, đang thử lại…';
                    }
                }
                this.loading = false;
                if (failedStatus === 401) {
                    window.location.href = config.loginUrl;
                    return;
                }
                if (isNonRetryable(failedStatus)) {
                    // 403/404/422…: polling again cannot succeed; keep the API message on screen.
                    this.timer = null;
                    return;
                }
                if (!this.isSettled) {
                    this.timer = setTimeout(() => this.refresh(), config.pollMs);
                }
            },

            async loadResults() {
                const all = [];
                let page = 1;
                let total = 0;
                // Pages of 100 (API maximum) until every stored result is loaded.
                for (;;) {
                    const url = config.resultsUrl + '?per_page=100&page=' + page;
                    const res = await fetch(url, { headers: jsonHeaders(), credentials: 'same-origin' });
                    const body = await res.json().catch(() => null);
                    if (!res.ok || !body || !Array.isArray(body.data)) {
                        throw new ApiError(apiErrorMessage(body, 'Không tải được kết quả.'), res.ok ? null : res.status);
                    }
                    all.push(...body.data);
                    total = body.meta ? body.meta.total : all.length;
                    if (all.length >= total || body.data.length === 0) {
                        break;
                    }
                    page++;
                }
                this.results = all;
                this.resultsTotal = total;
                this.resultsLoaded = true;
            },

            async cancel() {
                const consequence = this.run.status === 'QUEUED'
                    ? 'Lần chạy chưa bắt đầu nên sẽ không có test case nào được thực thi.'
                    : 'Các case chưa chạy sẽ được đánh dấu Bỏ qua (SKIPPED).';
                if (!confirm('Bạn có chắc muốn hủy lần chạy này? ' + consequence)) {
                    return;
                }
                this.cancelling = true;
                try {
                    const res = await fetch(config.cancelUrl, {
                        method: 'POST',
                        headers: Object.assign(jsonHeaders(), {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        }),
                        credentials: 'same-origin',
                    });
                    const body = await res.json().catch(() => null);
                    if (res.ok && body && body.data) {
                        this.run = body.data;
                        this.errorMessage = '';
                        await this.loadResults().catch(() => {});
                    } else {
                        this.errorMessage = (body && body.error && body.error.message) ? body.error.message : 'Không thể hủy lần chạy.';
                    }
                } catch (e) {
                    this.errorMessage = 'Không kết nối được máy chủ. Vui lòng thử lại.';
                } finally {
                    this.cancelling = false;
                }
            },

            testCaseUrl(id) {
                return config.testCaseUrlTemplate.replace("__CASE__", String(id));
            },

            caseOf(result) {
                return this.cases[result.test_case_id] || null;
            },

            toggle(id) {
                this.expanded[id] = !this.expanded[id];
            },

            steps(result) {
                const req = result.request_log || {};
                const resp = result.response_log || {};
                if (Array.isArray(req.steps) && req.steps.length > 0) {
                    const responses = Array.isArray(resp.steps) ? resp.steps : [];
                    return req.steps.map((r, i) => ({
                        key: i,
                        name: (r.step_name ? String(r.step_name).replace(/^Execute (GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS) /, 'Gọi $1 ').replace(/^Step ([0-9]+)$/, 'Bước $1') : null) || ('Bước ' + (r.step_index || i + 1)),
                        request: r,
                        response: responses[i] || null,
                    }));
                }
                if (req.method || req.url) {
                    return [{ key: 0, name: 'Bước 1', request: req, response: resp.status !== undefined ? resp : null }];
                }
                return [];
            },

            pretty(value) {
                if (value === null || value === undefined) {
                    return '—';
                }
                if (typeof value === 'string') {
                    try {
                        return JSON.stringify(JSON.parse(value), null, 2);
                    } catch (e) {
                        return value;
                    }
                }
                return JSON.stringify(value, null, 2);
            },

            isEmpty(value) {
                return value === null || value === undefined
                    || (Array.isArray(value) && value.length === 0)
                    || (typeof value === 'object' && Object.keys(value).length === 0);
            },

            preview(value) {
                const text = this.pretty(value);
                if (text.length <= BODY_PREVIEW_CHARS) {
                    return text;
                }
                return text.slice(0, BODY_PREVIEW_CHARS) + '\n… (đã cắt bớt, ' + text.length + ' ký tự)';
            },

            verdictClass(verdict) {
                return {
                    PASS: 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30',
                    FAIL: 'bg-rose-500/10 text-rose-300 border-rose-500/30',
                    ERROR: 'bg-amber-500/10 text-amber-300 border-amber-500/30',
                    SKIPPED: 'bg-slate-700/50 text-slate-300 border-slate-600',
                }[verdict] || 'bg-slate-800 text-slate-300 border-slate-700';
            },

            statusClass(status) {
                return {
                    COMPLETED: 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30',
                    RUNNING: 'bg-blue-500/10 text-blue-300 border-blue-500/30',
                    QUEUED: 'bg-slate-700/50 text-slate-300 border-slate-600',
                    FAILED: 'bg-rose-500/10 text-rose-300 border-rose-500/30',
                    CANCELLED: 'bg-amber-500/10 text-amber-300 border-amber-500/30',
                }[status] || 'bg-slate-800 text-slate-300 border-slate-700';
            },

            formatTime(iso) {
                // Same display zone as the server-rendered screens (M11/M13, exports): ait.display_timezone.
                if (!iso) return '—';
                try {
                    return new Date(iso).toLocaleString('vi-VN', { timeZone: config.displayTimezone });
                } catch (e) {
                    return new Date(iso).toLocaleString('vi-VN');
                }
            },
        };
    };
</script>

<div x-data="aitRunScreen({{ \Illuminate\Support\Js::from([
        'run' => (new \App\Http\Resources\TestRunResource($run))->resolve(request()),
        'cases' => (object) $cases,
        'runnableCount' => $runnableCount,
        'showUrl' => route('api.v1.runs.show', $run),
        'resultsUrl' => route('api.v1.runs.results', $run),
        'cancelUrl' => route('api.v1.runs.cancel', $run),
        'pollMs' => 2000,
        'displayTimezone' => (string) config('ait.display_timezone'),
        'statusLabels' => collect(\App\Enums\TestRunStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all(),
        'verdictLabels' => \App\Support\VerdictLabel::all(),
        'loginUrl' => route('login'),
        'testCaseUrlTemplate' => route('test-cases.show', ['testCase' => '__CASE__']),
    ]) }})" class="space-y-6">

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
                <a href="{{ route('suites.runs.index', $suite) }}" class="hover:text-white transition">Lần chạy</a>
                <span>/</span>
                <span class="text-slate-200 font-medium">Lần chạy #{{ $run->id }}</span>
            </div>
            <h1 class="flex items-center gap-3 text-xl font-bold tracking-tight text-white sm:text-2xl">
                <span>Lần chạy #{{ $run->id }}</span>
            </h1>
            <p class="mt-1 text-xs text-slate-400 sm:text-sm">
                Bộ kiểm thử: <span class="text-white font-semibold">{{ $suite->name }}</span>
                · Mục tiêu: <span class="font-mono text-cyan-300 break-all">{{ $run->target_base_url }}</span>
                @if ($run->benchmark_variant)
                    · Biến thể: <span class="font-mono text-slate-200">{{ \App\Support\VariantLabel::display($run->benchmark_variant) }}</span>
                @endif
                · Chạy bởi {{ $run->triggerer->name }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <a href="{{ route('suites.runs.index', $suite) }}"
               class="flex items-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800/80 px-4 py-2.5 text-xs font-semibold text-slate-300 hover:text-white transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Danh sách lần chạy
            </a>
            <a href="{{ route('runs.report', $run) }}" x-show="isTerminal" @unless ($run->status->isFinished()) x-cloak @endunless
               data-testid="run-report-link"
               class="flex items-center gap-1.5 rounded-xl border px-4 py-2.5 text-xs font-semibold hover:text-white transition {{ \App\Support\AccentClasses::secondaryButton(auth()->user()) }}">
                Xem báo cáo
            </a>
            <button type="button" x-show="!isTerminal" x-cloak @click="cancel()" :disabled="cancelling"
                    class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-2.5 text-xs font-semibold text-rose-300 hover:bg-rose-500/20 transition disabled:opacity-50">
                <span x-show="!cancelling">Hủy lần chạy</span>
                <span x-show="cancelling">Đang hủy…</span>
            </button>
        </div>
    </div>

    <div x-show="errorMessage !== ''" x-cloak role="alert" aria-live="assertive" class="rounded-xl border border-rose-500/30 bg-rose-950/30 p-3 text-xs text-rose-300" x-text="errorMessage"></div>

    <!-- Progress & summary -->
    <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            {{-- Live region (like #job-status on the generation screen): screen readers hear the status, the
                 progress and the verdict counts as polling updates them. --}}
            <div class="flex items-center gap-3" role="status" aria-live="polite" aria-atomic="true" data-testid="run-progress-status">
                <span class="rounded-xl border px-3 py-1 text-xs font-bold uppercase tracking-wider"
                      :class="statusClass(run.status)" :title="statusLabels[run.status] ? statusLabels[run.status] + ' (' + run.status + ')' : run.status" x-text="statusLabels[run.status] || run.status" title="{{ \App\Support\EnumTooltip::for($run->status->label(), $run->status) }}">{{ $run->status->label() }}</span>
                <span class="text-xs text-slate-300">TIẾN ĐỘ THỰC THI:
                    <strong class="text-white" x-text="percent + '%'"></strong>
                    (<span x-text="processedCount"></span>/<span x-text="expectedTotal"></span> case)
                </span>
                <span class="sr-only" x-text="'Đạt ' + counts.PASS + ', Không đạt ' + counts.FAIL + ', Lỗi ' + counts.ERROR + ', Bỏ qua ' + counts.SKIPPED"></span>
                <span x-show="!isSettled" x-cloak class="text-[11px] text-blue-300 animate-pulse">Đang cập nhật…</span>
                <span x-show="cancelledBeforeStart" x-cloak data-testid="run-cancelled-before-start" class="text-[11px] text-amber-300">Lần chạy đã bị hủy trước khi bắt đầu; không có test case nào được thực thi.</span>
            </div>
            <div class="text-xs text-slate-400 font-mono">
                Bắt đầu: <span x-text="formatTime(run.started_at)"></span>
                · Kết thúc: <span x-text="formatTime(run.finished_at)"></span>
            </div>
        </div>

        <div class="w-full bg-slate-900 rounded-full h-3 overflow-hidden border border-slate-800">
            <div class="h-3 rounded-full transition-all duration-500"
                 :class="run.status === 'COMPLETED' ? 'bg-gradient-to-r from-emerald-500 to-teal-400' : (run.status === 'FAILED' ? 'bg-rose-500' : (run.status === 'CANCELLED' ? 'bg-amber-500' : 'bg-indigo-500 animate-pulse'))"
                 :style="'width: ' + percent + '%'"></div>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <button type="button" @click="verdictFilter = verdictFilter === 'PASS' ? '' : 'PASS'" :aria-pressed="(verdictFilter === 'PASS').toString()" :class="verdictFilter === 'PASS' ? @js(\App\Support\AccentClasses::pressedRing(auth()->user())) : ''" class="rounded-2xl border border-emerald-500/30 bg-emerald-950/20 p-4 text-left">
                <span class="text-xs text-emerald-400 font-medium" data-testid="run-count-label-pass">{{ \App\Support\VerdictLabel::display('PASS') }}</span>
                <p class="mt-1 text-2xl font-bold text-emerald-300" x-text="counts.PASS">0</p>
            </button>
            <button type="button" @click="verdictFilter = verdictFilter === 'FAIL' ? '' : 'FAIL'" :aria-pressed="(verdictFilter === 'FAIL').toString()" :class="verdictFilter === 'FAIL' ? @js(\App\Support\AccentClasses::pressedRing(auth()->user())) : ''" class="rounded-2xl border border-rose-500/30 bg-rose-950/20 p-4 text-left">
                <span class="text-xs text-rose-400 font-medium" data-testid="run-count-label-fail">{{ \App\Support\VerdictLabel::display('FAIL') }}</span>
                <p class="mt-1 text-2xl font-bold text-rose-300" x-text="counts.FAIL">0</p>
            </button>
            <button type="button" @click="verdictFilter = verdictFilter === 'ERROR' ? '' : 'ERROR'" :aria-pressed="(verdictFilter === 'ERROR').toString()" :class="verdictFilter === 'ERROR' ? @js(\App\Support\AccentClasses::pressedRing(auth()->user())) : ''" class="rounded-2xl border border-amber-500/30 bg-amber-950/20 p-4 text-left">
                <span class="text-xs text-amber-400 font-medium" data-testid="run-count-label-error">{{ \App\Support\VerdictLabel::display('ERROR') }}</span>
                <p class="mt-1 text-2xl font-bold text-amber-300" x-text="counts.ERROR">0</p>
            </button>
            <button type="button" @click="verdictFilter = verdictFilter === 'SKIPPED' ? '' : 'SKIPPED'" :aria-pressed="(verdictFilter === 'SKIPPED').toString()" :class="verdictFilter === 'SKIPPED' ? @js(\App\Support\AccentClasses::pressedRing(auth()->user())) : ''" class="rounded-2xl border border-slate-600 bg-slate-800/40 p-4 text-left">
                <span class="text-xs text-slate-400 font-medium" data-testid="run-count-label-skipped">{{ \App\Support\VerdictLabel::display('SKIPPED') }}</span>
                <p class="mt-1 text-2xl font-bold text-slate-200" x-text="counts.SKIPPED">0</p>
            </button>
        </div>
    </div>

    <!-- Per-case results -->
    <div class="rounded-2xl border border-slate-800 bg-slate-800/40 shadow-xl overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-800 px-5 py-3">
            <h2 class="text-sm font-bold uppercase tracking-wider text-white">Kết quả từng ca kiểm thử</h2>
            <label class="flex items-center gap-2 text-xs text-slate-400">
                Lọc kết quả
                <select x-model="verdictFilter" class="rounded-lg border border-slate-700 bg-slate-900 px-2 py-1 text-xs text-white">
                    <option value="">Tất cả</option>
                    <option value="PASS">{{ \App\Support\VerdictLabel::display('PASS') }}</option>
                    <option value="FAIL">{{ \App\Support\VerdictLabel::display('FAIL') }}</option>
                    <option value="ERROR">{{ \App\Support\VerdictLabel::display('ERROR') }}</option>
                    <option value="SKIPPED">{{ \App\Support\VerdictLabel::display('SKIPPED') }}</option>
                </select>
            </label>
        </div>

        <p x-show="loading" class="px-5 py-8 text-center text-sm text-slate-400">Đang tải kết quả…</p>
        <p x-show="!loading && filteredResults.length === 0" x-cloak class="px-5 py-8 text-center text-sm text-slate-400">
            <span x-show="!isSettled">Chưa có kết quả nào, lần chạy đang được xử lý…</span>
            <span x-show="isSettled && !totalsOnly">Không có kết quả nào phù hợp.</span>
            <span x-show="totalsOnly" data-testid="run-totals-only">{{ \App\Data\Reports\RunReportSummary::TOTALS_ONLY_NOTE }}</span>
        </p>

        <div x-show="filteredResults.length > 0" x-cloak class="divide-y divide-slate-800">
            <template x-for="result in filteredResults" :key="result.id">
                <div>
                    <button type="button" @click="toggle(result.id)" :aria-expanded="(!!expanded[result.id]).toString()" :aria-controls="'result-detail-' + result.id" class="grid w-full grid-cols-[4rem_1fr_auto] items-center gap-3 px-5 py-3 text-left hover:bg-slate-800/40 md:grid-cols-[4rem_2fr_1.2fr_6rem_6rem_5rem]">
                        <span class="font-mono text-xs text-slate-400" x-text="'#' + result.test_case_id"></span>
                        <span class="text-sm text-white truncate" x-text="caseOf(result) ? caseOf(result).title : ('Test case #' + result.test_case_id)"></span>
                        <span class="hidden md:block font-mono text-xs text-cyan-300 truncate" x-text="caseOf(result) ? (caseOf(result).method + ' ' + caseOf(result).path) : '—'"></span>
                        <span class="justify-self-start rounded-lg border px-2 py-0.5 text-[11px] font-bold" :class="verdictClass(result.verdict)" :title="verdictLabels[result.verdict] || result.verdict" x-text="verdictLabels[result.verdict] || result.verdict"></span>
                        <span class="hidden md:block font-mono text-xs text-slate-400" x-text="result.duration_ms + ' ms'"></span>
                        <span data-testid="run-result-toggle-label" class="hidden md:block text-right text-xs font-semibold {{ \App\Support\AccentClasses::accentText(auth()->user()) }}" x-text="expanded[result.id] ? 'Thu gọn' : 'Chi tiết'"></span>
                    </button>

                    <div :id="'result-detail-' + result.id" x-show="expanded[result.id]" x-cloak class="space-y-4 border-t border-slate-800 bg-slate-950/40 px-5 py-4">
                        <div class="flex flex-wrap items-center gap-3 text-xs">
                            <a :href="testCaseUrl(result.test_case_id)" class="font-semibold {{ \App\Support\AccentClasses::link(auth()->user()) }}">Mở test case →</a>
                            <span class="text-slate-400" x-show="caseOf(result)">Kỹ thuật: <span class="text-slate-200" x-text="caseOf(result) ? caseOf(result).technique : ''"></span></span>
                        </div>

                        <div x-show="result.error_message" class="rounded-xl border border-amber-500/30 bg-amber-950/30 p-3 text-xs text-amber-200">
                            <strong>Lỗi:</strong> <span x-text="result.error_message"></span>
                        </div>

                        <!-- Requests / responses -->
                        <template x-for="step in steps(result)" :key="step.key">
                            <div class="space-y-2">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400" x-text="step.name"></p>
                                <div class="grid gap-3 lg:grid-cols-2">
                                    <div class="rounded-xl border border-slate-800 bg-slate-900/70 p-3">
                                        <p data-testid="run-step-request-heading" class="mb-2 text-[11px] font-bold uppercase tracking-wider {{ \App\Support\AccentClasses::label(auth()->user()) }}">Request</p>
                                        <p class="font-mono text-xs text-white break-all"><span class="text-cyan-300" x-text="step.request.method"></span> <span x-text="step.request.url"></span></p>
                                        <template x-if="!isEmpty(step.request.query)">
                                            <div class="mt-2"><p class="text-[11px] text-slate-400">Query</p><pre tabindex="0" class="max-h-40 overflow-auto whitespace-pre-wrap break-all text-[11px] text-slate-300 focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}" x-text="preview(step.request.query)"></pre></div>
                                        </template>
                                        <template x-if="!isEmpty(step.request.headers)">
                                            <div class="mt-2"><p class="text-[11px] text-slate-400">Headers</p><pre tabindex="0" class="max-h-40 overflow-auto whitespace-pre-wrap break-all text-[11px] text-slate-300 focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}" x-text="preview(step.request.headers)"></pre></div>
                                        </template>
                                        <div class="mt-2"><p class="text-[11px] text-slate-400">Body</p><pre tabindex="0" class="max-h-64 overflow-auto whitespace-pre-wrap break-all text-[11px] text-slate-300 focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}" x-text="preview(step.request.body)"></pre></div>
                                    </div>
                                    <div class="rounded-xl border border-slate-800 bg-slate-900/70 p-3">
                                        <p class="mb-2 text-[11px] font-bold uppercase tracking-wider text-emerald-300">Response</p>
                                        <template x-if="step.response">
                                            <div>
                                                <p class="font-mono text-xs text-white">HTTP <span x-text="step.response.status"></span> · <span x-text="(step.response.duration_ms ?? '—') + ' ms'"></span></p>
                                                <template x-if="!isEmpty(step.response.headers)">
                                                    <div class="mt-2"><p class="text-[11px] text-slate-400">Headers</p><pre tabindex="0" class="max-h-40 overflow-auto whitespace-pre-wrap break-all text-[11px] text-slate-300 focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}" x-text="preview(step.response.headers)"></pre></div>
                                                </template>
                                                <div class="mt-2"><p class="text-[11px] text-slate-400">Body (rút gọn)</p><pre tabindex="0" class="max-h-64 overflow-auto whitespace-pre-wrap break-all text-[11px] text-slate-300 focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}" x-text="preview(step.response.body)"></pre></div>
                                            </div>
                                        </template>
                                        <p x-show="!step.response" class="text-xs text-slate-400">Không nhận được response (lỗi kết nối hoặc timeout).</p>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <p x-show="steps(result).length === 0" class="text-xs text-slate-400">Không có request nào được gửi cho case này.</p>

                        <!-- Assertions -->
                        <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="rounded-xl border border-slate-800 bg-slate-900/70 overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
                            <p class="px-3 pt-3 text-[11px] font-bold uppercase tracking-wider text-amber-300">Kiểm tra (assertion)</p>
                            <table data-testid="assertion-table" class="w-full min-w-[36rem] text-xs">
                                <thead class="text-left text-[11px] uppercase tracking-wider text-slate-400">
                                    <tr>
                                        <th class="px-3 py-2">Loại</th>
                                        <th class="px-3 py-2">Mong đợi</th>
                                        <th class="px-3 py-2">Thực tế</th>
                                        <th class="px-3 py-2">Kết quả</th>
                                        <th class="px-3 py-2">Thông điệp</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800">
                                    <template x-for="(a, i) in (Array.isArray(result.assertion_results) ? result.assertion_results : [])" :key="i">
                                        <tr>
                                            <td class="whitespace-nowrap px-3 py-2 font-mono text-cyan-300" x-text="a.type"></td>
                                            <td class="px-3 py-2"><pre tabindex="0" data-testid="assertion-expected" class="max-h-32 min-w-[8rem] overflow-auto whitespace-pre-wrap font-mono text-slate-300 [overflow-wrap:anywhere] focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}" x-text="preview(a.expected)"></pre></td>
                                            <td class="px-3 py-2"><pre tabindex="0" data-testid="assertion-actual" class="max-h-32 min-w-[8rem] overflow-auto whitespace-pre-wrap font-mono text-slate-300 [overflow-wrap:anywhere] focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}" x-text="preview(a.actual)"></pre></td>
                                            <td class="px-3 py-2">
                                                <span data-testid="assertion-verdict" class="inline-block whitespace-nowrap rounded border px-1.5 py-0.5 text-[11px] font-bold"
                                                      :class="a.passed ? 'border-emerald-500/30 text-emerald-300' : 'border-rose-500/30 text-rose-300'"
                                                      x-text="a.passed ? 'Đạt' : 'Không đạt'"></span>
                                            </td>
                                            <td class="min-w-[10rem] px-3 py-2 text-slate-400 break-words" x-text="a.message || '—'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            <p x-show="!Array.isArray(result.assertion_results) || result.assertion_results.length === 0" class="px-3 pb-3 text-xs text-slate-400">Không có assertion nào được đánh giá.</p>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
@endsection
