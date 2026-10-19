{{--
    Blank auth layout (S14-03): the login screen M01. No site header, navigation or footer — only the
    centred content on the brand background, so the form is the single focus of the page.
    Static content, no auth()->user(), no database, CSP without inline
    event handlers. The <head> core is the shared partial layouts.partials.head.
    Sections: title, description, robots, content.
--}}
@php
    // Inline @section values are already escaped by Blade: decode once, {{ }} escapes again.
    $pageTitle = html_entity_decode(trim($__env->yieldContent('title', config('app.name', 'AI Testing Platform'))), ENT_QUOTES | ENT_HTML5);
    $pageDescription = html_entity_decode(trim($__env->yieldContent('description', 'AI Testing Platform (AIT) – nền tảng tự động sinh, thực thi và đánh giá ca kiểm thử REST API.')), ENT_QUOTES | ENT_HTML5);
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    @include('layouts.partials.head', ['title' => $pageTitle])
    <meta name="description" content="{{ $pageDescription }}">
    <meta name="robots" content="@yield('robots', 'noindex, follow')">
    <link rel="canonical" href="{{ url()->current() }}">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 font-sans antialiased flex flex-col overflow-x-hidden selection:bg-indigo-500 selection:text-white" data-layout="auth">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded-lg focus:bg-indigo-600 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:shadow-lg">Bỏ qua đến nội dung chính</a>
    <x-assets-missing-notice />

    {{-- Ambient background glow (decorative) --}}
    <div aria-hidden="true" class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute -top-40 -left-40 h-[28rem] w-[28rem] rounded-full bg-indigo-600/20 blur-3xl"></div>
        <div class="absolute top-1/3 -right-48 h-[30rem] w-[30rem] rounded-full bg-cyan-600/15 blur-3xl"></div>
        <div class="absolute -bottom-48 left-1/4 h-96 w-96 rounded-full bg-indigo-500/10 blur-3xl"></div>
    </div>

    <main id="main" tabindex="-1" class="flex flex-1 flex-col items-center justify-center px-4 py-10 focus:outline-none">
        @yield('content')
    </main>
</body>
</html>
