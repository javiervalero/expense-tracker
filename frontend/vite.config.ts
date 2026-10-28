import { fileURLToPath, URL } from 'node:url'

import vue from '@vitejs/plugin-vue'
import { loadEnv } from 'vite'
import { defineConfig } from 'vitest/config'

import { PUBLIC_ENV_PREFIX, assertNoOriginInPublicEnv, resolveDevEnv } from './vite/devEnv.ts'
import { applyForwardHeaders, rejectForeignOrigin } from './vite/proxyHeaders.ts'

// The repository root, where .env lives (frontend/ is one level down). Under Compose only frontend/ is mounted, so
// there is no .env there and the values come straight from the container's environment.
const repoRoot = fileURLToPath(new URL('..', import.meta.url))

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  // The empty prefix loads variables that are NOT VITE_-prefixed. That is the point: Vite only copies VITE_ variables
  // into the browser bundle, so ORIGIN_VERIFY_LOCAL stays in this Node process (checked by `make check-bundle-secrets`).
  const env = loadEnv(mode, repoRoot, '')
  assertNoOriginInPublicEnv(env)
  const { apiTarget, originVerify, cacheDir } = resolveDevEnv(env)

  return {
    plugins: [vue()],
    // Same .env as Make and Compose use. Explicit (it is also Vite's default): ONLY names starting with VITE_ are
    // exposed to the browser bundle; never widen this to '' or add a prefix that ORIGIN_VERIFY_LOCAL matches.
    envDir: repoRoot,
    envPrefix: PUBLIC_ENV_PREFIX,
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    build: {
      // Never inline assets as data: URLs: the strict CSP has img-src 'self' (plan phase-4, Step 0 R7).
      assetsInlineLimit: 0,
    },
    // Compose runs Vite on a read-only file system and points this at a tmpfs; on a host it stays the default.
    cacheDir,
    server: {
      port: 5173,
      // Never moves to another port: the S3 CORS rule and the documentation say 5173. `--host 0.0.0.0` is passed on the
      // command line by Compose only (inside the container the published port is bound to 127.0.0.1 on the host).
      strictPort: true,
      // The browser only ever uses localhost; Vite refuses any other Host header (DNS rebinding).
      allowedHosts: ['localhost', '127.0.0.1'],
      // The dev server serves files from the project over HTTP; the config and any .env file are never to be served.
      fs: { deny: ['.env', '.env.*', '*.{crt,pem}', 'vite.config.*', '**/.git/**'] },
      proxy: {
        '/api': {
          target: apiTarget,
          // Keeps the browser's Host header (localhost:5173). nginx does not route on it.
          changeOrigin: false,
          // Local CSRF mitigation: a page on another origin cannot make the browser use this proxy (which adds
          // X-Origin-Verify). A foreign Origin header gets 403; the response is ended here, so Vite does not proxy it.
          bypass: (req, res) => (res && rejectForeignOrigin(req, res) ? req.url : undefined),
          configure: (proxy) => {
            // Stands in for CloudFront (ADR 0012): adds X-Origin-Verify and ALWAYS overwrites
            // CloudFront-Viewer-Address, because nginx keeps a client-supplied one.
            proxy.on('proxyReq', (proxyReq, req) => {
              applyForwardHeaders(proxyReq, req.socket, originVerify)
            })
          },
        },
      },
    },
    test: {
      environment: 'jsdom',
      include: ['src/**/*.spec.ts', 'vite/**/*.spec.ts'],
      // Makes Date.now() monotonic: a stepped-back host clock otherwise makes Vue skip event handlers (monotonicClock.ts).
      setupFiles: ['./vite/vitest.setup.ts'],
      coverage: {
        provider: 'v8',
        include: ['src/**/*.{ts,vue}', 'vite/**/*.ts'],
        // schema.gen.ts is generated (checked by `make contract`); main.ts is only the bootstrap that
        // mounts the app and has no logic of its own; the spec files are the tests.
        exclude: ['src/api/schema.gen.ts', 'src/main.ts', 'vite/vitest.setup.ts', '**/*.spec.ts'],
        reporter: ['text', 'json-summary'],
        thresholds: { lines: 80, branches: 80, functions: 80, statements: 80 },
      },
    },
  }
})
