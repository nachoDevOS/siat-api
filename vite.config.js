import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

// Usan Vite el sitio publico (/) y la documentacion de la API (/docs/api).
// El panel /admin sigue con su CSS propio en Blade y no depende del build.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/sitio/main.jsx', 'resources/js/docs/main.jsx'],
            refresh: ['resources/views/sitio/**', 'resources/views/docs/**', 'docs/integracion/**'],
        }),
        react(),
    ],
});
