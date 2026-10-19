@extends('layouts.app')

@section('title', 'Quy tắc nghiệp vụ — ' . $project->name)

@php
    $createErrors = $errors->any() && old('_form') === 'create';
    $editErrors = $errors->any() && old('_form') === 'edit';
@endphp
@if ($createErrors || $editErrors)
    @section('inline_form_errors', '1')
@endif

@section('content')
@php $primaryButton = \App\Support\AccentClasses::primaryButton(auth()->user()); @endphp
<div x-data="{
    createModalOpen: {{ $errors->any() && old('_form') === 'create' ? 'true' : (request()->has('create') ? 'true' : 'false') }},
    editModalOpen: {{ $errors->any() && old('_form') === 'edit' ? 'true' : 'false' }},
    editRule: @js([
        'id' => old('_form') === 'edit' && is_scalar(old('rule_id')) ? (string) old('rule_id') : '',
        'code' => old('_form') === 'edit' && is_scalar(old('code')) ? (string) old('code') : '',
        'endpoint_id' => old('_form') === 'edit' && is_scalar(old('endpoint_id')) ? (string) old('endpoint_id') : '',
        'description' => old('_form') === 'edit' && is_scalar(old('description')) ? (string) old('description') : '',
    ]),
    openEdit(rule) {
        this.editRule = {
            id: rule.id,
            code: rule.code,
            endpoint_id: rule.endpoint_id ? rule.endpoint_id : '',
            description: rule.description
        };
        this.editModalOpen = true;
    }
}" class="space-y-6">

    <!-- Breadcrumb & Top Navigation -->
    <nav aria-label="Đường dẫn" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400 min-w-0 break-all">
        <a href="{{ route('projects.index') }}" class="hover:text-white transition-colors">Dự án</a>
        <span>/</span>
        <a href="{{ route('projects.suites.index', $project) }}" class="hover:text-white transition-colors truncate max-w-xs">{{ $project->name }}</a>
        <span>/</span>
        <span data-testid="breadcrumb-current" class="font-semibold {{ \App\Support\AccentClasses::label(auth()->user()) }}" aria-current="page">Quy tắc nghiệp vụ</span>
    </nav>

    <!-- Header Section (M08 Wireframe) -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-800 pb-5">
        <div>
            <div class="flex items-center gap-2.5">
                <span class="text-xs text-slate-400">DỰ ÁN: {{ $project->name }}</span>
            </div>
            <h1 class="mt-1 text-xl font-bold tracking-tight text-white sm:text-2xl">
                Quy tắc nghiệp vụ
            </h1>
            <p class="text-xs text-slate-400 mt-1 max-w-3xl leading-relaxed">
                Ràng buộc nghiệp vụ viết bằng ngôn ngữ tự nhiên, dùng làm ngữ cảnh khi sinh test biên và luồng nghiệp vụ nhiều bước bằng chiến lược LLM và HYBRID.
            </p>
        </div>

        <div class="flex items-center gap-3 shrink-0">
            <a href="{{ route('projects.specs.index', $project) }}"
               class="px-3.5 py-2 rounded-xl border border-slate-700 bg-slate-800 hover:bg-slate-750 text-slate-300 hover:text-white text-xs font-semibold flex items-center gap-2 transition-all">
                <svg aria-hidden="true" class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                Xem đặc tả OpenAPI
            </a>

            @can('create', [\App\Models\BusinessRule::class, $project])
                <button type="button"
                        id="btn-create-rule"
                        @click="createModalOpen = true"
                        class="{{ \App\Support\AccentClasses::headerCta(auth()->user()) }}">
                    <span aria-hidden="true" class="text-base leading-none">+</span>
                    Thêm quy tắc mới
                </button>
            @endcan
        </div>
    </div>

    <!-- KPI Statistics (Stats Bar) -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="rounded-2xl border border-slate-800 bg-slate-850 p-4 shadow-lg flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-slate-400">Tổng số quy tắc</p>
                <p class="text-2xl font-black text-white mt-1">{{ number_format($stats['total']) }}</p>
            </div>
            <div class="w-10 h-10 rounded-xl border flex items-center justify-center {{ \App\Support\AccentClasses::iconTile(auth()->user()) }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-800 bg-slate-850 p-4 shadow-lg flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-slate-400">Áp dụng toàn bộ API (Global)</p>
                <p class="text-2xl font-black text-cyan-300 mt-1">{{ number_format($stats['global']) }}</p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                </svg>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-800 bg-slate-850 p-4 shadow-lg flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-slate-400">Quy tắc theo Endpoint</p>
                <p class="text-2xl font-black text-amber-300 mt-1">{{ number_format($stats['endpoint_specific']) }}</p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Filter & Search Bar -->
    <div class="rounded-2xl border border-slate-800 bg-slate-850 p-4 shadow-xl">
        <form method="GET" action="{{ route('projects.business-rules.index', $project) }}" class="flex flex-col md:flex-row items-center gap-3">
            <div class="relative flex-1 w-full">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
                <label for="rule-search" class="sr-only">Tìm kiếm quy tắc nghiệp vụ</label>
                <input type="text"
                       id="rule-search"
                       name="search"
                       value="{{ $filters['search'] }}"
                       placeholder="Tìm kiếm theo mã luật hoặc nội dung quy tắc..."
                       class="w-full pl-10 pr-4 py-2 rounded-xl bg-slate-900 border border-slate-700/80 text-xs text-white placeholder-slate-500 focus:outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }} {{ \App\Support\AccentClasses::focusBorder(auth()->user()) }} transition-all">
            </div>

            <div class="w-full md:w-80 shrink-0">
                <label for="rule-endpoint-filter" class="sr-only">Lọc theo endpoint</label>
                <select id="rule-endpoint-filter"
                        name="endpoint_id"
                        class="w-full py-2 px-3 rounded-xl bg-slate-900 border border-slate-700/80 text-xs text-slate-200 focus:outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }} {{ \App\Support\AccentClasses::focusBorder(auth()->user()) }} transition-all">
                    <option value="">-- Tất cả Endpoint & Toàn bộ API --</option>
                    <option value="global" {{ $filters['endpoint_id'] === 'global' ? 'selected' : '' }}>
                        🌐 (Áp dụng toàn bộ API)
                    </option>
                    @include('business-rules._endpoint-options', ['endpoints' => $endpoints, 'selected' => (string) $filters['endpoint_id']])
                </select>
            </div>

            <div class="flex items-center gap-2 w-full md:w-auto shrink-0">
                <button type="submit"
                        class="flex-1 md:flex-none px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-white text-xs font-semibold transition-all">
                    Lọc
                </button>
                @if ($filters['search'] !== '' || $filters['endpoint_id'] !== '')
                    <a href="{{ route('projects.business-rules.index', $project) }}"
                       class="flex-1 md:flex-none px-3.5 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-400 hover:text-white border border-slate-800 text-xs font-medium transition-all text-center">
                        Xóa lọc
                    </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Business Rules Table (M08 Wireframe) -->
    <div class="rounded-2xl border border-slate-800 bg-slate-850 shadow-xl overflow-hidden">
        @if ($businessRules->isEmpty())
            {{-- Full-width card outside the horizontally scrolling table, so the message and its
                 "xóa lọc" link stay readable at 375 px (S14-06 live QA r4, like M05/M09). --}}
            <div data-testid="business-rules-empty-state" class="px-5 py-12 text-center text-slate-400">
                <div class="max-w-md mx-auto space-y-3">
                    <div aria-hidden="true" class="w-12 h-12 mx-auto rounded-full bg-slate-800 flex items-center justify-center text-slate-400">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    @if ($filters['search'] !== '' || $filters['endpoint_id'] !== '')
                        {{-- Rules hidden by the filter: point at the filter, not at creating data (S14-06, like M05/M09). --}}
                        <p data-testid="business-rules-empty-filtered" class="text-sm font-semibold text-slate-300">Không có quy tắc nào khớp bộ lọc</p>
                        <p class="text-xs text-slate-400">
                            Hãy đổi điều kiện lọc hoặc
                            <a href="{{ route('projects.business-rules.index', $project) }}" class="font-semibold text-slate-200 underline underline-offset-2 hover:text-white">xóa lọc</a>.
                        </p>
                    @else
                        <p data-testid="business-rules-empty" class="text-sm font-semibold text-slate-300">Chưa có quy tắc nghiệp vụ nào</p>
                        <p class="text-xs text-slate-400">
                            Thêm các quy tắc như ràng buộc tiền tệ, trạng thái chuyển đổi, hoặc logic bảo mật để cấp ngữ cảnh cho mô hình LLM khi sinh ca kiểm thử.
                        </p>
                        @can('create', [\App\Models\BusinessRule::class, $project])
                            <button type="button"
                                    @click="createModalOpen = true"
                                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-semibold shadow-lg transition-all mt-2 focus-visible:outline-none focus-visible:ring-2 {{ $primaryButton }}">
                                Thêm quy tắc đầu tiên
                            </button>
                        @endcan
                    @endif
                </div>
            </div>
        @else
        <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="relative overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
            <table data-testid="business-rules-table" class="w-full min-w-[48rem] text-left text-xs">
                <thead class="bg-slate-900/90 text-[11px] text-slate-400 uppercase tracking-wider border-b border-slate-800">
                    <tr>
                        <th class="whitespace-nowrap px-5 py-3.5 font-bold w-40">Mã luật</th>
                        <th class="px-5 py-3.5 font-bold w-64">Endpoint áp dụng</th>
                        <th class="min-w-[14rem] px-5 py-3.5 font-bold">Mô tả quy tắc (Ngôn ngữ tự nhiên)</th>
                        <th class="whitespace-nowrap px-5 py-3.5 font-bold text-right w-36">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-normal text-slate-300">
                    @foreach ($businessRules as $rule)
                        <tr class="hover:bg-slate-800/40 transition-colors">
                            <!-- Mã luật -->
                            <td data-testid="business-rule-code" class="whitespace-nowrap px-5 py-4 align-top">
                                <span class="inline-block whitespace-nowrap px-2.5 py-1 rounded-lg text-xs font-mono font-bold border {{ \App\Support\AccentClasses::codeBadge(auth()->user()) }}">
                                    {{ $rule->code }}
                                </span>
                            </td>

                            <!-- Endpoint áp dụng -->
                            <td class="px-5 py-4 align-top">
                                @if ($rule->endpoint)
                                    <div class="flex items-center gap-2 flex-wrap">
                                        @php
                                            $rawMethod = is_object($rule->endpoint->method) ? $rule->endpoint->method->value : (string) $rule->endpoint->method;
                                            $method = strtoupper($rawMethod);
                                            $colorMap = [
                                                'GET' => 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
                                                'POST' => 'bg-blue-500/20 text-blue-300 border-blue-500/30',
                                                'PUT' => 'bg-amber-500/20 text-amber-300 border-amber-500/30',
                                                'PATCH' => 'bg-purple-500/20 text-purple-300 border-purple-500/30',
                                                'DELETE' => 'bg-rose-500/20 text-rose-300 border-rose-500/30',
                                            ];
                                            $methodClass = $colorMap[$method] ?? 'bg-slate-700 text-slate-200 border-slate-600';
                                        @endphp
                                        <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold border {{ $methodClass }}">
                                            {{ $method }}
                                        </span>
                                        <span class="font-mono text-[11px] text-slate-200 break-all">
                                            {{ $rule->endpoint->path }}
                                        </span>
                                    </div>
                                    @if ($rule->endpoint->apiSpec)
                                        <p class="text-[11px] text-slate-400 mt-1">Phiên bản đặc tả: {{ $rule->endpoint->apiSpec->version_label }}</p>
                                    @endif
                                    @if ($rule->endpoint->summary)
                                        <p class="text-[11px] text-slate-400 mt-1 line-clamp-1">
                                            {{ $rule->endpoint->summary }}
                                        </p>
                                    @endif
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-medium bg-cyan-500/10 text-cyan-300 border border-cyan-500/20">
                                        <svg aria-hidden="true" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                                        </svg>
                                        (Áp dụng toàn bộ API)
                                    </span>
                                @endif
                            </td>

                            <!-- Mô tả quy tắc -->
                            <td class="min-w-[14rem] px-5 py-4 align-top">
                                <p class="text-xs text-slate-200 leading-relaxed whitespace-pre-line break-words">
                                    {{ $rule->description }}
                                </p>
                            </td>

                            <!-- Thao tác -->
                            <td class="whitespace-nowrap px-5 py-4 align-top text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $rule)
                                        <button type="button" data-testid="business-rule-edit-button"
                                                @click="openEdit(@js([
                                                    'id' => (string) $rule->id,
                                                    'code' => $rule->code,
                                                    'endpoint_id' => (string) ($rule->endpoint_id ?? ''),
                                                    'description' => $rule->description,
                                                ]))"
                                                aria-label="Sửa quy tắc {{ $rule->code }}"
                                                class="px-2.5 py-1 rounded-lg border text-xs font-semibold transition-all {{ \App\Support\AccentClasses::secondaryButton(auth()->user()) }}">
                                            Sửa
                                        </button>
                                    @endcan

                                    @can('delete', $rule)
                                        <form method="POST"
                                              action="{{ route('projects.business-rules.destroy', [$project, $rule]) }}"
                                              data-confirm="Bạn có chắc chắn muốn xóa quy tắc {{ $rule->code }} không?">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="px-2.5 py-1 rounded-lg border border-rose-500/30 bg-rose-500/10 text-rose-300 hover:bg-rose-500/20 text-xs font-semibold transition-all">
                                                Xóa
                                            </button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        @if ($businessRules->hasPages())
            <div class="p-4 border-t border-slate-800 bg-slate-900/60">
                {{ $businessRules->links() }}
            </div>
        @endif
    </div>

    <!-- MODAL: THÊM QUY TẮC MỚI (M08 Wireframe Quick Form) -->
    <div x-show="createModalOpen"
         x-cloak
         role="dialog" aria-modal="true" aria-labelledby="create-rule-modal-title" x-modal-focus="createModalOpen" data-modal-trigger="#btn-create-rule" @keydown.escape.window="createModalOpen = false" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4">
        <div class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm transition-opacity" @click="createModalOpen = false"></div>

        <div class="relative z-10 w-full max-w-2xl rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl space-y-5"
             x-data="{ codeVal: @js(old('_form') === 'create' && is_scalar(old('code')) ? (string) old('code') : ''), descVal: @js(old('_form') === 'create' && is_scalar(old('description')) ? (string) old('description') : '') }">

            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <h2 id="create-rule-modal-title" class="text-base font-bold text-white flex items-center gap-2">
                        <svg aria-hidden="true" class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        THÊM QUY TẮC NGHIỆP VỤ MỚI
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">Nhập ràng buộc nghiệp vụ bằng ngôn ngữ tự nhiên để cấp ngữ cảnh cho AI.</p>
                </div>
                <button type="button" @click="createModalOpen = false" aria-label="Đóng hộp thoại" class="text-slate-400 hover:text-white transition-colors">
                    <svg aria-hidden="true" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <form method="POST" action="{{ route('projects.business-rules.store', $project) }}" class="space-y-4">
                @csrf
                <input type="hidden" name="_form" value="create">
                <x-form-errors :when="$createErrors" />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Mã luật -->
                    <div>
                        <label for="create-code" class="block text-xs font-semibold text-slate-300 mb-1.5">
                            Mã luật <span class="text-rose-400">*</span>
                        </label>
                        <input type="text"
                               id="create-code"
                               name="code"
                               x-model="codeVal"
                               @input="codeVal = $event.target.value.toUpperCase()"
                               required
                               maxlength="50"
                               @invalid('code', 'create-code-error', $createErrors)
                               placeholder="VD: BR-EXAMPLE-01"
                               class="w-full px-3 py-2 rounded-xl bg-slate-800 border border-slate-700 text-sm font-mono text-white placeholder-slate-500 focus:outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }} {{ \App\Support\AccentClasses::focusBorder(auth()->user()) }}">
                        <p class="text-[11px] text-slate-400 mt-1">Chữ hoa, số, dấu gạch nối (-), gạch dưới (_).</p>
                        <x-field-error field="code" id="create-code-error" :when="$createErrors" />
                    </div>

                    <!-- Endpoint áp dụng -->
                    <div>
                        <label for="create-endpoint-id" class="block text-xs font-semibold text-slate-300 mb-1.5">
                            Endpoint áp dụng
                        </label>
                        <select id="create-endpoint-id"
                                name="endpoint_id"
                                @invalid('endpoint_id', 'create-endpoint-error', $createErrors)
                                class="w-full px-3 py-2 rounded-xl bg-slate-800 border border-slate-700 text-xs text-slate-200 focus:outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }} {{ \App\Support\AccentClasses::focusBorder(auth()->user()) }}">
                            <option value="">🌐 (Áp dụng toàn bộ API)</option>
                            @php
                                $rawEndpoint = old('endpoint_id', request('endpoint_id'));
                                $selectedEndpoint = is_scalar($rawEndpoint) ? (string) $rawEndpoint : '';
                            @endphp
                            @include('business-rules._endpoint-options', ['endpoints' => $endpoints, 'selected' => $selectedEndpoint])
                        </select>
                        <p class="text-[11px] text-slate-400 mt-1">Để trống nếu luật này áp dụng cho toàn bộ API. Endpoint được nhóm theo phiên bản đặc tả.</p>
                        <x-field-error field="endpoint_id" id="create-endpoint-error" :when="$createErrors" />
                    </div>
                </div>

                <!-- Mô tả luật -->
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label for="create-description" class="block text-xs font-semibold text-slate-300">
                            Mô tả quy tắc (Ngôn ngữ tự nhiên) <span class="text-rose-400">*</span>
                        </label>
                        <span class="text-[11px] text-slate-400 font-mono">
                            <span x-text="descVal ? descVal.length : 0">0</span>/5000 ký tự (Tối đa 10KB)
                        </span>
                    </div>
                    <textarea id="create-description"
                              name="description"
                              x-model="descVal"
                              required
                              rows="4"
                              maxlength="5000"
                              @invalid('description', 'create-description-error', $createErrors)
                              placeholder="VD: Tổng giá trị đơn hàng vượt quá 50 triệu bắt buộc phải thanh toán trước (không hỗ trợ COD). Hoặc: Số lượng mỗi mặt hàng trong đơn hàng phải lớn hơn 0 và không vượt quá số tồn kho hiện có."
                              class="w-full px-3.5 py-2.5 rounded-xl bg-slate-800 border border-slate-700 text-xs text-white placeholder-slate-500 focus:outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }} {{ \App\Support\AccentClasses::focusBorder(auth()->user()) }} leading-relaxed"></textarea>
                    <x-field-error field="description" id="create-description-error" :when="$createErrors" />
                </div>

                <!-- Quick Tips for LLM Context -->
                <div class="rounded-xl border border-indigo-500/20 bg-indigo-500/5 p-3 text-[11px] text-slate-300 space-y-1">
                    <p class="font-semibold text-indigo-300">Mẹo viết quy tắc hiệu quả khi sinh test bằng AI:</p>
                    <ul class="list-disc list-inside text-slate-400 space-y-0.5">
                        <li>Mô tả rõ ràng điều kiện biên (giá trị tối đa, tối thiểu, giá trị âm, độ dài chuỗi).</li>
                        <li>Nêu rõ trạng thái hợp lệ và không hợp lệ (ví dụ: đơn hàng CANCELLED thì không cho thanh toán).</li>
                        <li>Quy định rõ mã lỗi kỳ vọng nếu có (ví dụ: trả về 422 khi kho không đủ hàng).</li>
                    </ul>
                </div>

                <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
                    <button type="button"
                            @click="createModalOpen = false"
                            class="px-4 py-2 rounded-xl border border-slate-700 bg-slate-800 hover:bg-slate-750 text-slate-300 text-xs font-semibold transition-all">
                        Hủy
                    </button>
                    <button type="submit"
                            class="px-5 py-2 rounded-xl text-xs font-semibold shadow-lg transition-all focus-visible:outline-none focus-visible:ring-2 {{ $primaryButton }}">
                        Lưu quy tắc
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: CHỈNH SỬA QUY TẮC -->
    <div x-show="editModalOpen"
         x-cloak
         role="dialog" aria-modal="true" aria-labelledby="edit-rule-modal-title" x-modal-focus="editModalOpen" @keydown.escape.window="editModalOpen = false" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4">
        <div class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm transition-opacity" @click="editModalOpen = false"></div>

        <div class="relative z-10 w-full max-w-2xl rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl space-y-5">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <h2 id="edit-rule-modal-title" class="text-base font-bold text-white flex items-center gap-2">
                        <svg aria-hidden="true" class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        CHỈNH SỬA QUY TẮC NGHIỆP VỤ #<span x-text="editRule.code"></span>
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">Cập nhật nội dung mô tả hoặc thay đổi endpoint áp dụng.</p>
                </div>
                <button type="button" @click="editModalOpen = false" aria-label="Đóng hộp thoại" class="text-slate-400 hover:text-white transition-colors">
                    <svg aria-hidden="true" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <form method="POST"
                  :action="'{{ url('projects/' . $project->id . '/business-rules') }}/' + editRule.id"
                  class="space-y-4">
                @csrf
                @method('PATCH')
                <input type="hidden" name="_form" value="edit">
                <input type="hidden" name="rule_id" :value="editRule.id">
                <x-form-errors :when="$editErrors" />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Mã luật -->
                    <div>
                        <label for="edit-code" class="block text-xs font-semibold text-slate-300 mb-1.5">
                            Mã luật <span class="text-rose-400">*</span>
                        </label>
                        <input type="text"
                               id="edit-code"
                               name="code"
                               x-model="editRule.code"
                               @input="editRule.code = $event.target.value.toUpperCase()"
                               required
                               maxlength="50"
                               @invalid('code', 'edit-code-error', $editErrors)
                               class="w-full px-3 py-2 rounded-xl bg-slate-800 border border-slate-700 text-sm font-mono text-white placeholder-slate-500 focus:outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }} {{ \App\Support\AccentClasses::focusBorder(auth()->user()) }}">
                        <x-field-error field="code" id="edit-code-error" :when="$editErrors" />
                    </div>

                    <!-- Endpoint áp dụng -->
                    <div>
                        <label for="edit-endpoint-id" class="block text-xs font-semibold text-slate-300 mb-1.5">
                            Endpoint áp dụng
                        </label>
                        <select id="edit-endpoint-id"
                                name="endpoint_id"
                                x-model="editRule.endpoint_id"
                                @invalid('endpoint_id', 'edit-endpoint-error', $editErrors)
                                class="w-full px-3 py-2 rounded-xl bg-slate-800 border border-slate-700 text-xs text-slate-200 focus:outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }} {{ \App\Support\AccentClasses::focusBorder(auth()->user()) }}">
                            <option value="">🌐 (Áp dụng toàn bộ API)</option>
                            @include('business-rules._endpoint-options', ['endpoints' => $endpoints, 'selected' => null])
                        </select>
                        <x-field-error field="endpoint_id" id="edit-endpoint-error" :when="$editErrors" />
                    </div>
                </div>

                <!-- Mô tả luật -->
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label for="edit-description" class="block text-xs font-semibold text-slate-300">
                            Mô tả quy tắc (Ngôn ngữ tự nhiên) <span class="text-rose-400">*</span>
                        </label>
                        <span class="text-[11px] text-slate-400 font-mono">
                            <span x-text="editRule.description ? editRule.description.length : 0">0</span>/5000 ký tự (Tối đa 10KB)
                        </span>
                    </div>
                    <textarea id="edit-description"
                              name="description"
                              x-model="editRule.description"
                              required
                              rows="4"
                              maxlength="5000"
                              @invalid('description', 'edit-description-error', $editErrors)
                              class="w-full px-3.5 py-2.5 rounded-xl bg-slate-800 border border-slate-700 text-xs text-white placeholder-slate-500 focus:outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }} {{ \App\Support\AccentClasses::focusBorder(auth()->user()) }} leading-relaxed"></textarea>
                    <x-field-error field="description" id="edit-description-error" :when="$editErrors" />
                </div>

                <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
                    <button type="button"
                            @click="editModalOpen = false"
                            class="px-4 py-2 rounded-xl border border-slate-700 bg-slate-800 hover:bg-slate-750 text-slate-300 text-xs font-semibold transition-all">
                        Hủy
                    </button>
                    <button type="submit"
                            class="px-5 py-2 rounded-xl text-xs font-semibold shadow-lg transition-all focus-visible:outline-none focus-visible:ring-2 {{ $primaryButton }}">
                        Cập nhật quy tắc
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
