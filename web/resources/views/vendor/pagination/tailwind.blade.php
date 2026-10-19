{{-- App pagination (overrides pagination::tailwind): slate palette for the always-dark UI, Vietnamese text (§8).
     The current page and the focus ring follow the shell accent (indigo: ADMIN, teal: TESTER, S14-06). --}}
@if ($paginator->hasPages())
    @php
        $accentUser = auth()->user();
        $pageBase = 'inline-flex items-center border border-slate-700 px-3 py-1.5 text-xs font-medium leading-5 transition';
        $pageLink = $pageBase.' bg-slate-800 text-slate-200 hover:bg-slate-700 hover:text-white focus:outline-none focus:ring-2 '.\App\Support\AccentClasses::focusRing($accentUser);
        $pageDisabled = $pageBase.' bg-slate-900/60 text-slate-400 cursor-not-allowed';
        $pageCurrent = $pageBase.' '.\App\Support\AccentClasses::currentPage($accentUser).' cursor-default';
    @endphp
    <nav role="navigation" aria-label="Phân trang" class="ait-pagination">
        <div class="flex items-center justify-between gap-2 sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="{{ $pageDisabled }} rounded-lg">&laquo; Trước</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $pageLink }} rounded-lg">&laquo; Trước</a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $pageLink }} rounded-lg">Sau &raquo;</a>
            @else
                <span class="{{ $pageDisabled }} rounded-lg">Sau &raquo;</span>
            @endif
        </div>

        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between sm:gap-2">
            <p class="text-xs leading-5 text-slate-300">
                Hiển thị
                @if ($paginator->firstItem())
                    <span class="font-semibold text-white">{{ $paginator->firstItem() }}</span>
                    đến
                    <span class="font-semibold text-white">{{ $paginator->lastItem() }}</span>
                @else
                    {{ $paginator->count() }}
                @endif
                trên
                <span class="font-semibold text-white">{{ $paginator->total() }}</span>
                kết quả
            </p>

            <span class="inline-flex flex-wrap">
                @if ($paginator->onFirstPage())
                    {{-- A plain span may not carry aria-label/aria-disabled (axe aria-prohibited-attr): screen readers get sr-only text. --}}
                    <span class="{{ $pageDisabled }} rounded-l-lg"><span aria-hidden="true">&lsaquo;</span><span class="sr-only">Trang trước (không khả dụng)</span></span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Trang trước" class="{{ $pageLink }} rounded-l-lg"><span aria-hidden="true">&lsaquo;</span></a>
                @endif

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="{{ $pageDisabled }} -ml-px">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page" class="{{ $pageCurrent }} -ml-px">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" aria-label="Đến trang {{ $page }}" class="{{ $pageLink }} -ml-px">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Trang sau" class="{{ $pageLink }} -ml-px rounded-r-lg"><span aria-hidden="true">&rsaquo;</span></a>
                @else
                    <span class="{{ $pageDisabled }} -ml-px rounded-r-lg"><span aria-hidden="true">&rsaquo;</span><span class="sr-only">Trang sau (không khả dụng)</span></span>
                @endif
            </span>
        </div>
    </nav>
@endif
