{{-- Table of the latest test runs on the dashboards (M02, S14-04): the runs the viewer may see.
     `accent` follows the shell (indigo: ADMIN, teal: TESTER, App\Support\RoleLayout::accent). --}}
@props(['runs', 'caption' => '5 lần chạy gần nhất', 'accent' => null])
@php
    $accent ??= \App\Support\RoleLayout::accent(auth()->user());
    $idText = $accent === 'teal' ? 'text-teal-300' : 'text-indigo-300';
    $link = $accent === 'teal' ? 'text-teal-300 hover:text-teal-200' : 'text-indigo-300 hover:text-indigo-200';
    $focus = $accent === 'teal' ? 'focus-visible:ring-teal-400' : 'focus-visible:ring-indigo-400';
@endphp
<div {{ $attributes->merge(['class' => 'rounded-2xl bg-slate-800/40 border border-slate-800 shadow-xl overflow-hidden']) }}>
    <div data-testid="recent-runs-header" class="p-5 border-b border-slate-800 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-200 flex items-center gap-2">
            <span>Các lần chạy gần đây</span>
        </h2>
        <span class="text-xs text-slate-400">{{ $caption }}</span>
    </div>

    <div tabindex="0" role="region" aria-label="Bảng dữ liệu, cuộn ngang để xem thêm" class="overflow-x-auto focus:outline-none focus-visible:ring-2 {{ $focus }}">
        <table class="w-full text-left text-xs">
            <thead class="bg-slate-800/70 text-slate-400 uppercase font-semibold border-b border-slate-800 whitespace-nowrap">
                <tr>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">Bộ kiểm thử (Suite)</th>
                    <th class="px-4 py-3">Hệ thống đích</th>
                    <th class="px-4 py-3">Biến thể</th>
                    <th class="px-4 py-3">Kết quả</th>
                    <th class="px-4 py-3">Bắt đầu</th>
                    <th class="px-4 py-3 text-right">Chi tiết</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/60 text-slate-300">
                @forelse ($runs as $run)
                    <tr class="hover:bg-slate-800/40 transition-colors">
                        <td class="px-4 py-3.5 font-mono font-semibold whitespace-nowrap {{ $idText }}">#{{ $run->id }}</td>
                        <td class="min-w-[10rem] px-4 py-3.5 font-medium text-white break-words">{{ $run->testSuite->name }}</td>
                        <td class="px-4 py-3.5 font-mono text-slate-400"><span class="block max-w-[12rem] truncate whitespace-nowrap" title="{{ $run->target_base_url }}">{{ $run->target_base_url }}</span></td>
                        <td class="px-4 py-3.5">
                            <span data-testid="run-variant-badge" class="inline-block whitespace-nowrap px-2 py-0.5 rounded bg-slate-800 border border-slate-700 text-slate-300">{{ \App\Support\VariantLabel::display($run->benchmark_variant) }}</span>
                        </td>
                        <td class="px-4 py-3.5 whitespace-nowrap">
                            <x-run-status-badge :status="$run->status" />
                            @if (is_array($run->totals))
                                <span class="mt-1 block whitespace-nowrap text-slate-400">Đạt {{ (int) ($run->totals['pass'] ?? 0) }}/{{ (int) ($run->totals['total'] ?? 0) }}</span>
                            @endif
                        </td>
                        {{-- Start time, like M12/M13: created_at is the import time of demo-imported official runs (S14-06). --}}
                        <td class="px-4 py-3.5 whitespace-nowrap text-slate-400"><x-datetime :value="$run->started_at ?? $run->created_at" /></td>
                        <td class="px-4 py-3.5 text-right whitespace-nowrap">
                            <a href="{{ route('runs.show', $run) }}" class="font-medium {{ $link }}">Xem</a>
                            @if ($run->status->isFinished())
                                <a href="{{ route('runs.report', $run) }}" class="ml-2 font-medium {{ $link }}">Báo cáo</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-12 text-center text-slate-400">
                            <div class="max-w-sm mx-auto flex flex-col items-center">
                                <div class="w-12 h-12 rounded-2xl bg-slate-800 border border-slate-700 flex items-center justify-center text-slate-400 mb-3">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                </div>
                                <p class="text-sm font-semibold text-slate-300">Chưa có đợt chạy kiểm thử nào</p>
                                <p class="text-xs text-slate-400 mt-1">Hãy chạy một bộ kiểm thử; các lần chạy gần nhất sẽ hiển thị tại đây.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
