@extends('layouts.admin')

@section('title', 'Quản lý người dùng')

@php
    // Only errors of the create form reopen its modal; errors of a row action (role, lock) stay in
    // the layout banner, which is visible because no modal covers it.
    $createErrors = $errors->any() && old('_form') === 'create-user';
@endphp
@if ($createErrors)
    @section('inline_form_errors', '1')
@endif

@section('content')
<div x-data="{ createModalOpen: {{ $createErrors ? 'true' : 'false' }} }" class="space-y-6">
    <!-- Header & Action -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-slate-800">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-white flex items-center gap-3">
                <span>Quản lý người dùng</span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-400 mt-1">
                Tạo tài khoản nghiên cứu viên/sinh viên, phân quyền vai trò và khóa tài khoản khi cần.
            </p>
        </div>

        <button
            @click="createModalOpen = true"
            type="button"
            id="btn-create-user"
            class="inline-flex shrink-0 items-center justify-center gap-2 self-start whitespace-nowrap rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-indigo-600/25 transition hover:bg-indigo-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900 sm:self-auto"
        >
            <span aria-hidden="true" class="text-base leading-none">+</span>
            Cấp tài khoản mới
        </button>
    </div>

    <!-- Filter & Search Bar -->
    <div class="p-4 rounded-2xl bg-slate-800/40 border border-slate-800 shadow-md">
        <form method="GET" action="{{ route('admin.users.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <!-- Search -->
            <div>
                <label for="search" class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400 mb-1">Tìm kiếm</label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    value="{{ $filters['search'] }}"
                    placeholder="Nhập tên hoặc email..."
                    class="w-full px-3 py-2 rounded-xl border border-slate-700 bg-slate-800/80 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 text-xs"
                />
            </div>

            <!-- Filter Role -->
            <div>
                <label for="role" class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400 mb-1">Lọc vai trò</label>
                <select
                    id="role"
                    name="role"
                    class="w-full px-3 py-2 rounded-xl border border-slate-700 bg-slate-800/80 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-xs"
                >
                    <option value="">Tất cả vai trò</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}" {{ $filters['role'] === $role->value ? 'selected' : '' }}>
                            {{ $role->label() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Filter Status -->
            <div>
                <label for="status" class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400 mb-1">Trạng thái</label>
                <select
                    id="status"
                    name="status"
                    class="w-full px-3 py-2 rounded-xl border border-slate-700 bg-slate-800/80 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-xs"
                >
                    <option value="">Tất cả trạng thái</option>
                    <option value="1" {{ $filters['status'] === '1' ? 'selected' : '' }}>Đang hoạt động</option>
                    <option value="0" {{ $filters['status'] === '0' ? 'selected' : '' }}>Đã khóa</option>
                </select>
            </div>

            <!-- Submit & Reset -->
            <div class="flex items-end gap-2">
                <button
                    type="submit"
                    class="flex-1 px-4 py-2 rounded-xl bg-slate-700 hover:bg-slate-600 text-white text-xs font-semibold transition cursor-pointer"
                >
                    Lọc dữ liệu
                </button>
                @if ($filters['search'] || $filters['role'] || $filters['status'] !== '')
                    <a
                        href="{{ route('admin.users.index') }}"
                        class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white text-xs font-medium border border-slate-700 transition"
                    >
                        Xóa
                    </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Users Table (M03 Wireframe) -->
    <div class="rounded-2xl bg-slate-800/40 border border-slate-800 shadow-xl overflow-hidden">
        @if ($users->isEmpty())
            {{-- Full-width card outside the horizontally scrolling table, so the message and its
                 "xóa lọc" link stay readable at 375 px (S14-06 live QA r4, like M05/M09). --}}
            <div data-testid="users-empty-state" class="px-5 py-12 text-center text-slate-400">
                <div class="max-w-md mx-auto space-y-2">
                    @if ($filters['search'] !== '' || $filters['role'] !== '' || $filters['status'] !== '')
                        <p data-testid="users-empty-filtered" class="text-sm font-semibold text-slate-300">Không tìm thấy người dùng nào phù hợp với bộ lọc</p>
                        <p class="text-xs text-slate-400">
                            Hãy đổi điều kiện lọc hoặc
                            <a href="{{ route('admin.users.index') }}" class="font-semibold text-slate-200 underline underline-offset-2 hover:text-white">xóa lọc</a>.
                        </p>
                    @else
                        <p data-testid="users-empty" class="text-sm font-semibold text-slate-300">Chưa có người dùng nào</p>
                    @endif
                </div>
            </div>
        @else
        <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="relative overflow-x-auto focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-800/80 text-slate-400 uppercase font-semibold border-b border-slate-800">
                    <tr>
                        <th class="px-5 py-3.5">ID</th>
                        <th class="px-5 py-3.5">Họ và tên</th>
                        <th class="px-5 py-3.5">Email</th>
                        <th class="px-5 py-3.5">Vai trò</th>
                        <th class="px-5 py-3.5">Trạng thái</th>
                        <th class="px-5 py-3.5 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 text-slate-300">
                    @foreach ($users as $user)
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="px-5 py-4 font-mono text-slate-400 font-medium">{{ $user->id }}</td>
                            <td data-testid="user-name-cell" class="min-w-[8rem] max-w-xs px-5 py-4 font-semibold text-white [overflow-wrap:anywhere]">{{ $user->name }}</td>
                            <td data-testid="user-email-cell" class="min-w-[10rem] max-w-xs px-5 py-4 font-mono text-slate-300 [overflow-wrap:anywhere]">{{ $user->email }}</td>
                            <td class="px-5 py-4">
                                @if ($user->isAdmin())
                                    <span class="inline-flex items-center whitespace-nowrap px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-indigo-500/20 text-indigo-300 border border-indigo-500/30" title="{{ \App\Support\EnumTooltip::for($user->role->label(), $user->role) }}">
                                        {{ $user->role->label() }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center whitespace-nowrap px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-teal-500/20 text-teal-300 border border-teal-500/30" title="{{ \App\Support\EnumTooltip::for($user->role->label(), $user->role) }}">
                                        {{ $user->role->label() }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                @if ($user->is_active)
                                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap px-2.5 py-1 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                        <span aria-hidden="true" class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                        Hoạt động
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap px-2.5 py-1 rounded-full text-[11px] font-medium bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                        <span aria-hidden="true" class="w-1.5 h-1.5 rounded-full bg-rose-400"></span>
                                        Đã khóa
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if ($user->id === auth()->id())
                                        <span class="text-slate-400 italic text-[11px]">(Bạn)</span>
                                    @else
                                        <form method="POST" action="{{ route('admin.users.update-role', $user) }}" class="flex items-center gap-1.5" data-testid="update-role-form-{{ $user->id }}"
                                              data-confirm="Đổi vai trò tài khoản {{ $user->email }}?">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="_form" value="update-role">
                                            <label for="role-{{ $user->id }}" class="sr-only">Vai trò của {{ $user->email }}</label>
                                            <select id="role-{{ $user->id }}" name="role" class="rounded-lg border border-slate-700 bg-slate-900 px-2 py-1 text-[11px] text-slate-200">
                                                @foreach ($roles as $roleOption)
                                                    <option value="{{ $roleOption->value }}" @selected($user->role === $roleOption)>{{ $roleOption->label() }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="px-2.5 py-1 rounded-lg bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 text-[11px] font-semibold whitespace-nowrap transition cursor-pointer">Đổi vai trò</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.users.toggle-status', $user) }}">
                                            @csrf
                                            <input type="hidden" name="_form" value="toggle-status">
                                            <input type="hidden" name="is_active" value="{{ $user->is_active ? 0 : 1 }}">
                                            @if ($user->is_active)
                                                <button
                                                    type="submit"
                                                    data-confirm="Bạn có chắc chắn muốn KHÓA tài khoản {{ $user->email }}?"
                                                    class="px-2.5 py-1 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 border border-rose-500/30 text-[11px] font-semibold whitespace-nowrap transition cursor-pointer"
                                                >
                                                    Khóa
                                                </button>
                                            @else
                                                <button
                                                    type="submit"
                                                    data-confirm="Bạn có chắc chắn muốn MỞ KHÓA tài khoản {{ $user->email }}?"
                                                    class="px-2.5 py-1 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 text-[11px] font-semibold whitespace-nowrap transition cursor-pointer"
                                                >
                                                    Mở khóa
                                                </button>
                                            @endif
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        <!-- Pagination & Summary -->
        <div class="px-5 py-4 border-t border-slate-800 bg-slate-800/30 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-400">
            <div>
                Trang {{ $users->currentPage() }}/{{ $users->lastPage() }} (Tổng số: {{ $users->total() }} người dùng)
            </div>
            <div>
                {{ $users->links() }}
            </div>
        </div>
    </div>

    <!-- Alpine Modal: CẤP TÀI KHOẢN MỚI (M03 Modal) -->
    <div
        x-show="createModalOpen"
        x-cloak
        role="dialog"
        aria-modal="true"
        aria-labelledby="create-user-modal-title"
        x-modal-focus="createModalOpen"
        data-modal-trigger="#btn-create-user"
        @keydown.escape.window="createModalOpen = false"
        class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4"
    >
        <!-- Backdrop -->
        <div
            x-show="createModalOpen"
            x-transition:enter="ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @click="createModalOpen = false"
            class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm"
        ></div>

        <!-- Modal Dialog -->
        <div
            x-show="createModalOpen"
            x-transition:enter="ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="relative w-full max-w-lg rounded-2xl bg-slate-900 border border-slate-700 shadow-2xl p-6 z-10 space-y-5"
        >
            <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                <h3 id="create-user-modal-title" class="text-base font-bold text-white flex items-center gap-2">
                    <span>CẤP TÀI KHOẢN MỚI</span>
                </h3>
                <button type="button" @click="createModalOpen = false" aria-label="Đóng hộp thoại" class="text-slate-400 hover:text-white p-1 rounded-lg">
                    <svg aria-hidden="true" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-4" data-testid="create-user-form">
                @csrf
                <input type="hidden" name="_form" value="create-user">
                <x-form-errors :when="$createErrors" />

                <!-- Name -->
                <div>
                    <label for="modal-name" class="block text-xs font-semibold text-slate-300 mb-1.5">Họ và tên:</label>
                    <input
                        type="text"
                        id="modal-name"
                        name="name"
                        value="{{ $createErrors ? old('name') : '' }}"
                        required
                        maxlength="255"
                        autocomplete="off"
                        @invalid('name', 'modal-name-error', $createErrors)
                        placeholder="Nguyễn Văn A"
                        class="w-full px-3 py-2 rounded-xl border border-slate-700 bg-slate-800 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 text-xs"
                    />
                    <x-field-error field="name" id="modal-name-error" :when="$createErrors" />
                </div>

                <!-- Email -->
                <div>
                    <label for="modal-email" class="block text-xs font-semibold text-slate-300 mb-1.5">Email:</label>
                    <input
                        type="email"
                        id="modal-email"
                        name="email"
                        value="{{ $createErrors ? old('email') : '' }}"
                        required
                        maxlength="255"
                        autocomplete="off"
                        @invalid('email', 'modal-email-error', $createErrors)
                        placeholder="ten@ait.local"
                        class="w-full px-3 py-2 rounded-xl border border-slate-700 bg-slate-800 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 text-xs"
                    />
                    <x-field-error field="email" id="modal-email-error" :when="$createErrors" />
                </div>

                <!-- Role -->
                <div>
                    <span id="modal-role-label" class="block text-xs font-semibold text-slate-300 mb-1.5">Vai trò:</span>
                    <div role="radiogroup" aria-labelledby="modal-role-label" class="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs text-slate-200">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                type="radio"
                                name="role"
                                value="TESTER"
                                {{ ($createErrors ? old('role', 'TESTER') : 'TESTER') === 'TESTER' ? 'checked' : '' }}
                                class="text-indigo-600 border-slate-700 bg-slate-800 focus:ring-indigo-500"
                            />
                            <span>TESTER (Kiểm thử viên)</span>
                        </label>

                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                type="radio"
                                name="role"
                                value="ADMIN"
                                {{ $createErrors && old('role') === 'ADMIN' ? 'checked' : '' }}
                                class="text-indigo-600 border-slate-700 bg-slate-800 focus:ring-indigo-500"
                            />
                            <span>ADMIN (Quản trị viên)</span>
                        </label>
                    </div>
                    <x-field-error field="role" id="modal-role-error" :when="$createErrors" />
                </div>

                <!-- Password -->
                <div>
                    <label for="modal-password" class="block text-xs font-semibold text-slate-300 mb-1.5">Mật khẩu ban đầu:</label>
                    <input
                        type="password"
                        id="modal-password"
                        name="password"
                        required
                        minlength="12"
                        maxlength="255"
                        autocomplete="new-password"
                        @invalid('password', 'modal-password-error', $createErrors)
                        placeholder="Tối thiểu 12 ký tự"
                        class="w-full px-3 py-2 rounded-xl border border-slate-700 bg-slate-800 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 text-xs"
                    />
                    <x-field-error field="password" id="modal-password-error" :when="$createErrors" />
                    @if ($createErrors)
                        <p class="mt-1 text-[11px] text-slate-400">Vì lý do bảo mật, hãy nhập lại mật khẩu ban đầu.</p>
                    @endif
                </div>

                <!-- Is Active -->
                <div class="pt-1">
                    <label class="flex items-center gap-2 cursor-pointer select-none">
                        <input
                            type="checkbox"
                            name="is_active"
                            id="modal-is-active"
                            value="1"
                            {{ $createErrors ? (old('is_active') ? 'checked' : '') : 'checked' }}
                            class="w-4 h-4 rounded border-slate-700 bg-slate-800 text-indigo-600 focus:ring-indigo-500"
                        />
                        <span class="text-xs text-slate-200 font-medium">Kích hoạt tài khoản ngay</span>
                    </label>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-800">
                    <button
                        @click="createModalOpen = false"
                        type="button"
                        class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium border border-slate-700 transition cursor-pointer"
                    >
                        Hủy
                    </button>
                    <button
                        type="submit"
                        id="btn-submit-user"
                        class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-md shadow-indigo-600/30 transition cursor-pointer"
                    >
                        Lưu tài khoản
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
