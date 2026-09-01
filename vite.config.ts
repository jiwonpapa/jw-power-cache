import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import { resolve } from 'node:path';

export default defineConfig({
    plugins: [react()],
    build: {
        lib: {
            entry: resolve(__dirname, 'resources/js/index.ts'),
            name: 'JWPowerCache',
            formats: ['iife'],
            cssFileName: 'plugin',
        },
        outDir: 'dist',
        rollupOptions: {
            external: ['react', 'react/jsx-runtime'],
            output: {
                globals: {
                    react: 'React',
                    'react/jsx-runtime': 'ReactJSXRuntime',
                },
                entryFileNames: 'js/plugin.iife.js',
                assetFileNames: (assetInfo) => assetInfo.name?.endsWith('.css')
                    ? 'css/plugin.css'
                    : 'assets/[name][extname]',
            },
        },
        sourcemap: !['0', 'false'].includes(process.env.G7_BUILD_SOURCEMAP ?? ''),
        minify: 'esbuild',
        target: 'es2020',
        chunkSizeWarningLimit: 100,
    },
});
