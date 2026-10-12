import { defineConfig, loadEnv } from 'vite'
import tailwindcss from '@tailwindcss/vite'
import laravel from 'laravel-vite-plugin'
import { wordpressPlugin, wordpressThemeJson } from '@roots/vite-plugin'
import path from 'path'

export default defineConfig(({ command, mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const wpOrigin = env.WP_HOME || 'http://h2otwock.local'

  return {
    server: {
      host: 'h2otwock.local',
      port: 6011,
      strictPort: true,
      cors: true,
      proxy: {
        '/wp-content/uploads': {
          target: wpOrigin,
          changeOrigin: true,
        },
      },
      hmr: {
        protocol: 'ws',
        host: 'h2otwock.local',
        port: 6011,
      },
    },

    base: command === 'build'
      ? '/wp-content/themes/h2otwock/public/build/'
      : '/build/',

    plugins: [
      tailwindcss({
        config: path.resolve(__dirname, 'tailwind.config.js'),
      }),

      laravel({
        input: [
          'resources/css/app.css',
          'resources/js/app.js',
          'resources/css/editor.css',
          'resources/js/editor.js',
        ],
        refresh: true,
      }),

      wordpressPlugin(),

      wordpressThemeJson({
        disableTailwindColors: false,
        disableTailwindFonts: false,
        disableTailwindFontSizes: false,
      }),
    ],

    resolve: {
      alias: {
        '@scripts': '/resources/js',
        '@styles': '/resources/css',
        '@fonts': '/resources/fonts',
        '@images': '/resources/images',
      },
    },
  }
})