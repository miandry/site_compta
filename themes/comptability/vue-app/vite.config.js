import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'
import { fileURLToPath } from 'url'

const __dirname = fileURLToPath(new URL('.', import.meta.url))

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const drupal = env.VITE_DRUPAL_URL || 'http://comptability.local:8888'

  return {
    plugins: [vue()],
    resolve: {
      alias: {
        '@': resolve(__dirname, 'src'),
      },
    },
    server: {
      port: 5173,
      proxy: {
        '/api_solutions': { target: drupal, changeOrigin: true },
        '/sites/default/files': { target: drupal, changeOrigin: true },
      },
    },
    build: {
      outDir: resolve(__dirname, '../dist'),
      emptyOutDir: true,
      cssCodeSplit: false,
      rollupOptions: {
        input: resolve(__dirname, 'src/main.ts'),
        output: {
          // Drupal charge un seul fichier JS déclaré dans comptability.libraries.yml.
          inlineDynamicImports: true,
          entryFileNames: 'app.js',
          assetFileNames: 'assets/main.[ext]',
        },
      },
    },
  }
})
