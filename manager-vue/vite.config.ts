import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
  base: '/manager/',
  plugins: [vue()],
  server: {
    allowedHosts: true,
    hmr: { clientPort: 8201 },
  },
})
