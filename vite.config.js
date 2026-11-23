import Symfony from '@symfony/reprise/vite';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';

export default defineConfig({
    input: {
        app: './assets/app.js',
    },
    plugins: [
        Symfony({
            devServerOrigin: 'http://localhost:5173',
            stimulus: 'assets/controllers.json',
            copy: [{ from: 'assets/images', to: 'images' }],
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
    },
});
