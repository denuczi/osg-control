import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  plugins: [
    laravel({ input: ['resources/js/app.ts'], refresh: true }),
    vue({
      template: {
        transformAssetUrls: {
          // These files are served directly by Laravel from public/.
          includeAbsolute: false,
        },
      },
    }),
  ],
  resolve: { alias: { '@': '/resources/js' } },
});
