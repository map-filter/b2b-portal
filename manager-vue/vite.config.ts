import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

const base = process.env.VITE_APP_BASE ?? '/'

export default defineConfig({
  base: `${base}manager/`,
  plugins: [vue(), tailwindcss()],
  server: {
    allowedHosts: true,
    hmr: { clientPort: Number(process.env.VITE_HMR_PORT ?? 8201) },
  },
})
