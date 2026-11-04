import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// In sviluppo le chiamate /api vengono inoltrate al server PHP locale:
//   php -S 127.0.0.1:8089 -t backend backend/scripts/dev-router.php
export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: false
  },
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://127.0.0.1:8089'
    }
  }
});
