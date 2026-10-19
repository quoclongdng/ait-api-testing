{{-- Testing workspace links shared by both shells (S14-04): the project list (M05) and, on a screen
     bound to a project the user may view, that project's contextual links (M06/M07, M08, M09, M12).
     Only the route-bound project is used, never a view variable (NavigationContext). --}}
@php
    $navProject = \App\Support\NavigationContext::project(request());
    $navRunsSuite = $navProject ? \App\Support\NavigationContext::runsSuite(request(), $navProject) : null;
@endphp
<x-shell.nav-item :href="route('projects.index')" :active="request()->routeIs('projects.index')" icon="folder" :accent="$accent" testid="nav-projects">Dự án</x-shell.nav-item>
@if ($navProject)
    <li data-testid="nav-project-context">
        <div class="ml-5 mt-1 border-l border-slate-800 pl-3">
            <p id="nav-project-heading" class="truncate px-3 pb-1 pt-1 text-[11px] font-medium text-slate-400" title="{{ $navProject->name }}">
                <span class="sr-only">Dự án đang mở: </span>{{ $navProject->name }}
            </p>
            <ul role="list" aria-labelledby="nav-project-heading" class="space-y-0.5">
                <x-shell.nav-item nested :href="route('projects.specs.index', $navProject)" :active="request()->routeIs('projects.specs.*')" icon="document" :accent="$accent" testid="nav-project-specs">Đặc tả OpenAPI</x-shell.nav-item>
                <x-shell.nav-item nested :href="route('projects.business-rules.index', $navProject)" :active="request()->routeIs('projects.business-rules.*')" icon="scale" :accent="$accent" testid="nav-project-rules">Quy tắc nghiệp vụ</x-shell.nav-item>
                <x-shell.nav-item nested :href="route('projects.suites.index', $navProject)" :active="request()->routeIs('projects.suites.*', 'suites.show', 'suites.generation-jobs.*', 'generation-jobs.*', 'test-cases.*')" icon="clipboard" :accent="$accent" testid="nav-project-suites">Bộ kiểm thử</x-shell.nav-item>
                @if ($navRunsSuite)
                    <x-shell.nav-item nested :href="route('suites.runs.index', $navRunsSuite)" :active="request()->routeIs('suites.runs.*', 'runs.*')" icon="play" :accent="$accent" testid="nav-project-runs">Lần chạy</x-shell.nav-item>
                @endif
            </ul>
        </div>
    </li>
@endif
