@unless (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
    <div role="alert" data-testid="assets-missing" style="background:#7f1d1d;color:#fff;padding:12px 16px;font-family:sans-serif;font-size:14px;">
        Chưa có bản build giao diện (public/build/manifest.json). Hãy chạy <code>npm run build</code> trong thư mục <code>web/</code> hoặc bật service <code>vite</code> rồi tải lại trang.
    </div>
@endunless
