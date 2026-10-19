@extends('layouts.app')

@section('title', 'Bộ kiểm thử — ' . $project->name)

@section('content')
@php
    // Reopen the create modal with the user's input after a validation redirect,
    // or when arriving from M07 (?create=1&api_spec_id=...).
    $reopenCreate = old('_form') === 'create-suite' || request()->boolean('create');
    $createErrors = $errors->any() && old('_form') === 'create-suite';
    $selectedSpecId = (string) old('api_spec_id', request()->query('api_spec_id', ''));
@endphp
@if ($createErrors)
    @section('inline_form_errors', '1')
@endif
<div x-data="{ createModalOpen: {{ $reopenCreate ? 'true' : 'false' }} }" class="space-y-6">
    <!-- Breadcrumb & Header -->
    <div class="flex flex-col gap-4 border-b border-slate-800 pb-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400 mb-1 min-w-0 break-all">
                <a href="{{ route('projects.index') }}" class="hover:text-white transition">Dự án</a>
                <span>/</span>
                <span class="text-slate-200 font-medium">{{ $project->name }}</span>
            </div>
            <h1 class="flex items-center gap-3 text-xl font-bold tracking-tight text-white sm:text-2xl">
                <span>Bộ kiểm thử</span>
            </h1>
            <p class="mt-1 text-xs text-slate-400 sm:text-sm">
                Quản lý các bộ kiểm thử tự động cho dự án {{ $project->name }}.
            </p>
        </div>

        <button
            type="button"
            id="btn-create-suite"
            @click="createModalOpen = true"
            class="{{ \App\Support\AccentClasses::headerCta(auth()->user()) }}"
        >
            <span aria-hidden="true" class="text-base leading-none">+</span>
            Tạo bộ kiểm thử mới
        </button>
    </div>

    @if ($suites->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-700 bg-slate-800/30 px-6 py-16 text-center">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl border border-slate-700 bg-slate-800 text-slate-400">
                <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </div>
            <p class="text-sm font-semibold text-slate-200">Chưa có bộ kiểm thử nào</p>
            <p class="mt-1 text-xs text-slate-400">Tạo bộ kiểm thử mới kết nối với đặc tả OpenAPI để bắt đầu sinh test case.</p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            @foreach ($suites as $suite)
                <article class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl space-y-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <a href="{{ route('suites.show', $suite) }}" class="text-base font-bold text-white transition truncate block {{ \App\Support\RoleLayout::accent(auth()->user()) === 'teal' ? 'hover:text-teal-300' : 'hover:text-indigo-400' }}">
                                {{ $suite->name }}
                            </a>
                            <p class="text-xs text-slate-400 mt-0.5">
                                Spec: <span class="text-cyan-400 font-mono">{{ $suite->apiSpec->version_label ?? ('#' . $suite->api_spec_id) }}</span> · Tạo bởi {{ $suite->creator->name }}
                            </p>
                        </div>
                        <span class="shrink-0 rounded-lg border border-slate-700 bg-slate-900/60 px-2 py-1 font-mono text-[10px] text-slate-400">#{{ $suite->id }}</span>
                    </div>

                    <p class="text-sm text-slate-400 min-h-10 leading-5">
                        {{ $suite->description ?: 'Chưa có mô tả.' }}
                    </p>

                    <div class="grid grid-cols-2 gap-3 text-xs">
                        <div class="rounded-xl border border-slate-700/60 bg-slate-900/40 p-3">
                            <span class="text-slate-400">Số lượng test cases</span>
                            <p class="mt-1 text-lg font-bold text-emerald-400">{{ $suite->test_cases_count }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-700/60 bg-slate-900/40 p-3">
                            <span class="text-slate-400">Cập nhật</span>
                            <p class="mt-1 text-xs text-slate-300 font-mono">{{ $suite->updated_at->diffForHumans() }}</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between border-t border-slate-800 pt-4">
                        <div class="flex gap-2">
                            <a href="{{ route('suites.show', $suite) }}" data-testid="suite-detail-link" class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition {{ \App\Support\AccentClasses::secondaryButton(auth()->user()) }}">
                                Chi tiết
                            </a>
                            <a href="{{ route('suites.generation-jobs.create', $suite) }}" class="rounded-lg bg-emerald-600/20 border border-emerald-500/30 px-3 py-1.5 text-xs font-semibold text-emerald-300 hover:bg-emerald-600/30 transition">
                                Sinh test
                            </a>
                        </div>
                        <form method="POST" action="{{ route('suites.destroy', $suite) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" data-confirm="Bạn có chắc muốn xóa bộ kiểm thử {{ $suite->name }}?" class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-1.5 text-xs font-semibold text-rose-300 hover:bg-rose-500/20 transition">
                                Xóa
                            </button>
                        </form>
                    </div>
                </article>
            @endforeach
        </div>

        @if ($suites->hasPages())
            <div class="rounded-2xl border border-slate-800 bg-slate-800/30 px-5 py-4 text-xs text-slate-400">
                {{ $suites->links() }}
            </div>
        @endif
    @endif

    <!-- Modal Create Suite -->
    <div x-show="createModalOpen" x-cloak role="dialog" aria-modal="true" aria-labelledby="create-suite-modal-title" x-modal-focus="createModalOpen" data-modal-trigger="#btn-create-suite" @keydown.escape.window="createModalOpen = false" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4">
        <div class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm" @click="createModalOpen = false"></div>
        <div class="relative z-10 w-full max-w-xl space-y-5 rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h2 id="create-suite-modal-title" class="text-base font-bold text-white">TẠO BỘ KIỂM THỬ MỚI</h2>
                <button type="button" @click="createModalOpen = false" aria-label="Đóng hộp thoại" class="text-slate-400 hover:text-white"><span aria-hidden="true">✕</span></button>
            </div>
            <form method="POST" action="{{ route('projects.suites.store', $project) }}" class="space-y-4">
                @csrf
                <input type="hidden" name="_form" value="create-suite">
                <x-form-errors :when="$createErrors" />
                <div>
                    <label for="create-suite-spec" class="mb-1.5 block text-xs font-semibold text-slate-300">Đặc tả OpenAPI <span class="text-rose-400">*</span></label>
                    <select id="create-suite-spec" name="api_spec_id" required @invalid('api_spec_id', 'create-suite-spec-error', $createErrors) class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 text-sm text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}">
                        <option value="">-- Chọn đặc tả OpenAPI --</option>
                        @foreach ($apiSpecs as $spec)
                            <option value="{{ $spec->id }}" @selected($selectedSpecId === (string) $spec->id)>{{ $spec->version_label }} ({{ $spec->source_filename }}) - {{ $spec->displayOpenApiVersion() }}</option>
                        @endforeach
                    </select>
                    <x-field-error field="api_spec_id" id="create-suite-spec-error" :when="$createErrors" />
                </div>
                <div>
                    <label for="create-suite-name" class="mb-1.5 block text-xs font-semibold text-slate-300">Tên bộ kiểm thử <span class="text-rose-400">*</span></label>
                    <input id="create-suite-name" name="name" value="{{ old('name') }}" required maxlength="255" @invalid('name', 'create-suite-name-error', $createErrors) placeholder="Bộ kiểm thử hồi quy v1.0" class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 text-sm text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}">
                    <x-field-error field="name" id="create-suite-name-error" :when="$createErrors" />
                </div>
                <div>
                    <label for="create-suite-description" class="mb-1.5 block text-xs font-semibold text-slate-300">Mô tả</label>
                    <textarea id="create-suite-description" name="description" rows="3" maxlength="2000" placeholder="Mục tiêu kiểm thử của bộ test này..." @invalid('description', 'create-suite-description-error', $createErrors) class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 text-sm text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}">{{ old('description') }}</textarea>
                    <x-field-error field="description" id="create-suite-description-error" :when="$createErrors" />
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-800 pt-4">
                    <button type="button" @click="createModalOpen = false" class="rounded-xl border border-slate-700 px-4 py-2 text-xs font-semibold text-slate-300">Hủy</button>
                    <button type="submit" class="rounded-xl px-4 py-2 text-xs font-semibold shadow-md focus-visible:outline-none focus-visible:ring-2 {{ \App\Support\AccentClasses::primaryButton(auth()->user()) }}">Tạo bộ kiểm thử</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
