@extends('layouts.app')

@section('title', 'Đặc tả OpenAPI — ' . $project->name)

@section('content')
@php
    $primaryButton = \App\Support\AccentClasses::primaryButton(auth()->user());
    $activeSegment = \App\Support\AccentClasses::activeSegment(auth()->user());
    // Upload area, section icons and version bullets follow the shell accent (S14-06 live QA r7).
    $accentText = \App\Support\AccentClasses::accentText(auth()->user());
    $accentDot = \App\Support\AccentClasses::dot(auth()->user());
@endphp
<div class="space-y-8" x-data="{
    inputMethod: @js(old('input_method') === 'text' ? 'text' : 'file'),
    submitting: false,
    selectedFile: null,
    dragOver: false,
    onFileSelected(e) {
        if (e.target.files && e.target.files[0]) {
            this.selectedFile = e.target.files[0];
        }
    },
    onDrop(e) {
        this.dragOver = false;
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            this.selectedFile = e.dataTransfer.files[0];
            $refs.fileInput.files = e.dataTransfer.files;
        }
    }
}">
    <!-- Breadcrumb & Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-slate-800 pb-5">
        <div>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400 mb-1 min-w-0 break-all">
                <a href="{{ route('projects.index') }}" class="transition-colors {{ \App\Support\AccentClasses::breadcrumbLink(auth()->user()) }}">Dự án</a>
                <span class="text-slate-400">/</span>
                <span class="text-slate-300 font-medium">{{ $project->name }}</span>
                <span class="text-slate-400">/</span>
                <span data-testid="breadcrumb-current" class="font-medium {{ \App\Support\AccentClasses::label(auth()->user()) }}" aria-current="page">Đặc tả OpenAPI</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-3">
                <span class="p-2 rounded-xl border shadow-inner {{ \App\Support\AccentClasses::iconTile(auth()->user()) }}">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </span>
                Đặc tả OpenAPI
            </h1>
            <p class="text-xs text-slate-400 mt-1">Tải lên tệp JSON/YAML đặc tả OpenAPI 3.x, tự động bóc tách cấu trúc endpoint và quản lý lịch sử phiên bản.</p>
        </div>

        <div class="flex items-center gap-3">
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-slate-800/90 text-slate-300 border border-slate-700">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                {{ $specs->count() }} phiên bản đã lưu
            </span>
        </div>
    </div>

    @if (is_array(session('spec_parse_errors')) && session('spec_parse_errors') !== [])
        <div role="alert" data-testid="spec-parse-failure" class="rounded-2xl border border-rose-500/40 bg-rose-950/40 p-4 space-y-2">
            <p class="text-sm font-semibold text-rose-200">Lý do không phân tích được đặc tả:</p>
            @include('specs._parse-errors', ['errorsList' => session('spec_parse_errors')])
        </div>
    @endif

    <!-- Card 1: Upload OpenAPI Spec Form -->
    <div class="rounded-2xl bg-gradient-to-b from-slate-850 to-slate-900 border border-slate-800 shadow-xl overflow-hidden">
        <div class="px-6 py-4 bg-slate-800/40 border-b border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 {{ $accentText }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                </svg>
                <h2 class="text-sm font-semibold text-white tracking-wide uppercase">Tải lên phiên bản đặc tả mới</h2>
            </div>
            <span class="text-xs text-slate-400">Hỗ trợ OpenAPI 3.0.x / 3.1.x (tối đa 5 MB)</span>
        </div>

        <form action="{{ route('projects.specs.store', $project) }}" method="POST" enctype="multipart/form-data" @submit="submitting = true" class="p-6 space-y-6">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Version label -->
                <div>
                    <label for="version_label" class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">
                        Nhãn phiên bản (Version label) <span class="text-rose-400">*</span>
                    </label>
                    <div class="relative">
                        <input type="text"
                               name="version_label"
                               id="version_label"
                               required
                               placeholder="ví dụ: v1.0.0, v1.1.0-release, 2026-10-06"
                               value="{{ old('version_label', 'v1.0.0') }}"
                               class="w-full px-4 py-2.5 rounded-xl bg-slate-900/90 border border-slate-700 text-white placeholder-slate-500 text-sm focus:outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }} focus:border-transparent transition-all shadow-inner">
                    </div>
                    <p class="text-[11px] text-slate-400 mt-1.5">Dùng để phân biệt các lần cập nhật và phiên bản đặc tả trong dự án.</p>
                </div>

                <!-- Input Method Switcher -->
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">
                        Phương thức nhập đặc tả
                    </label>
                    <div class="grid grid-cols-2 gap-3 p-1 rounded-xl bg-slate-900/90 border border-slate-700/80">
                        <button type="button"
                                @click="inputMethod = 'file'"
                                :aria-pressed="(inputMethod === 'file').toString()"
                                :class="inputMethod === 'file' ? @js($activeSegment) : 'text-slate-400 hover:text-slate-200'"
                                class="flex items-center justify-center gap-2 py-2 px-3 rounded-lg text-xs font-semibold transition-all">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                            </svg>
                            Tải tệp tin (.json, .yaml)
                        </button>
                        <button type="button"
                                @click="inputMethod = 'text'"
                                :aria-pressed="(inputMethod === 'text').toString()"
                                :class="inputMethod === 'text' ? @js($activeSegment) : 'text-slate-400 hover:text-slate-200'"
                                class="flex items-center justify-center gap-2 py-2 px-3 rounded-lg text-xs font-semibold transition-all">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                            Dán văn bản trực tiếp
                        </button>
                    </div>
                    <input type="hidden" name="input_method" :value="inputMethod">
                </div>
            </div>

            <!-- Tab 1: File Upload (Drag and Drop) -->
            <div x-show="inputMethod === 'file'" x-cloak class="space-y-2">
                <div @dragover.prevent="dragOver = true"
                     @dragleave.prevent="dragOver = false"
                     @drop.prevent="onDrop($event)"
                     :class="dragOver ? @js(\App\Support\AccentClasses::dropzoneActive(auth()->user())) : 'border-slate-700 bg-slate-900/50 hover:border-slate-600'"
                     class="relative border-2 border-dashed rounded-2xl p-8 text-center transition-all cursor-pointer group">
                    <input type="file"
                           id="spec-file-input"
                           name="spec_file"
                           aria-label="Chọn tệp đặc tả OpenAPI"
                           aria-describedby="spec-file-hint"
                           x-ref="fileInput"
                           @change="onFileSelected($event)"
                           accept=".json,.yaml,.yml,application/json,application/x-yaml,text/yaml"
                           class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">

                    <div class="flex flex-col items-center justify-center">
                        <div data-testid="spec-dropzone-icon" class="p-4 rounded-2xl border mb-3 group-hover:scale-110 transition-transform {{ \App\Support\AccentClasses::iconTile(auth()->user()) }}">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                            </svg>
                        </div>
                        <p class="text-sm font-semibold text-slate-200">
                            Kéo và thả tệp OpenAPI spec tại đây, hoặc <span data-testid="spec-dropzone-link" class="{{ $accentText }} group-hover:underline">chọn tệp từ máy tính</span>
                        </p>
                        <p id="spec-file-hint" class="text-xs text-slate-400 mt-1">Định dạng hợp lệ: .json, .yaml, .yml (tối đa 5 MB)</p>

                        <!-- Selected File Preview -->
                        <template x-if="selectedFile">
                            <div class="mt-4 inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-950/80 border border-emerald-500/40 text-emerald-200 text-xs font-semibold shadow-lg">
                                <svg class="w-4 h-4 text-emerald-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span>Tệp đã chọn: <strong x-text="selectedFile.name"></strong> (<span x-text="(selectedFile.size / 1024).toFixed(1) + ' KB'"></span>)</span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Tab 2: Raw Text Input -->
            <div x-show="inputMethod === 'text'" x-cloak class="space-y-2">
                <label for="spec_text" class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">
                    Nội dung văn bản đặc tả OpenAPI (JSON hoặc YAML)
                </label>
                <div class="relative">
                    <textarea name="spec_text"
                              id="spec_text"
                              rows="10"
                              placeholder="openapi: 3.1.0&#10;info:&#10;  title: Sample Store API&#10;  version: 1.0.0&#10;paths:&#10;  /products:&#10;    get:&#10;      summary: List products&#10;      responses:&#10;        '200':&#10;          description: OK"
                              class="w-full p-4 rounded-xl bg-slate-900 border border-slate-700 text-slate-200 font-mono text-xs focus:outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }} focus:border-transparent transition-all shadow-inner leading-relaxed">{{ old('spec_text') }}</textarea>
                </div>
            </div>

            <!-- Submit Action -->
            <div class="flex items-center justify-end pt-2">
                <button type="submit"
                        :disabled="submitting"
                        data-testid="btn-parse-spec"
                        class="disabled:opacity-60 disabled:cursor-wait inline-flex items-center gap-2 px-6 py-2.5 rounded-xl font-semibold text-sm shadow-lg transition-all focus-visible:outline-none focus-visible:ring-2 {{ $primaryButton }}">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                    Tiến hành Phân tích đặc tả
                </button>
            </div>
        </form>
    </div>

    <!-- Card 2: Lịch sử các phiên bản đặc tả -->
    <div class="rounded-2xl bg-slate-850 border border-slate-800 shadow-xl overflow-hidden">
        <div class="px-6 py-4 bg-slate-800/40 border-b border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 {{ $accentText }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h2 class="text-sm font-semibold text-white tracking-wide uppercase">Lịch sử các phiên bản đặc tả</h2>
            </div>
            <span class="text-xs text-slate-400">Danh sách các phiên bản đã nhập của dự án</span>
        </div>

        @if($specs->isEmpty())
            <div class="p-12 text-center">
                <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-slate-800/80 border border-slate-700/80 flex items-center justify-center text-slate-400">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-slate-200">Chưa có đặc tả OpenAPI nào được tải lên</h3>
                <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">Vui lòng tải lên tệp OpenAPI (.json, .yaml) ở biểu mẫu phía trên để bắt đầu phân tích và sinh ca kiểm thử.</p>
            </div>
        @else
            <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="overflow-x-auto focus:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::focusVisibleRing(auth()->user()) }}">
                <table data-testid="spec-versions-table" class="w-full min-w-[52rem] text-left text-sm text-slate-300">
                    <thead class="bg-slate-900/80 text-[11px] font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                        <tr>
                            <th class="px-3 py-3.5">Phiên bản</th>
                            <th class="px-3 py-3.5">Tên tệp gốc</th>
                            <th class="px-3 py-3.5">Định dạng</th>
                            <th class="px-3 py-3.5">Phiên bản OpenAPI</th>
                            <th class="px-3 py-3.5">Số endpoint</th>
                            <th class="px-3 py-3.5">Trạng thái</th>
                            <th class="px-3 py-3.5">Mã băm (SHA-256)</th>
                            <th class="px-3 py-3.5 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/80 font-normal">
                        @foreach($specs as $spec)
                            @php
                                $specFormat = is_string($spec->format) ? $spec->format : ($spec->format->value ?? (string) $spec->format);
                                $specStatus = is_string($spec->status) ? $spec->status : ($spec->status->value ?? (string) $spec->status);
                            @endphp
                            <tr class="hover:bg-slate-800/40 transition-colors">
                                {{-- A long label ("v1.1.0-release-candidate") wraps inside 10rem instead of widening
                                     the table past the content area at 1280 px (S14-06 live QA r4 review). --}}
                                <td class="px-3 py-4 font-semibold text-white">
                                    <div data-testid="spec-version-label" class="flex max-w-[10rem] items-center gap-2 break-words">
                                        <span aria-hidden="true" data-testid="spec-version-dot" class="w-2 h-2 shrink-0 rounded-full {{ $accentDot }}"></span>
                                        <span class="min-w-0">{{ $spec->version_label }}</span>
                                    </div>
                                </td>
                                {{-- A fixed width keeps the name readable at desktop widths (no "spe/cif/ica…" one
                                     character per line); a long name is cut with an ellipsis and shown in full in the
                                     tooltip (S14-06 live QA r4). --}}
                                <td class="px-3 py-4 font-mono text-xs text-slate-300">
                                    <span data-testid="spec-source-filename" class="block w-40 truncate" title="{{ $spec->source_filename }}">{{ $spec->source_filename }}</span>
                                </td>
                                <td class="px-3 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $specFormat === 'json' ? 'bg-amber-500/10 text-amber-300 border border-amber-500/20' : 'bg-sky-500/10 text-sky-300 border border-sky-500/20' }}">
                                        {{ strtoupper($specFormat) }}
                                    </span>
                                </td>
                                <td class="px-3 py-4 font-mono text-xs text-slate-300">
                                    {{ $spec->displayOpenApiVersion() }}
                                </td>
                                <td class="px-3 py-4 font-semibold text-slate-200">
                                    {{ $spec->endpoints_count }}
                                </td>
                                <td class="px-3 py-4 whitespace-nowrap">
                                    @if($specStatus === 'PARSED')
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20" title="Đã phân tích (PARSED)">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                            Đã phân tích
                                        </span>
                                    @elseif($specStatus === 'PARSE_FAILED')
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                            <span aria-hidden="true" class="w-1.5 h-1.5 rounded-full bg-rose-400"></span>
                                            Lỗi phân tích
                                        </span>
                                        @if (! empty($spec->parse_errors))
                                            <details class="mt-2 max-w-sm whitespace-normal">
                                                <summary class="cursor-pointer text-xs font-semibold text-rose-300">Xem lý do</summary>
                                                <div class="mt-1">@include('specs._parse-errors', ['errorsList' => $spec->parse_errors])</div>
                                            </details>
                                        @endif
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-500/10 text-slate-400 border border-slate-500/20">
                                            {{ $specStatus }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-4 font-mono text-xs text-slate-400" title="{{ $spec->checksum }}">
                                    {{ substr($spec->checksum, 0, 8) }}…
                                </td>
                                <td class="px-3 py-4 text-right whitespace-nowrap">
                                    @if($specStatus === 'PARSED')
                                        <a href="{{ route('projects.specs.show', [$project, $spec]) }}"
                                           data-testid="spec-endpoints-link"
                                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-xs font-semibold transition-all hover:text-white {{ \App\Support\AccentClasses::secondaryButton(auth()->user()) }}">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                            Xem endpoint
                                        </a>
                                    @else
                                        <a href="{{ route('projects.specs.show', [$project, $spec]) }}"
                                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-rose-600/10 hover:bg-rose-600/20 text-rose-300 border border-rose-500/30 text-xs font-semibold transition-all">
                                            Xem lỗi
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
