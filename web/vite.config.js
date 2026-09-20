import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// base './' → 可部署在任意子路径（如 /admin/）
export default defineConfig({
  base: './',
  plugins: [vue()],
  build: { outDir: 'dist', emptyOutDir: true },
  server: {
    port: 5173,
    // 开发期可代理到中台，避免跨域（生产由 CORS 处理）
    proxy: {
      '/api': { target: process.env.HUB_URL || 'https://cbc.weixiubang.club', changeOrigin: true, secure: true },
    },
  },
});
