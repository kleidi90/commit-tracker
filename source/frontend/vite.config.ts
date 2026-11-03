import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [vue()],
  server: {
    // For `npm run dev` on your machine: API calls go to the Docker stack.
    proxy: {
      '/api': 'http://localhost:8080',
    },
  },
})
