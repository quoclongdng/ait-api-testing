import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// The dev compose service runs `npm run build -- --watch` (docker/docker-compose.yml). In watch mode keep the
// previous build in public/build while rebuilding, so a page loaded mid-rebuild still finds manifest.json and
// does not show the assets-missing notice; never watch Laravel's compiled views.
const watching = process.argv.includes('--watch') || process.argv.includes('-w');

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        emptyOutDir: !watching,
        watch: watching ? { exclude: ['**/storage/framework/views/**', '**/node_modules/**'] } : null,
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
