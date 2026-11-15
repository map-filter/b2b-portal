import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
  base: '/manager/',
  plugins: [vue(), tailwindcss()],
  server: {
    allowedHosts: true,
    hmr: { clientPort: 8201 },
  },
})
