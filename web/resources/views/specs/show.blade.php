@extends('layouts.app')

@section('title', 'Danh sách endpoint — ' . $project->name . ' ' . $spec->version_label)

@section('content')
@php
    // Count pill, OpenAPI version, operationId, parameter names and section icons follow the shell
    // accent (S14-06 live QA r7).
    $accentText = \App\Support\AccentClasses::accentText(auth()->user());
    $accentLabel = \App\Support\AccentClasses::label(auth()->user());
@endphp
<div class="space-y-6" x-data="{
    endpoints: {{ Js::from($endpoints) }},
    selectedId: {{ $endpoints->isNotEmpty() ? $endpoints->first()->id : 'null' }},
    searchQuery: '',
    methodFilter: 'ALL',

    init() {
        this.$watch('methodFilter', () => this.syncSelected());
        this.$watch('searchQuery', () => this.syncSelected());
    },

    syncSelected() {
        const list = this.filteredEndpoints;
        if (!list.some(ep => ep.id === this.selectedId)) {
            this.selectedId = list.length > 0 ? list[0].id : null;
        }
    },

    get filteredEndpoints() {
        return this.endpoints.filter(ep => {
            const matchesMethod = this.methodFilter === 'ALL' || ep.method === this.methodFilter;
            const q = this.searchQuery.trim().toLowerCase();
            const matchesSearch = !q ||
                ep.path.toLowerCase().includes(q) ||
                (ep.summary && ep.summary.toLowerCase().includes(q)) ||
                (ep.operation_id && ep.operation_id.toLowerCase().includes(q));
            return matchesMethod && matchesSearch;
        });
    },

    get selectedEndpoint() {
        return this.endpoints.find(ep => ep.id === this.selectedId) || null;
    },

    getMethodClass(method) {
        switch(method) {
            case 'GET': return 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30';
            case 'POST': return 'bg-sky-500/15 text-sky-400 border-sky-500/30';
            case 'PUT': return 'bg-amber-500/15 text-amber-400 border-amber-500/30';
            case 'PATCH': return 'bg-purple-500/15 text-purple-400 border-purple-500/30';
            case 'DELETE': return 'bg-rose-500/15 text-rose-400 border-rose-500/30';
            default: return 'bg-slate-500/15 text-slate-400 border-slate-500/30';
        }
    }
}">
    <!-- Breadcrumb & Top Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-slate-800 pb-5">
        <div>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400 mb-1 min-w-0 break-all">
                <a href="{{ route('projects.index') }}" class="transition-colors {{ \App\Support\AccentClasses::breadcrumbLink(auth()->user()) }}">Dự án</a>
                <span class="text-slate-400">/</span>
                <span class="text-slate-300 font-medium">{{ $project->name }}</span>
                <span class="text-slate-400">/</span>
                <a href="{{ route('projects.specs.index', $project) }}" class="transition-colors {{ \App\Support\AccentClasses::breadcrumbLink(auth()->user()) }}">Đặc tả OpenAPI</a>
                <span class="text-slate-400">/</span>
                <span data-testid="breadcrumb-current" class="font-medium {{ \App\Support\AccentClasses::label(auth()->user()) }}" aria-current="page">{{ $spec->version_label }}</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-3">
                <span class="p-2 rounded-xl border shadow-inner {{ \App\Support\AccentClasses::iconTile(auth()->user()) }}">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                    </svg>
                </span>
                Danh sách endpoint
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Chi tiết schema của từng endpoint · Tệp: <strong class="text-slate-200">{{ $spec->source_filename }}</strong>
                • Phiên bản OpenAPI: <span data-testid="spec-openapi-version" class="{{ $accentLabel }} font-mono font-semibold">{{ $spec->displayOpenApiVersion() }}</span>
                • Định dạng: <span class="uppercase text-slate-300 font-semibold">{{ $spec->format }}</span>
            </p>
        </div>

        <div class="flex items-center gap-3">
            <span data-testid="spec-endpoint-count" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border {{ \App\Support\AccentClasses::codeBadge(auth()->user()) }}">
                <span aria-hidden="true" class="w-2 h-2 rounded-full {{ \App\Support\AccentClasses::dot(auth()->user()) }}"></span>
                Tổng cộng {{ $endpoints->count() }} endpoint
            </span>
            <a href="{{ route('projects.specs.index', $project) }}"
               class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold border border-slate-700 transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Quay lại danh sách đặc tả
            </a>
        </div>
    </div>

    @if ($spec->status === \App\Enums\ApiSpecStatus::ParseFailed)
        <div role="alert" data-testid="spec-parse-failed" class="rounded-2xl border border-rose-500/40 bg-rose-950/40 p-5 space-y-2">
            <p class="text-sm font-semibold text-rose-200">Không phân tích được đặc tả này nên không có endpoint và không dùng được để sinh test.</p>
            @include('specs._parse-errors', ['errorsList' => $spec->parse_errors])
            <p class="text-xs text-slate-300">Hãy sửa tệp rồi tải lên lại ở trang Đặc tả OpenAPI.</p>
        </div>
    @endif

    <!-- Server-rendered Semantic Accessibility & SEO List -->
    <div class="sr-only">
        @foreach($endpoints as $ep)
            <div>{{ $ep->method }} {{ $ep->path }} {{ $ep->operation_id }} {{ $ep->summary }}</div>
        @endforeach
    </div>

    <!-- Master-Detail 2-Column Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

        <!-- Column Left: Endpoints List & Filter (4 cols) -->
        <div class="lg:col-span-5 rounded-2xl bg-slate-850 border border-slate-800 shadow-xl overflow-hidden flex flex-col h-[750px]">
            <!-- Filters header -->
            <div class="p-4 border-b border-slate-800 bg-slate-900/60 space-y-3 shrink-0">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                        <svg class="w-4 h-4 {{ $accentText }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                        </svg>
                        Tìm kiếm & Lọc Endpoint
                    </span>
                    <span class="text-[11px] text-slate-400" x-text="filteredEndpoints.length + ' / ' + endpoints.length + ' endpoint'"></span>
                </div>

                <!-- Search Input -->
                <div class="relative">
                    <label for="endpoint-search" class="sr-only">Tìm kiếm endpoint</label>
                    <input type="text"
                           id="endpoint-search"
                           x-model="searchQuery"
                           placeholder="Tìm kiếm path, summary, operationId..."
                           class="w-full pl-9 pr-4 py-2 rounded-xl bg-slate-900 border border-slate-700/80 text-white placeholder-slate-500 text-xs focus:outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }} focus:border-transparent transition-all shadow-inner">
                    <svg class="w-4 h-4 text-slate-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>

                <!-- Method Filter Pills -->
                <div class="flex flex-wrap gap-1.5 pt-1">
                    <button type="button"
                            @click="methodFilter = 'ALL'"
                            :aria-pressed="(methodFilter === 'ALL').toString()"
                            :class="methodFilter === 'ALL' ? @js(\App\Support\AccentClasses::activeSegment(auth()->user()).' font-bold') : 'bg-slate-800 text-slate-400 hover:text-slate-200'"
                            class="px-2.5 py-1 rounded-lg text-[11px] font-semibold transition-all">
                        Tất cả
                    </button>
                    @foreach(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method)
                        <button type="button"
                                @click="methodFilter = '{{ $method }}'"
                                :aria-pressed="(methodFilter === '{{ $method }}').toString()"
                                :class="methodFilter === '{{ $method }}' ? '{{ $method === 'GET' ? 'bg-emerald-600 text-white' : ($method === 'POST' ? 'bg-sky-600 text-white' : ($method === 'PUT' ? 'bg-amber-600 text-white' : ($method === 'PATCH' ? 'bg-purple-600 text-white' : 'bg-rose-600 text-white'))) }} font-bold shadow' : 'bg-slate-800 text-slate-400 hover:text-slate-200'"
                                class="px-2.5 py-1 rounded-lg text-[11px] font-semibold transition-all">
                            {{ $method }}
                        </button>
                    @endforeach
                </div>
            </div>

            <!-- Endpoints Scrollable List -->
            <div tabindex="0" role="region" aria-label="Danh sách endpoint" class="overflow-y-auto flex-1 divide-y divide-slate-800/60 p-2 space-y-1 focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
                <template x-for="ep in filteredEndpoints" :key="ep.id">
                    <button type="button" @click="selectedId = ep.id"
                         :aria-pressed="(selectedId === ep.id).toString()"
                         :class="selectedId === ep.id ? @js(\App\Support\AccentClasses::selectedCard(auth()->user())) : 'bg-slate-900/40 border-transparent hover:bg-slate-800/50 hover:border-slate-700/60'"
                         class="block w-full text-left p-3 rounded-xl border transition-all cursor-pointer group">
                        <span class="flex items-center gap-2 mb-1">
                            <span :class="getMethodClass(ep.method)"
                                  class="px-2 py-0.5 rounded-md text-[10px] font-extrabold tracking-wider border font-mono shrink-0"
                                  x-text="ep.method"></span>
                            <span class="font-mono text-xs font-semibold text-slate-200 truncate group-hover:text-white"
                                  x-text="ep.path"></span>
                        </span>
                        <span class="flex items-center justify-between text-[11px] text-slate-400 pl-1">
                            <span class="truncate max-w-[240px]" x-text="ep.summary || ep.operation_id || 'Không có mô tả'"></span>
                            <span x-show="selectedId === ep.id" aria-hidden="true" class="text-xs shrink-0 font-bold {{ \App\Support\AccentClasses::accentText(auth()->user()) }}">●</span>
                        </span>
                    </button>
                </template>

                <div x-show="filteredEndpoints.length === 0" class="p-8 text-center text-xs text-slate-400">
                    Không tìm thấy endpoint nào phù hợp với bộ lọc.
                </div>
            </div>
        </div>

        <!-- Column Right: Endpoint Detail & Schema Explorer (7 cols) -->
        <div class="lg:col-span-7 rounded-2xl bg-slate-850 border border-slate-800 shadow-xl overflow-hidden flex flex-col h-[750px]">
            <!-- No Selection State -->
            <div x-show="!selectedEndpoint" class="m-auto text-center p-12 text-slate-400">
                <svg class="w-12 h-12 mx-auto mb-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122" />
                </svg>
                <p class="text-sm font-semibold">Chọn một endpoint từ danh sách bên trái để xem chi tiết</p>
            </div>

            <!-- Detail Content -->
            <div x-show="selectedEndpoint" x-cloak class="flex flex-col h-full overflow-hidden">
                <!-- Endpoint Header Banner -->
                <div class="p-6 border-b border-slate-800 bg-slate-900/80 shrink-0 space-y-3">
                    <div class="flex items-center gap-3">
                        <span :class="selectedEndpoint ? getMethodClass(selectedEndpoint.method) : ''"
                              class="px-3 py-1 rounded-lg text-xs font-black tracking-wider border font-mono"
                              x-text="selectedEndpoint ? selectedEndpoint.method : ''"></span>
                        <h2 class="text-lg font-bold font-mono text-white break-all"
                            x-text="selectedEndpoint ? selectedEndpoint.path : ''"></h2>
                    </div>

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
                        <div x-show="selectedEndpoint && selectedEndpoint.operation_id" class="flex items-center gap-1.5">
                            <span class="text-slate-400">Mã thao tác (operationId):</span>
                            <code data-testid="endpoint-operation-id" class="px-1.5 py-0.5 rounded bg-slate-800 {{ $accentLabel }} font-mono text-[11px]" x-text="selectedEndpoint ? selectedEndpoint.operation_id : ''"></code>
                        </div>
                    </div>

                    <div x-show="selectedEndpoint && (selectedEndpoint.summary || selectedEndpoint.description)" class="text-xs text-slate-300 bg-slate-800/40 p-3 rounded-xl border border-slate-700/50">
                        <p class="font-semibold text-slate-200" x-text="selectedEndpoint ? selectedEndpoint.summary : ''"></p>
                        <p class="text-slate-400 mt-1 whitespace-pre-line" x-text="selectedEndpoint ? selectedEndpoint.description : ''"></p>
                    </div>
                </div>

                <!-- Scrollable Body Tabs & Info -->
                <div tabindex="0" role="region" aria-label="Chi tiết endpoint" class="flex-1 overflow-y-auto p-6 space-y-6 focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">

                    <!-- 1. Parameters Section -->
                    <div class="space-y-3">
                        <h3 class="text-xs font-bold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                            <svg class="w-4 h-4 {{ $accentText }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14" />
                            </svg>
                            Tham số (Parameters)
                        </h3>

                        <template x-if="selectedEndpoint && selectedEndpoint.parameters && selectedEndpoint.parameters.length > 0">
                            <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="border border-slate-800 rounded-xl overflow-x-auto bg-slate-900/60 focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
                                <table class="w-full min-w-[480px] text-left text-xs">
                                    <thead class="bg-slate-800/80 text-[10px] text-slate-400 uppercase tracking-wider border-b border-slate-800">
                                        <tr>
                                            <th class="px-4 py-2">Tên</th>
                                            <th class="px-4 py-2">Vị trí (In)</th>
                                            <th class="px-4 py-2">Kiểu (Schema)</th>
                                            <th class="px-4 py-2">Bắt buộc</th>
                                            <th class="px-4 py-2">Mô tả</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-800/60 font-normal text-slate-300">
                                        <template x-for="p in selectedEndpoint.parameters" :key="p.name + p.in">
                                            <tr>
                                                <td class="px-4 py-2.5 font-mono font-semibold {{ $accentLabel }}" x-text="p.name"></td>
                                                <td class="px-4 py-2.5">
                                                    <span class="px-2 py-0.5 rounded text-[10px] bg-slate-800 font-mono text-slate-400" x-text="p.in"></span>
                                                </td>
                                                <td class="px-4 py-2.5 font-mono text-slate-400" x-text="(p.schema && p.schema.type) ? p.schema.type : 'any'"></td>
                                                <td class="px-4 py-2.5">
                                                    <span x-show="p.required" class="text-rose-400 font-semibold text-[10px]">Bắt buộc</span>
                                                    <span x-show="!p.required" class="text-slate-400 text-[10px]">Tùy chọn</span>
                                                </td>
                                                <td class="px-4 py-2.5 text-slate-400 text-[11px]" x-text="p.description || '—'"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>

                        <div x-show="!selectedEndpoint || !selectedEndpoint.parameters || selectedEndpoint.parameters.length === 0"
                             class="p-4 rounded-xl bg-slate-900/40 border border-slate-800 text-xs text-slate-400 italic">
                            Không có tham số nào được khai báo cho endpoint này.
                        </div>
                    </div>

                    <!-- 2. Request Body Section -->
                    <div class="space-y-3">
                        <h3 class="text-xs font-bold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                            <svg class="w-4 h-4 {{ $accentText }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            Thân yêu cầu (request body, application/json)
                        </h3>

                        <template x-if="selectedEndpoint && selectedEndpoint.request_body">
                            <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="p-4 rounded-xl bg-slate-900/90 border border-slate-800 font-mono text-xs text-slate-300 overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
                                <div class="flex items-center justify-between pb-2 mb-2 border-b border-slate-800 text-[11px] text-slate-400">
                                    <span>Trạng thái: <strong class="text-slate-200" x-text="selectedEndpoint.request_body.required ? 'Bắt buộc (Required)' : 'Tùy chọn'"></strong></span>
                                    <span>Mô tả: <span x-text="selectedEndpoint.request_body.description || 'N/A'"></span></span>
                                </div>
                                <pre class="text-[11px] {{ $accentLabel }} leading-relaxed" x-text="JSON.stringify(selectedEndpoint.request_body.content || selectedEndpoint.request_body, null, 2)"></pre>
                            </div>
                        </template>

                        <div x-show="!selectedEndpoint || !selectedEndpoint.request_body"
                             class="p-4 rounded-xl bg-slate-900/40 border border-slate-800 text-xs text-slate-400 italic">
                            Endpoint này không yêu cầu thân yêu cầu (request body).
                        </div>
                    </div>

                    <!-- 3. Responses Section -->
                    <div class="space-y-3">
                        <h3 class="text-xs font-bold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                            <svg class="w-4 h-4 {{ $accentText }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Phản hồi (Responses)
                        </h3>

                        <template x-if="selectedEndpoint && selectedEndpoint.responses">
                            <div class="space-y-2">
                                <template x-for="(resp, code) in selectedEndpoint.responses" :key="code">
                                    <div class="p-3 rounded-xl bg-slate-900/70 border border-slate-800 text-xs space-y-1.5">
                                        <div class="flex items-center gap-2">
                                            <span :class="code.startsWith('2') ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' : (code.startsWith('4') ? 'bg-amber-500/20 text-amber-300 border-amber-500/30' : 'bg-rose-500/20 text-rose-300 border-rose-500/30')"
                                                  class="px-2 py-0.5 rounded font-mono font-bold text-[11px] border"
                                                  x-text="code"></span>
                                            <span class="font-medium text-slate-200" x-text="resp.description || 'Không có mô tả phản hồi'"></span>
                                        </div>
                                        <template x-if="resp.content">
                                            <pre tabindex="0" class="mt-2 p-2 rounded bg-slate-950 font-mono text-[10px] text-slate-400 overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}" x-text="JSON.stringify(resp.content, null, 2)"></pre>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="p-4 border-t border-slate-800 bg-slate-900/90 shrink-0 flex flex-wrap items-center justify-between gap-3">
                    <span class="text-xs text-slate-400">
                        Thao tác nhanh cho endpoint đang chọn:
                    </span>
                    <div class="flex flex-wrap items-center gap-3">
                        <a :href="'{{ route('projects.business-rules.index', $project) }}?endpoint_id=' + (selectedEndpoint ? selectedEndpoint.id : '') + '&create=1'"
                           class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold border border-slate-700 transition-all">
                            <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            + Gắn quy tắc nghiệp vụ
                        </a>
                        <a href="{{ route('projects.suites.index', ['project' => $project, 'create' => 1, 'api_spec_id' => $spec->id]) }}"
                                data-testid="m07-create-suite-link"
                                class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-semibold shadow-lg transition-all focus-visible:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::primaryButton(auth()->user()) }}">
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            ▶ Tạo bộ kiểm thử &amp; sinh test
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
