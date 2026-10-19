{{--
    Generic error page (S14-02). Rendered by Laravel when APP_DEBUG=false (always in production):
    a Vietnamese message and the request's trace id only, never the exception, stack trace or SQL.
    Self-contained on purpose: no Vite assets, session, auth or database, so it still renders when
    those are the cause of the error. Expected variables: $status, $title, $message.
--}}
@php
    $traceId = \App\Support\TraceId::current();
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    {{-- Same identity as the guest layout (Inter, favicon, AIT mark, dark indigo palette) without its
         Vite assets: plain CSS so the page survives a missing build or a broken app. --}}
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#020617">
    <title>{{ $status }} – {{ $title }} — {{ config('app.name', 'AI Testing Platform') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; flex-direction: column; background: #020617; color: #e2e8f0; font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        .glow { position: fixed; inset: 0; z-index: -1; overflow: hidden; pointer-events: none; }
        .glow::before, .glow::after { content: ""; position: absolute; border-radius: 9999px; filter: blur(64px); }
        .glow::before { top: -10rem; left: -10rem; width: 28rem; height: 28rem; background: rgba(79, 70, 229, .2); }
        .glow::after { top: 33%; right: -12rem; width: 30rem; height: 30rem; background: rgba(8, 145, 178, .15); }
        header { border-bottom: 1px solid rgba(30, 41, 59, .8); }
        .bar { max-width: 80rem; margin: 0 auto; height: 64px; display: flex; align-items: center; padding: 0 16px; }
        .brand { display: inline-flex; align-items: center; gap: 12px; color: #fff; text-decoration: none; border-radius: 12px; }
        .mark { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(to top right, #4f46e5, #6366f1, #22d3ee); color: #fff; font-size: 14px; font-weight: 700; }
        .brand-name { display: flex; flex-direction: column; font-size: 16px; font-weight: 700; letter-spacing: -.01em; }
        .brand-name small { font-size: 11px; font-weight: 500; color: #94a3b8; }
        main { flex: 1; display: flex; align-items: center; justify-content: center; padding: 48px 16px; }
        .card { max-width: 32rem; width: 100%; background: rgba(15, 23, 42, .9); border: 1px solid #1e293b; border-radius: 16px; padding: 32px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, .5); }
        .code { display: inline-block; font-size: 12px; font-weight: 600; color: #c7d2fe; letter-spacing: .08em; background: rgba(99, 102, 241, .1); border: 1px solid rgba(99, 102, 241, .3); border-radius: 9999px; padding: 4px 12px; }
        h1 { font-size: 24px; line-height: 1.3; margin: 16px 0 8px; color: #fff; letter-spacing: -.01em; }
        p { line-height: 1.6; margin: 0 0 16px; color: #cbd5e1; font-size: 15px; }
        .home { display: inline-block; margin-top: 8px; color: #fff; background: #4f46e5; text-decoration: none; padding: 10px 18px; border-radius: 12px; font-weight: 600; font-size: 14px; }
        .home:hover { background: #6366f1; }
        a:focus-visible { outline: 2px solid #a5b4fc; outline-offset: 2px; }
        .trace { font-size: 13px; color: #94a3b8; border-top: 1px solid #1e293b; padding-top: 16px; margin: 24px 0 0; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: #e2e8f0; word-break: break-all; }
        @media (min-width: 640px) { .bar { padding: 0 24px; } }
    </style>
</head>
<body>
<div class="glow" aria-hidden="true"></div>
<header>
    <div class="bar">
        <a class="brand" href="{{ url('/') }}">
            <span class="mark" aria-hidden="true">AIT</span>
            <span class="brand-name">AI Testing Platform<small>Đại học Duy Tân</small></span>
        </a>
    </div>
</header>
<main>
    <div class="card">
        <span class="code">LỖI {{ $status }}</span>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        <a class="home" href="{{ url('/') }}">Về trang chủ</a>
        <p class="trace">Mã truy vết: <code>{{ $traceId }}</code><br>Nếu cần hỗ trợ, hãy gửi mã này cho quản trị viên.</p>
    </div>
</main>
</body>
</html>
