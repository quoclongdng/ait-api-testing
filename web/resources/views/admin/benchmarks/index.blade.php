@extends('layouts.admin')

@section('title', 'Benchmark')

@section('content')
<div
    x-data="benchmarkPage({ storeUrl: @js(route('api.v1.admin.benchmarks.store')), selected: @js($benchmarks->first()?->id) })"
    class="space-y-6"
>
    <div class="flex flex-col gap-4 border-b border-slate-800 pb-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="flex items-center gap-3 text-xl font-bold tracking-tight text-white sm:text-2xl">
                <span>Benchmark</span>
            </h1>
            <p class="mt-1 text-xs text-slate-400 sm:text-sm">Các API sandbox có lỗi cài sẵn và ground truth dùng làm chuẩn đánh giá thực nghiệm.</p>
        </div>
        <div class="flex flex-wrap gap-2 self-start sm:self-auto">
            <form method="POST" action="{{ route('admin.benchmarks.import') }}">
                @csrf
                <button id="btn-import-ground-truth" type="submit" class="inline-flex items-center gap-2 whitespace-nowrap rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-2.5 text-xs font-semibold text-emerald-300 transition hover:bg-emerald-500/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300"><span aria-hidden="true">⟳</span> Đồng bộ từ ground-truth.yaml</button>
            </form>
            <button id="btn-create-benchmark" type="button" @click="modalOpen = true" class="inline-flex shrink-0 items-center justify-center gap-2 self-start whitespace-nowrap rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-indigo-600/25 transition hover:bg-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900 sm:self-auto"><span aria-hidden="true" class="text-base leading-none">+</span> Thêm benchmark</button>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <section class="space-y-5 lg:col-span-2">
            <div class="rounded-2xl border border-slate-800 bg-slate-800/40 shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-800 px-5 py-4">
                    <h2 class="text-sm font-bold text-white">DANH SÁCH BỘ BENCHMARK</h2>
                    <span class="rounded-full bg-cyan-500/10 px-2.5 py-1 text-xs font-semibold text-cyan-300">{{ $benchmarks->count() }} benchmark · {{ $benchmarks->sum('bugs_count') }} bug</span>
                </div>
                <ul class="divide-y divide-slate-800/60 text-xs">
                    @forelse ($benchmarks as $benchmark)
                        <li>
                            <button type="button" @click="selected = {{ $benchmark->id }}" :aria-pressed="(selected === {{ $benchmark->id }}).toString()" aria-pressed="{{ $loop->first ? 'true' : 'false' }}" class="flex w-full items-center justify-between gap-3 px-5 py-3.5 text-left hover:bg-slate-800/30" :class="selected === {{ $benchmark->id }} ? 'bg-indigo-500/10' : ''">
                                <span>
                                    <span class="font-semibold text-slate-100">[Benchmark #{{ $benchmarkNumbers[$benchmark->id] }}] {{ $benchmark->name }} ({{ $benchmark->version }})</span>
                                    <span class="mt-0.5 block font-mono text-slate-400">{{ $benchmark->slug }} · Base URL: {{ $benchmark->base_url }}</span>
                                </span>
                                <span class="shrink-0 rounded-full bg-slate-700/60 px-2 py-0.5 font-semibold text-slate-200">{{ $benchmark->bugs_count }} bug</span>
                            </button>
                        </li>
                    @empty
                        <li class="px-5 py-10 text-center text-slate-400">Chưa có benchmark nào. Bấm “Đồng bộ từ ground-truth.yaml” để nạp các benchmark có sẵn.</li>
                    @endforelse
                </ul>
            </div>

            @foreach ($benchmarks as $benchmark)
                <div x-show="selected === {{ $benchmark->id }}" @if (! $loop->first) x-cloak @endif class="rounded-2xl border border-slate-800 bg-slate-800/40 shadow-xl">
                    <div class="flex flex-col gap-3 border-b border-slate-800 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-sm font-bold text-white">CHI TIẾT SEEDED BUGS &amp; GROUND TRUTH — {{ $benchmark->name }}</h2>
                            @if ($benchmark->description)
                                <p class="mt-1 text-xs text-slate-400">{{ $benchmark->description }}</p>
                            @endif
                        </div>
                        @if ($benchmark->experiments_count === 0)
                            <form method="POST" action="{{ route('admin.benchmarks.destroy', $benchmark) }}" class="shrink-0" data-testid="benchmark-delete-{{ $benchmark->id }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" data-confirm="Xóa benchmark {{ $benchmark->name }} ({{ $benchmark->version }}) cùng {{ $benchmark->bugs_count }} seeded bug? Thao tác này không hoàn tác được." aria-label="Xóa benchmark {{ $benchmark->name }} ({{ $benchmark->version }})" class="whitespace-nowrap rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-1.5 text-xs font-semibold text-rose-300 hover:bg-rose-500/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-300">Xóa benchmark</button>
                            </form>
                        @else
                            <p class="shrink-0 text-xs text-slate-400 sm:max-w-[14rem] sm:text-right" data-testid="benchmark-delete-locked-{{ $benchmark->id }}">Đã có {{ $benchmark->experiments_count }} thực nghiệm dùng benchmark này nên không xóa được.</p>
                        @endif
                    </div>
                    <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="overflow-x-auto focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                        <table data-testid="benchmark-bugs-table" class="w-full min-w-[56rem] text-left text-xs">
                            <thead class="border-b border-slate-800 bg-slate-800/70 font-semibold uppercase text-slate-400">
                                <tr>
                                    <th class="whitespace-nowrap px-4 py-3">Mã bug</th>
                                    <th class="whitespace-nowrap px-4 py-3">Phương thức</th>
                                    <th class="px-4 py-3">Đường dẫn</th>
                                    <th class="px-4 py-3">Phân loại lỗi</th>
                                    <th class="px-4 py-3">Cờ kích hoạt môi trường</th>
                                    <th class="min-w-[16rem] px-4 py-3">Mô tả</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60 text-slate-300">
                                @forelse ($benchmark->bugs as $bug)
                                    <tr class="hover:bg-slate-800/30">
                                        <td data-testid="benchmark-bug-code" class="whitespace-nowrap px-4 py-2.5 font-mono font-semibold text-cyan-300">{{ $bug->code }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 font-mono">{{ $bug->method->value }}</td>
                                        <td class="px-4 py-2.5 font-mono break-all">{{ $bug->path }}</td>
                                        <td class="px-4 py-2.5"><span class="inline-block whitespace-nowrap rounded bg-amber-500/10 px-1.5 py-0.5 text-[11px] text-amber-300" title="{{ \App\Support\EnumTooltip::for($bug->category->label(), $bug->category) }}">{{ $bug->category->label() }}</span></td>
                                        <td class="px-4 py-2.5 font-mono text-emerald-300 break-all">{{ $bug->toggle_flag }}=1</td>
                                        <td class="min-w-[16rem] max-w-md px-4 py-2.5 text-slate-400">{{ $bug->description }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-5 py-8 text-center text-slate-400">Benchmark này chưa có seeded bug.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @php($fixture = $fixtures[$benchmark->slug] ?? null)
                <div x-show="selected === {{ $benchmark->id }}" @if (! $loop->first) x-cloak @endif class="rounded-2xl border border-slate-800 bg-slate-800/40 shadow-xl" data-testid="benchmark-fixture-{{ $benchmark->slug }}">
                    <div class="border-b border-slate-800 px-5 py-4">
                        <h2 class="text-sm font-bold text-white">FIXTURE THỰC NGHIỆM (CHỈ ĐỌC) — {{ $benchmark->name }}</h2>
                        <p class="mt-1 text-xs text-slate-400">Vai trò xác thực và business rules trong <code>ground-truth.yaml</code>; mỗi thực nghiệm mới lưu snapshot của phần này. Token chỉ dùng trong sandbox và không hiển thị ở đây.</p>
                    </div>
                    <div class="space-y-4 px-5 py-4 text-xs">
                        @if ($fixture === null)
                            <p class="text-slate-400">Không có ground-truth.yaml cho benchmark này.</p>
                        @elseif ($fixture['error'] !== null)
                            <p class="text-rose-300">{{ $fixture['error'] }}</p>
                        @else
                            <div>
                                <h3 class="font-semibold uppercase tracking-wider text-slate-300">Vai trò xác thực</h3>
                                @if ($fixture['auth'] === null)
                                    <p class="mt-2 text-slate-400">Benchmark không có endpoint yêu cầu xác thực.</p>
                                @else
                                    <p class="mt-2 text-slate-400">Bearer token; vai trò mặc định: <span class="font-mono text-cyan-300">{{ $fixture['auth']['default_role'] }}</span>. Biến dùng trong DSL: <code class="text-emerald-300">@{{auth_token_&lt;vai trò&gt;}}</code>.</p>
                                    <ul class="mt-2 space-y-1">
                                        @foreach ($fixture['auth']['roles'] as $role)
                                            <li><span class="font-mono text-cyan-300">{{ $role['name'] }}</span> <span class="text-slate-400">— {{ $role['description'] ?? '' }}</span></li>
                                        @endforeach
                                    </ul>
                                    @if ($fixture['auth']['operations'] !== [])
                                        <ul class="mt-2 space-y-1 text-slate-400">
                                            @foreach ($fixture['auth']['operations'] as $operation)
                                                <li><span class="font-mono text-slate-200">{{ $operation['method'] }} {{ $operation['path'] }}</span>@if ($operation['role'] !== null) · vai trò <span class="font-mono text-cyan-300">{{ $operation['role'] }}</span>@endif @if ($operation['forbidden_roles'] !== []) · bị cấm (403): <span class="font-mono text-amber-300">{{ implode(', ', $operation['forbidden_roles']) }}</span>@endif</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                @endif
                            </div>
                            <div>
                                <h3 class="font-semibold uppercase tracking-wider text-slate-300">Business rules ({{ count($fixture['business_rules']) }})</h3>
                                <ul class="mt-2 space-y-1.5">
                                    @forelse ($fixture['business_rules'] as $rule)
                                        <li><span class="font-mono font-semibold text-cyan-300">{{ $rule['code'] }}</span>@if ($rule['method'] !== null) <span class="font-mono text-slate-400">{{ $rule['method'] }} {{ $rule['path'] }}</span>@endif <span class="text-slate-400">— {{ $rule['description'] }}</span></li>
                                    @empty
                                        <li class="text-slate-400">Chưa khai báo business rule.</li>
                                    @endforelse
                                </ul>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </section>

        <aside class="space-y-5">
            <div class="rounded-2xl border border-emerald-500/20 bg-emerald-950/20 p-5 text-xs">
                <h2 class="font-bold uppercase tracking-wider text-emerald-300">Ground truth trên đĩa</h2>
                <p class="mt-2 leading-5 text-slate-400">Các thư mục <code class="text-emerald-300">benchmark/&lt;slug&gt;/ground-truth.yaml</code> tìm thấy:</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse ($availableSlugs as $slug)
                        <span class="rounded-lg border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-1 font-mono text-emerald-300">{{ $slug }}</span>
                    @empty
                        <span class="text-slate-400">Không tìm thấy (kiểm tra BENCHMARK_PATH).</span>
                    @endforelse
                </div>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 text-xs">
                <h2 class="font-bold uppercase tracking-wider text-slate-300">Sandbox biến thể bug</h2>
                <div class="mt-3 space-y-2">
                    <div class="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2">
                        <span class="text-slate-400">Driver</span>
                        <span class="font-mono text-slate-200">{{ $sandboxDriver }}</span>
                    </div>
                    <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2">
                        <span class="text-slate-400">BENCHMARK_VARIANT_URL_TEMPLATE</span>
                        <span class="mt-1 block break-all font-mono {{ $variantTemplate === '' ? 'text-amber-300' : 'text-slate-200' }}">{{ $variantTemplate === '' ? '(chưa cấu hình)' : $variantTemplate }}</span>
                    </div>
                </div>
                <p class="mt-3 leading-5 text-slate-400">Mỗi biến thể bug là một sandbox riêng chỉ bật đúng một cờ (cô lập đơn lỗi). Host của các biến thể phải thuộc allowlist.</p>
            </div>
        </aside>
    </div>

    <div x-show="modalOpen" x-cloak role="dialog" aria-modal="true" aria-labelledby="benchmark-modal-title" x-modal-focus="modalOpen" data-modal-trigger="#btn-create-benchmark" @keydown.escape.window="modalOpen = false" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4">
        <div class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm" @click="modalOpen = false"></div>
        <form @submit.prevent="submit()" class="relative w-full max-w-lg space-y-4 rounded-2xl border border-slate-700 bg-slate-900 p-6 text-xs shadow-2xl">
            <div class="flex items-center justify-between gap-3 border-b border-slate-800 pb-3">
                <h2 id="benchmark-modal-title" class="text-sm font-bold text-white">THÊM BENCHMARK</h2>
                <button type="button" @click="modalOpen = false" aria-label="Đóng hộp thoại" class="text-slate-400 hover:text-white"><span aria-hidden="true">✕</span></button>
            </div>
            <p class="text-slate-400">Nếu tồn tại <code>benchmark/&lt;mã&gt;/ground-truth.yaml</code>, các seeded bug được nạp tự động và phiên bản phải trùng phiên bản trong tệp đó. Phiên bản được lưu dạng <code>v1.0</code> (nhập <code>1.0</code> cũng được).</p>
            <label class="block">
                <span class="font-semibold text-slate-300">Mã (slug)</span>
                <input type="text" x-model="form.slug" required maxlength="100" placeholder="simple-store" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-sm text-slate-100">
            </label>
            <label class="block">
                <span class="font-semibold text-slate-300">Tên benchmark</span>
                <input type="text" x-model="form.name" required maxlength="255" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
            </label>
            <label class="block">
                <span class="font-semibold text-slate-300">Base URL</span>
                <input type="url" x-model="form.base_url" required maxlength="500" placeholder="http://benchmark:8000" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-sm text-slate-100">
            </label>
            <label class="block">
                <span class="font-semibold text-slate-300">Phiên bản</span>
                <input type="text" x-model="form.version" required maxlength="20" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-sm text-slate-100">
            </label>
            <label class="block">
                <span class="font-semibold text-slate-300">Mô tả</span>
                <textarea x-model="form.description" maxlength="2000" rows="2" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"></textarea>
            </label>
            <div x-show="error" x-cloak role="alert" aria-live="assertive" class="rounded-lg border border-rose-500/30 bg-rose-500/10 p-3 text-rose-200" data-testid="benchmark-error">
                <p x-text="error"></p>
                <template x-for="detail in errorDetails" :key="detail">
                    <p x-text="'• ' + detail"></p>
                </template>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" @click="modalOpen = false" class="rounded-lg border border-slate-700 px-4 py-2 font-semibold text-slate-300 hover:bg-slate-800">Hủy</button>
                <button type="submit" :disabled="submitting" class="rounded-lg bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">Lưu</button>
            </div>
        </form>
    </div>
</div>

<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    function benchmarkPage(options) {
        return {
            selected: options.selected,
            modalOpen: false,
            submitting: false,
            error: '',
            errorDetails: [],
            form: { slug: '', name: '', base_url: '', version: 'v1.0', description: '' },
            async submit() {
                this.error = '';
                this.errorDetails = [];
                this.submitting = true;
                try {
                    const response = await fetch(options.storeUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ ...this.form, description: this.form.description || null }),
                    });
                    const json = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        this.error = (json.error && json.error.message) ? json.error.message : 'Không thể thêm benchmark.';
                        // Show which field failed (error.details from the §6 envelope). Only VALIDATION_FAILED
                        // details are field messages; a CONFLICT carries context ({slug, version}) that the
                        // message already explains, so it is not listed as bullets (S14-06).
                        this.errorDetails = (json.error && json.error.code === 'VALIDATION_FAILED' && json.error.details)
                            ? Object.values(json.error.details).flat().map(String)
                            : [];
                        return;
                    }
                    window.location.reload();
                } catch (e) {
                    this.error = 'Không kết nối được máy chủ.';
                } finally {
                    this.submitting = false;
                }
            },
        };
    }
</script>
@endsection
