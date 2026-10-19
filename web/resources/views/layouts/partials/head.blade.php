{{--
    Shared <head> core of every page layout (S14-03/S14-04): the guest layout (M00, M01) and the
    role shells (layouts.base → layouts.admin / layouts.tester) include this partial so that charset,
    viewport, title, favicon, fonts and the Vite assets stay identical everywhere.
    CSP (ADR-0007): scripts only through <x-vite-assets /> (nonce); no inline event handlers.
    Parameters: $title (string, required), $withCsrf (bool, default false: emit the csrf-token meta
    read by fetch() calls of the signed-in screens), $themeColor (string, default #020617).
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
@if ($withCsrf ?? false)
<meta name="csrf-token" content="{{ csrf_token() }}">
@endif
<title>{{ $title }}</title>
<meta name="theme-color" content="{{ $themeColor ?? '#020617' }}">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

<x-vite-assets />
<style>
    [x-cloak] { display: none !important; }
</style>
