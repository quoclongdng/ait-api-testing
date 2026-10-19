{{-- Tài sản giao diện chỉ đến từ bản build Vite (D01); không có fallback CDN bên thứ ba. --}}
@if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@endif
