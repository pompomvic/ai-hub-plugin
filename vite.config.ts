import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

const externals = [
  '@wordpress/components',
  '@wordpress/edit-post',
  '@wordpress/data',
  '@wordpress/data-controls',
  '@wordpress/element',
  '@wordpress/i18n',
  '@wordpress/plugins',
  'react',
  'react-dom'
];

export default defineConfig({
  root: 'js',
  base: './',
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'js')
    }
  },
  build: {
    outDir: path.resolve(__dirname, 'dist'),
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        admin: path.resolve(__dirname, 'js/admin/index.tsx')
      },
      external: externals,
      output: {
        format: 'iife',
        entryFileNames: 'assets/[name].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
        globals: {
          react: 'React',
          'react-dom': 'ReactDOM',
          '@wordpress/components': 'wp.components',
          '@wordpress/edit-post': 'wp.editPost',
          '@wordpress/data': 'wp.data',
          '@wordpress/data-controls': 'wp.dataControls',
          '@wordpress/element': 'wp.element',
          '@wordpress/i18n': 'wp.i18n',
          '@wordpress/plugins': 'wp.plugins'
        }
      }
    }
  },
  server: {
    port: 5173
  }
});
