/** The dev proxy's target when Vite runs on the host (Compose sets API_PROXY_TARGET to http://api:8080). */
export const DEFAULT_API_PROXY_TARGET = 'http://127.0.0.1:8080'
/** The documented local, non-secret value (.env.example). Production uses a real secret held in AWS. */
export const DEFAULT_ORIGIN_VERIFY_LOCAL = 'local-origin-verify-not-a-secret'
const DEFAULT_CACHE_DIR = 'node_modules/.vite'

/** Only variables with this prefix are copied into the browser bundle by Vite (`envPrefix` in vite.config.ts). */
export const PUBLIC_ENV_PREFIX = 'VITE_'

export interface DevEnv {
  apiTarget: string
  originVerify: string
  cacheDir: string
}

/** What the dev server needs from the environment. Throws, never guesses, when the origin-verify value is blank. */
export function resolveDevEnv(env: Record<string, string | undefined>): DevEnv {
  const originVerify = env.ORIGIN_VERIFY_LOCAL ?? DEFAULT_ORIGIN_VERIFY_LOCAL
  if (originVerify.trim() === '') {
    throw new Error(
      'ORIGIN_VERIFY_LOCAL is set but empty: the dev proxy would send an empty X-Origin-Verify. ' +
        'Unset it to use the local default, or give it a value (see .env.example).',
    )
  }
  return {
    apiTarget: env.API_PROXY_TARGET || DEFAULT_API_PROXY_TARGET,
    originVerify,
    cacheDir: env.FRONTEND_CACHE_DIR || DEFAULT_CACHE_DIR,
  }
}

/**
 * The origin-verify value must stay in the Node process. A variable named VITE_...ORIGIN... would be inlined into the
 * bundle, so it stops the dev server and the build (`make check-bundle-secrets` is the second line of defence).
 */
export function assertNoOriginInPublicEnv(env: Record<string, string | undefined>): void {
  const offending = Object.keys(env).filter(
    (name) => name.startsWith(PUBLIC_ENV_PREFIX) && /origin/i.test(name),
  )
  if (offending.length > 0) {
    throw new Error(
      `${offending.join(', ')}: a ${PUBLIC_ENV_PREFIX}* variable naming an origin would be copied into the browser bundle. Rename it without the ${PUBLIC_ENV_PREFIX} prefix.`,
    )
  }
}
