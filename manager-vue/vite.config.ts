import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

const base = process.env.VITE_APP_BASE ?? '/'

export default defineConfig({
  base: `${base}manager/`,
  plugins: [vue(), tailwindcss()],
  server: {
    allowedHosts: true,
    hmr: { clientPort: 8201 },
  },
})
