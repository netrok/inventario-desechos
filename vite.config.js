import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: 'http://localhost:5173',
        // Sin esto, Vite 7 devuelve Access-Control-Allow-Origin con su propio
        // origen (localhost:5173) en vez de reflejar el origen real de la
        // pagina (localhost:8080, o el APP_PORT que uses), y el navegador
        // bloquea la carga de app.js por CORS — Alpine.js nunca arranca y
        // ningun menu/dropdown reacciona, aunque el HTML y el CSS carguen bien.
        cors: {
            origin: [/^http:\/\/localhost(:\d+)?$/],
        },
        hmr: {
            host: 'localhost',
        },
    },
});
