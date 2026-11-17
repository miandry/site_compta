/**
 * After Vite build: icons + manifest + sw.js at the Drupal docroot
 * (same layout as https://app.cache.mg/).
 */
import { execFileSync } from 'node:child_process'
import { copyFileSync, existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const themeRoot = join(__dirname, '../..')
const vueApp = join(__dirname, '..')
const pwaSrc = join(vueApp, 'pwa')
const iconsDir = join(pwaSrc, 'icons')
const distDir = join(themeRoot, 'dist')
const docroot = join(themeRoot, '../../../..') // sites/default/themes/comptability → Drupal root
const themeUrl = '/sites/default/themes/comptability'

mkdirSync(iconsDir, { recursive: true })

const phpBin = process.env.PHP_BIN || 'php'
execFileSync(phpBin, [join(__dirname, 'generate-pwa-icons.php'), iconsDir], { stdio: 'inherit' })

const iconFiles = [
  'manifest-icon-192.maskable.png',
  'manifest-icon-512.maskable.png',
  'apple-icon-180.png',
  'favicon-196.png',
]
for (const name of iconFiles) {
  copyFileSync(join(iconsDir, name), join(docroot, name))
}
copyFileSync(join(pwaSrc, 'manifest.webmanifest'), join(docroot, 'manifest.webmanifest'))

const precache = new Set([
  '/',
  '/manifest.webmanifest',
  ...iconFiles.map((n) => `/${n}`),
])

if (existsSync(join(distDir, 'app.js'))) {
  precache.add(`${themeUrl}/dist/app.js`)
}
if (existsSync(join(themeRoot, 'css/style.css'))) {
  precache.add(`${themeUrl}/css/style.css`)
}
const assetsDir = join(distDir, 'assets')
if (existsSync(assetsDir)) {
  for (const file of readdirSync(assetsDir)) {
    precache.add(`${themeUrl}/dist/assets/${file}`)
  }
}

const version = Date.now().toString(36)
const urls = [...precache].sort()
let sw = readFileSync(join(pwaSrc, 'sw.template.js'), 'utf8')
sw = sw
  .replace('__PRECACHE_NAME__', JSON.stringify(`comptability-precache-${version}`))
  .replace('__PRECACHE_URLS__', JSON.stringify(urls))

writeFileSync(join(docroot, 'sw.js'), sw)
writeFileSync(join(pwaSrc, 'sw.js'), sw)

console.log(`PWA written to ${relative(vueApp, docroot) || '.'}: sw.js + manifest + ${iconFiles.length} icons`)
console.log(`Precache (${urls.length}):`, urls.join(', '))
