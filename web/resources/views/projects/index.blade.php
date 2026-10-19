@extends('layouts.app')

@section('title', auth()->user()->isAdmin() ? 'Tất cả dự án' : 'Dự án của tôi')

@php
    $createErrors = $errors->any() && old('_form') === 'create-project';
    $editErrors = $errors->any() && str_starts_with((string) old('_form'), 'edit-project-');
@endphp
@if ($createErrors)
    @section('inline_form_errors', '1')
@endif

@section('content')
@php $primaryButton = \App\Support\AccentClasses::primaryButton(auth()->user()); @endphp
<div
    x-data="{ createModalOpen: {{ request()->boolean('create') || old('_form') === 'create-project' ? 'true' : 'false' }} }"
    class="space-y-6"
>
    <div class="flex flex-col gap-4 border-b border-slate-800 pb-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="flex items-center gap-3 text-xl font-bold tracking-tight text-white sm:text-2xl">
                <span>{{ auth()->user()->isAdmin() ? 'Tất cả dự án' : 'Dự án của tôi' }}</span>
            </h1>
            <p class="mt-1 text-xs text-slate-400 sm:text-sm">
                Tạo và quản lý workspace chứa đặc tả OpenAPI, bộ kiểm thử và target mặc định.
            </p>
        </div>

        <button
            id="btn-create-project"
            type="button"
            @click="createModalOpen = true"
            class="{{ \App\Support\AccentClasses::headerCta(auth()->user()) }}"
        >
            <span aria-hidden="true" class="text-base leading-none">+</span>
            Tạo dự án mới
        </button>
    </div>

    <form method="GET" action="{{ route('projects.index') }}" class="flex flex-col gap-3 rounded-2xl border border-slate-800 bg-slate-800/40 p-4 sm:flex-row">
        <label for="project-search" class="sr-only">Tìm theo tên dự án</label>
        <input
            id="project-search"
            name="search"
            type="search"
            value="{{ $search }}"
            placeholder="Tìm theo tên dự án..."
            class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-2.5 text-sm text-white placeholder-slate-500 outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}"
        />
        <button type="submit" class="rounded-xl bg-slate-700 px-4 py-2.5 text-xs font-semibold text-white transition hover:bg-slate-600">Tìm kiếm</button>
        @if ($search !== '')
            <a href="{{ route('projects.index') }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-center text-xs font-semibold text-slate-300 transition hover:bg-slate-800">Xóa lọc</a>
        @endif
    </form>

    @if ($projects->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-700 bg-slate-800/30 px-6 py-16 text-center">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl border border-slate-700 bg-slate-800 text-slate-400">
                <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
            </div>
            @if ($search !== '')
                {{-- Data hidden by the search: point at the filter, not at creating data (S14-06). --}}
                <p data-testid="projects-empty-filtered" class="break-words text-sm font-semibold text-slate-200">Không có dự án nào khớp với “{{ $search }}”</p>
                <p class="mt-1 text-xs text-slate-400">
                    Hãy thử từ khóa khác hoặc
                    <a href="{{ route('projects.index') }}" class="font-semibold text-slate-200 underline underline-offset-2 hover:text-white">xóa lọc</a>.
                </p>
            @else
                <p data-testid="projects-empty" class="text-sm font-semibold text-slate-200">Chưa có dự án nào</p>
                <p class="mt-1 text-xs text-slate-400">Tạo dự án đầu tiên để bắt đầu nhập đặc tả OpenAPI.</p>
            @endif
        </div>
    @else
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            @foreach ($projects as $project)
                @php($editingThis = old('_form') === 'edit-project-'.$project->id)
                <article
                    x-data="{ editing: {{ $editingThis ? 'true' : 'false' }} }"
                    x-init="if (editing) $nextTick(() => ($el.querySelector('[aria-invalid=true]') ?? $refs.editName).focus())"
                    aria-labelledby="project-title-{{ $project->id }}"
                    class="rounded-2xl border border-slate-800 bg-slate-800/40 p-5 shadow-xl">
                    <div x-show="!editing" class="space-y-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <h2 id="project-title-{{ $project->id }}" class="truncate text-base font-bold text-white">{{ $project->name }}</h2>
                                @if (auth()->user()->isAdmin())
                                    <p class="mt-1 text-[11px] text-indigo-300">Chủ sở hữu: {{ $project->owner->name }} · {{ $project->owner->email }}</p>
                                @endif
                            </div>
                            <span class="shrink-0 rounded-lg border border-slate-700 bg-slate-900/60 px-2 py-1 font-mono text-[10px] text-slate-400">#{{ $project->id }}</span>
                        </div>

                        <p class="min-h-10 text-sm leading-5 text-slate-400">{{ $project->description ?: 'Chưa có mô tả.' }}</p>

                        <dl class="grid grid-cols-2 gap-3 text-xs">
                            <div class="rounded-xl border border-slate-700/60 bg-slate-900/40 p-3">
                                <dt class="text-slate-400">Số đặc tả OpenAPI</dt>
                                <dd class="mt-1 text-lg font-bold text-cyan-300">{{ $project->api_specs_count }}</dd>
                            </div>
                            <div class="rounded-xl border border-slate-700/60 bg-slate-900/40 p-3">
                                <dt class="text-slate-400">Số bộ kiểm thử</dt>
                                <dd class="mt-1 text-lg font-bold text-emerald-300">{{ $project->test_suites_count }}</dd>
                            </div>
                        </dl>

                        <div class="rounded-xl border border-slate-700/60 bg-slate-900/40 px-3 py-2.5 text-xs">
                            <span class="text-slate-400">Target mặc định:</span>
                            <span class="ml-1 break-all font-mono text-slate-300">{{ $project->default_target_url ?: 'Chưa thiết lập' }}</span>
                        </div>

                        <div class="grid grid-cols-2 gap-2 pt-1">
                            <a href="{{ route('projects.specs.index', $project) }}" class="rounded-xl border border-cyan-500/30 bg-cyan-500/10 px-3 py-2 text-center text-xs font-semibold text-cyan-300 hover:bg-cyan-500/20 transition flex items-center justify-center gap-1.5">
                                <svg aria-hidden="true" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                Đặc tả OpenAPI
                            </a>
                            <a href="{{ route('projects.business-rules.index', $project) }}" class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-center text-xs font-semibold text-amber-300 hover:bg-amber-500/20 transition flex items-center justify-center gap-1.5">
                                <svg aria-hidden="true" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
                                Quy tắc nghiệp vụ
                            </a>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-800 pt-4">
                            <span class="text-[11px] text-slate-400">Cập nhật {{ $project->updated_at->diffForHumans() }}</span>
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('projects.suites.index', $project) }}" class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-1.5 text-xs font-semibold text-emerald-300 transition hover:bg-emerald-500/20">Bộ kiểm thử</a>
                                <button type="button" data-testid="project-edit-button" class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition {{ \App\Support\AccentClasses::secondaryButton(auth()->user()) }}" x-ref="editButton" aria-label="Sửa dự án {{ $project->name }}" @click="editing = true; $nextTick(() => $refs.editName.focus())">Sửa</button>
                                <form method="POST" action="{{ route('projects.destroy', $project) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" data-confirm="Bạn có chắc muốn xóa dự án {{ $project->name }}?" class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-1.5 text-xs font-semibold text-rose-300 transition hover:bg-rose-500/20">Xóa</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <form x-show="editing" x-cloak method="POST" action="{{ route('projects.update', $project) }}" class="space-y-4">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="_form" value="edit-project-{{ $project->id }}">
                        <x-form-errors :when="$editingThis && $editErrors" />
                        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                            <h2 class="text-sm font-bold text-white">CHỈNH SỬA DỰ ÁN #{{ $project->id }}</h2>
                            <button type="button" @click="editing = false; $nextTick(() => $refs.editButton.focus())" class="text-xs text-slate-400 hover:text-white">Đóng</button>
                        </div>
                        <div>
                            <label for="edit-name-{{ $project->id }}" class="mb-1.5 block text-xs font-semibold text-slate-300">Tên dự án</label>
                            <input id="edit-name-{{ $project->id }}" x-ref="editName" name="name" required maxlength="255" value="{{ $editingThis ? old('name') : $project->name }}" @invalid('name', 'edit-name-'.$project->id.'-error', $editingThis) class="w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}">
                            <x-field-error field="name" :id="'edit-name-'.$project->id.'-error'" :when="$editingThis" />
                        </div>
                        <div>
                            <label for="edit-description-{{ $project->id }}" class="mb-1.5 block text-xs font-semibold text-slate-300">Mô tả</label>
                            <textarea id="edit-description-{{ $project->id }}" name="description" rows="3" maxlength="2000" @invalid('description', 'edit-description-'.$project->id.'-error', $editingThis) class="w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}">{{ $editingThis ? old('description') : $project->description }}</textarea>
                            <x-field-error field="description" :id="'edit-description-'.$project->id.'-error'" :when="$editingThis" />
                        </div>
                        <div>
                            <label for="edit-target-{{ $project->id }}" class="mb-1.5 block text-xs font-semibold text-slate-300">Target mặc định</label>
                            <input id="edit-target-{{ $project->id }}" name="default_target_url" type="url" maxlength="500" value="{{ $editingThis ? old('default_target_url') : $project->default_target_url }}" placeholder="http://benchmark:8000" @invalid('default_target_url', 'edit-target-'.$project->id.'-error', $editingThis, 'edit-target-'.$project->id.'-hint') class="w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 font-mono text-sm text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}">
                            <p id="edit-target-{{ $project->id }}-hint" class="mt-1 text-[11px] text-slate-400">Host phải thuộc danh sách được phép (allowlist) thì mới chạy test được.</p>
                            <x-field-error field="default_target_url" :id="'edit-target-'.$project->id.'-error'" :when="$editingThis" />
                        </div>
                        <div class="flex justify-end gap-2 border-t border-slate-800 pt-4">
                            <button type="button" @click="editing = false; $nextTick(() => $refs.editButton.focus())" class="rounded-xl border border-slate-700 px-4 py-2 text-xs font-semibold text-slate-300">Hủy</button>
                            <button type="submit" class="rounded-xl px-4 py-2 text-xs font-semibold focus-visible:outline-none focus-visible:ring-2 {{ $primaryButton }}">Lưu thay đổi</button>
                        </div>
                    </form>
                </article>
            @endforeach
        </div>

        @if ($projects->hasPages())
            <div class="rounded-2xl border border-slate-800 bg-slate-800/30 px-5 py-4 text-xs text-slate-400">
                {{ $projects->links() }}
            </div>
        @endif
    @endif

    <div x-show="createModalOpen" x-cloak role="dialog" aria-modal="true" aria-labelledby="create-project-modal-title" x-modal-focus="createModalOpen" data-modal-trigger="#btn-create-project" @keydown.escape.window="createModalOpen = false" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4">
        <div class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm" @click="createModalOpen = false"></div>
        <div class="relative z-10 w-full max-w-xl space-y-5 rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <h2 id="create-project-modal-title" class="text-base font-bold text-white">TẠO DỰ ÁN MỚI</h2>
                    <p class="mt-1 text-xs text-slate-400">Bạn sẽ là chủ sở hữu của dự án.</p>
                </div>
                <button type="button" @click="createModalOpen = false" aria-label="Đóng hộp thoại" class="text-slate-400 hover:text-white"><span aria-hidden="true">✕</span></button>
            </div>
            <form method="POST" action="{{ route('projects.store') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="_form" value="create-project">
                <x-form-errors :when="$createErrors" />
                <div>
                    <label for="create-project-name" class="mb-1.5 block text-xs font-semibold text-slate-300">Tên dự án <span class="text-rose-400">*</span></label>
                    <input id="create-project-name" name="name" required maxlength="255" value="{{ old('_form') === 'create-project' ? old('name') : '' }}" @invalid('name', 'create-project-name-error', $createErrors) placeholder="Hệ thống quản lý bán hàng" class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 text-sm text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}">
                    <x-field-error field="name" id="create-project-name-error" :when="$createErrors" />
                </div>
                <div>
                    <label for="create-project-description" class="mb-1.5 block text-xs font-semibold text-slate-300">Mô tả</label>
                    <textarea id="create-project-description" name="description" rows="4" maxlength="2000" placeholder="Mục tiêu và phạm vi kiểm thử..." @invalid('description', 'create-project-description-error', $createErrors) class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 text-sm text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}">{{ old('_form') === 'create-project' ? old('description') : '' }}</textarea>
                    <x-field-error field="description" id="create-project-description-error" :when="$createErrors" />
                </div>
                <div>
                    <label for="create-project-target" class="mb-1.5 block text-xs font-semibold text-slate-300">Target mặc định</label>
                    <input id="create-project-target" name="default_target_url" type="url" maxlength="500" value="{{ old('_form') === 'create-project' ? old('default_target_url') : '' }}" placeholder="http://benchmark:8000" @invalid('default_target_url', 'create-project-target-error', $createErrors, 'create-project-target-hint') class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 font-mono text-sm text-white outline-none focus:ring-2 {{ \App\Support\AccentClasses::focusRing(auth()->user()) }}">
                    <p id="create-project-target-hint" class="mt-1 text-[11px] text-slate-400">Host phải thuộc danh sách được phép (allowlist) thì mới chạy test được.</p>
                    <x-field-error field="default_target_url" id="create-project-target-error" :when="$createErrors" />
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-800 pt-4">
                    <button type="button" @click="createModalOpen = false" class="rounded-xl border border-slate-700 px-4 py-2 text-xs font-semibold text-slate-300">Hủy</button>
                    <button id="btn-submit-project" type="submit" class="rounded-xl px-4 py-2 text-xs font-semibold shadow-md focus-visible:outline-none focus-visible:ring-2 {{ $primaryButton }}">Lưu dự án</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
