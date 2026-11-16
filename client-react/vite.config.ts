import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

const base = process.env.VITE_APP_BASE ?? '/'

export default defineConfig({
  base,
  plugins: [react(), tailwindcss()],
  server: {
    allowedHosts: true,
    hmr: { clientPort: Number(process.env.VITE_HMR_PORT ?? 8201) },
  },
})
