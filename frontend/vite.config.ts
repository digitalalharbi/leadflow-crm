import path from 'node:path'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: { '@': path.resolve(__dirname, './src') },
  },
  server: {
    port: 5173,
    watch: {
      // Playwright writes traces, screenshots and HTML snapshots under e2e/ while
      // a test is running. Without this the watcher treats them as source edits
      // and reloads the page mid-assertion, which looks exactly like a flaky app.
      ignored: ['**/e2e/.results/**', '**/e2e/.report/**', '**/playwright-report/**'],
    },
    // Lets the SPA call the API on the same origin in development,
    // which keeps cookies and CORS out of the way entirely.
    proxy: {
      '/api': {
        target: process.env.VITE_PROXY_TARGET ?? 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  /*
   * The same proxy for `vite preview`, so a production build can be driven
   * against the local API exactly as the dev server is. Useful for checking the
   * built bundle, and for running the E2E suite on a machine where the dev
   * server's memory footprint is the thing that fails first.
   */
  preview: {
    port: 5173,
    proxy: {
      '/api': {
        target: process.env.VITE_PROXY_TARGET ?? 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    sourcemap: false,
    rollupOptions: {
      output: {
        // Split the heavy, rarely-changing libraries so a UI tweak does not
        // invalidate the whole bundle for returning users.
        manualChunks(id: string) {
          if (!id.includes('node_modules')) return undefined
          if (id.includes('recharts') || id.includes('d3-')) return 'charts'
          if (id.includes('react-router')) return 'router'
          if (id.includes('/react/') || id.includes('react-dom')) return 'react'
          if (id.includes('@tanstack')) return 'query'
          return undefined
        },
      },
    },
  },
})
