import { makeMonotonic } from './monotonicClock'

// Runs before every spec file (see `test.setupFiles` in vite.config.ts). Glue only: the logic is in monotonicClock.ts.
Date.now = makeMonotonic(Date.now.bind(Date))
