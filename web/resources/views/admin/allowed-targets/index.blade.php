@extends('layouts.admin')

@section('title', 'Cấu hình & allowlist')

@php
    $createErrors = $errors->any() && old('_form') === 'create-allowed-target';
    // ADR-0007 public deploy: ai-service is up but reports provider "disabled" (RULE only).
    $llmDisabled = $llmConfig['reachable'] && $llmConfig['provider'] === \App\Data\AiServiceStatus::PROVIDER_DISABLED;
@endphp
@if ($createErrors)
    @section('inline_form_errors', '1')
@endif

@section('content')
<div x-data="{ createModalOpen: {{ old('_form') === 'create-allowed-target' ? 'true' : 'false' }} }" class="space-y-6">
    <div class="flex flex-col gap-4 border-b border-slate-800 pb-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="flex items-center gap-3 text-xl font-bold tracking-tight text-white sm:text-2xl">
                <span>Cấu hình &amp; allowlist</span>
            </h1>
            <p class="mt-1 text-xs text-slate-400 sm:text-sm">Quản lý target được phép gọi nhằm bảo vệ Executor khỏi SSRF.</p>
        </div>
        <button id="btn-create-allowed-target" type="button" @click="createModalOpen = true" class="inline-flex shrink-0 items-center justify-center gap-2 self-start whitespace-nowrap rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-indigo-600/25 transition hover:bg-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900 sm:self-auto"><span aria-hidden="true" class="text-base leading-none">+</span> Thêm host</button>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <section class="space-y-4 lg:col-span-2">
            <div class="rounded-2xl border border-slate-800 bg-slate-800/40 shadow-xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-800 px-5 py-4">
                    <div>
                        <h2 class="text-sm font-bold text-white">DANH SÁCH TRẮNG TARGET</h2>
                        <p class="mt-1 text-xs text-slate-400">Các target động được lưu trong cơ sở dữ liệu.</p>
                    </div>
                    <span class="shrink-0 whitespace-nowrap rounded-full bg-cyan-500/10 px-2.5 py-1 text-xs font-semibold text-cyan-300">{{ $allowedTargets->count() }} target</span>
                </div>

                <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="overflow-x-auto focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-slate-800 bg-slate-800/70 font-semibold uppercase text-slate-400">
                            <tr>
                                <th class="px-5 py-3.5">Host / IP</th>
                                <th class="px-5 py-3.5">Cổng</th>
                                <th class="px-5 py-3.5">Ghi chú</th>
                                <th class="px-5 py-3.5">Người tạo</th>
                                <th class="px-5 py-3.5 text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 text-slate-300">
                            @forelse ($allowedTargets as $target)
                                <tr class="hover:bg-slate-800/30">
                                    <td class="px-5 py-4 font-mono font-semibold text-cyan-300">{{ $target->host }}</td>
                                    <td class="px-5 py-4 font-mono">{{ $target->port ?? 'Mọi cổng' }}</td>
                                    <td class="max-w-xs px-5 py-4 text-slate-400">{{ $target->note ?: '—' }}</td>
                                    <td class="px-5 py-4 text-slate-400">{{ $target->creator->name }}</td>
                                    <td class="px-5 py-4 text-right">
                                        <form method="POST" action="{{ route('admin.allowed-targets.destroy', $target) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" data-confirm="Xóa {{ $target->host }} khỏi allowlist?" class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-1.5 font-semibold text-rose-300 hover:bg-rose-500/20">Xóa</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-10 text-center text-slate-400">Chưa có target động nào. Các host mặc định từ môi trường vẫn được phép.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <aside class="space-y-5">
            <div class="rounded-2xl border border-emerald-500/20 bg-emerald-950/20 p-5">
                <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-300">Host cố định từ môi trường</h2>
                <p class="mt-2 text-xs leading-5 text-slate-400">Cấu hình bởi <code class="text-emerald-300">ALLOWED_TARGET_HOSTS</code>; không thể xóa tại giao diện.</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    @forelse ($environmentTargets as $target)
                        <span class="rounded-lg border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-1 font-mono text-xs text-emerald-300">{{ $target }}</span>
                    @empty
                        <span class="text-xs text-slate-400">Không có host cố định.</span>
                    @endforelse
                </div>
            </div>

            <div class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5" data-testid="m04-llm-card">
                <div class="flex items-center justify-between">
                    <h2 class="text-xs font-bold uppercase tracking-wider text-slate-300">LLM hệ thống (chỉ đọc)</h2>
                    @if ($llmDisabled)
                        <span data-testid="m04-llm-disabled-badge" class="inline-flex rounded-full border border-slate-500/30 bg-slate-500/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-slate-300">LLM đã tắt</span>
                    @elseif ($llmConfig['reachable'])
                        <span class="inline-flex rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-300">ai-service trực tuyến</span>
                    @else
                        <span class="inline-flex rounded-full border border-amber-500/20 bg-amber-500/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-amber-300">ai-service ngoại tuyến</span>
                    @endif
                </div>
                @if ($llmDisabled)
                    <p data-testid="m04-llm-disabled-note" class="mt-2 rounded-lg border border-slate-700 bg-slate-900/60 p-3 text-xs leading-5 text-slate-300">LLM đã tắt trên bản triển khai này: chỉ sinh test theo chiến lược RULE, LLM và HYBRID không khả dụng. Để bật, đặt <code class="font-mono">AI_LLM_PROVIDER=openai_compatible</code> cùng URL, API key và model của nhà cung cấp trong <code class="font-mono">ai-service/.env</code> rồi khởi động lại ai-service.</p>
                @endif
                <p class="mt-2 text-xs leading-5 text-slate-400">Nhà cung cấp (provider), phiên bản, mô hình (model), nhiệt độ (temperature) và seed mặc định do ai-service báo cáo qua <code class="font-mono">/health</code> (cấu hình tại <code class="font-mono">ai-service/.env</code>: <code class="font-mono">AI_LLM_MODEL</code>, <code class="font-mono">AI_LLM_TEMPERATURE</code>, <code class="font-mono">AI_LLM_SEED</code>). API key không bao giờ được hiển thị.</p>

                <div class="mt-4 space-y-2.5 text-xs">
                    <div class="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2">
                        <span class="text-slate-400">Nhà cung cấp LLM (provider)</span>
                        @if ($llmDisabled)
                            <span class="font-semibold text-slate-300">Đã tắt (chỉ RULE)</span>
                        @else
                            <span class="font-mono font-semibold text-emerald-400">{{ $llmConfig['provider'] ?? 'Không xác định' }}</span>
                        @endif
                    </div>
                    <div class="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2">
                        <span class="text-slate-400">Phiên bản ai-service</span>
                        <span class="font-mono font-medium text-slate-200">{{ $llmConfig['version'] ?? '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2">
                        <span class="text-slate-400">Mô hình (model)</span>
                        <span id="llm-model" class="font-mono font-semibold text-slate-200 truncate max-w-[180px]" title="{{ $llmDisabled ? '' : ($llmConfig['model'] ?? '') }}">{{ $llmDisabled ? '—' : ($llmConfig['model'] ?? 'Không xác định') }}</span>
                    </div>
                    <div class="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2">
                        <span class="text-slate-400">Nhiệt độ (temperature) / seed mặc định</span>
                        <span id="llm-temperature-seed" class="font-mono font-medium text-slate-300">{{ $llmDisabled ? '—' : ($llmConfig['temperature'] ?? '—').' / '.($llmConfig['seed'] ?? '—') }}</span>
                    </div>
                    <div class="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2">
                        <span class="text-slate-400">Driver kết nối (Web)</span>
                        <span class="font-mono font-medium text-slate-200">{{ $llmConfig['driver'] }}</span>
                    </div>
                    <div class="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2">
                        <span class="text-slate-400">URL ai-service</span>
                        <span class="font-mono font-medium text-slate-200 truncate max-w-[180px]" title="{{ $llmConfig['url'] }}">{{ $llmConfig['url'] }}</span>
                    </div>
                    <div class="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2">
                        <span class="text-slate-400">Thời gian chờ (timeout)</span>
                        <span class="font-mono font-medium text-slate-300">{{ $llmConfig['timeout'] }}s</span>
                    </div>
                    <div class="flex items-center justify-between rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2">
                        <span class="text-slate-400">Ngân sách sinh test (ai-service)</span>
                        <span id="llm-generate-budget" class="font-mono font-medium text-slate-300">{{ $llmConfig['generate_budget_seconds'] !== null ? $llmConfig['generate_budget_seconds'].'s' : '—' }}</span>
                    </div>
                </div>

                @if ($llmConfig['timeout_sufficient'] === false && ! $llmDisabled)
                    <div id="llm-timeout-warning" role="alert" class="mt-4 rounded-lg border border-amber-700 bg-amber-950/40 p-3 text-[11px] leading-relaxed text-amber-200">
                        <span class="font-semibold">Cấu hình thời gian không khớp:</span> AI_SERVICE_TIMEOUT ({{ $llmConfig['timeout'] }}s) nhỏ hơn ngân sách của ai-service + 60 giây ({{ $llmConfig['required_timeout'] }}s). Web sẽ từ chối sinh test bằng LLM/HYBRID. Đặt <code class="font-mono">AI_SERVICE_TIMEOUT={{ $llmConfig['required_timeout'] }}</code> trong <code class="font-mono">web/.env</code> rồi khởi động lại web và worker.
                    </div>
                @endif

                <div class="mt-4 rounded-lg border border-slate-800 bg-slate-900/50 p-3 text-[11px] leading-relaxed text-slate-400">
                    <span class="font-semibold text-slate-300">Lưu ý:</span> Màn hình sinh test và thực nghiệm để trống ô model theo mặc định, nên request gửi <code class="font-mono text-slate-300">model = null</code> và ai-service dùng model hệ thống. Chỉ khi người dùng nhập model, giá trị đó mới được gửi để ghi đè.
                </div>
            </div>
        </aside>
    </div>

    <div x-show="createModalOpen" x-cloak role="dialog" aria-modal="true" aria-labelledby="allowed-target-modal-title" x-modal-focus="createModalOpen" data-modal-trigger="#btn-create-allowed-target" @keydown.escape.window="createModalOpen = false" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4">
        <div class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm" @click="createModalOpen = false"></div>
        <div class="relative z-10 w-full max-w-lg space-y-5 rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <h2 id="allowed-target-modal-title" class="text-base font-bold text-white">THÊM TARGET VÀO ALLOWLIST</h2>
                    <p class="mt-1 text-xs text-slate-400">Chỉ nhập hostname hoặc IP, không kèm giao thức hay đường dẫn.</p>
                </div>
                <button type="button" @click="createModalOpen = false" aria-label="Đóng hộp thoại" class="text-slate-400 hover:text-white"><span aria-hidden="true">✕</span></button>
            </div>
            <form method="POST" action="{{ route('admin.allowed-targets.store') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="_form" value="create-allowed-target">
                <x-form-errors :when="$createErrors" />
                <div>
                    <label for="allowed-target-host" class="mb-1.5 block text-xs font-semibold text-slate-300">Host / địa chỉ IP <span class="text-rose-400">*</span></label>
                    <input id="allowed-target-host" name="host" required maxlength="255" value="{{ old('host') }}" autocomplete="off" @invalid('host', 'allowed-target-host-error', $createErrors) placeholder="staging.internal.lan" class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 font-mono text-sm text-white outline-none focus:ring-2 focus:ring-indigo-500">
                    <x-field-error field="host" id="allowed-target-host-error" :when="$createErrors" />
                </div>
                <div>
                    <label for="allowed-target-port" class="mb-1.5 block text-xs font-semibold text-slate-300">Cổng</label>
                    <input id="allowed-target-port" name="port" type="number" min="1" max="65535" value="{{ old('port') }}" @invalid('port', 'allowed-target-port-error', $createErrors) placeholder="Để trống nếu cho phép mọi cổng" class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 font-mono text-sm text-white outline-none focus:ring-2 focus:ring-indigo-500">
                    <x-field-error field="port" id="allowed-target-port-error" :when="$createErrors" />
                </div>
                <div>
                    <label for="allowed-target-note" class="mb-1.5 block text-xs font-semibold text-slate-300">Ghi chú</label>
                    <textarea id="allowed-target-note" name="note" rows="3" maxlength="1000" placeholder="Sandbox API nội bộ..." class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 text-sm text-white outline-none focus:ring-2 focus:ring-indigo-500" @invalid('note', 'allowed-target-note-error', $createErrors)>{{ old('note') }}</textarea>
                    <x-field-error field="note" id="allowed-target-note-error" :when="$createErrors" />
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-800 pt-4">
                    <button type="button" @click="createModalOpen = false" class="rounded-xl border border-slate-700 px-4 py-2 text-xs font-semibold text-slate-300">Hủy</button>
                    <button id="btn-submit-allowed-target" type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-md shadow-indigo-600/30 hover:bg-indigo-500">Thêm vào allowlist</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
