import { fileURLToPath, URL } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

// In development the app is opened through Caddy at https://localhost, which proxies
// everything except /api and /.well-known/mercure to this dev server. HMR therefore
// connects back through Caddy on 443.
export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.ico', 'apple-touch-icon-180x180.png', 'logo.svg'],
      manifest: {
        name: 'Tabooki',
        short_name: 'Tabooki',
        description: 'Recipe library, weekly meal plan and shopping list',
        lang: 'es',
        start_url: '/',
        scope: '/',
        display: 'standalone',
        background_color: '#f6efe2',
        theme_color: '#1f6f4a',
        icons: [
          { src: 'pwa-64x64.png', sizes: '64x64', type: 'image/png' },
          { src: 'pwa-192x192.png', sizes: '192x192', type: 'image/png' },
          { src: 'pwa-512x512.png', sizes: '512x512', type: 'image/png' },
          { src: 'maskable-icon-512x512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        // API calls and the Mercure hub must never be answered with the SPA shell.
        navigateFallbackDenylist: [/^\/api/, /^\/\.well-known\//, /^\/_profiler/, /^\/_wdt/, /^\/bundles\//],
      },
      devOptions: { enabled: false },
    }),
  ],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    // IPv4 loopback: Docker Desktop's host.docker.internal reaches 127.0.0.1, not ::1.
    host: '127.0.0.1',
    port: 5174,
    strictPort: true,
    hmr: { clientPort: 443, protocol: 'wss' },
  },
})
