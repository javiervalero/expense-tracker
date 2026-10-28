/**
 * A clock that never reads lower than an earlier reading. `read` is the raw clock (`Date.now`), which on a WSL2 or
 * virtual-machine host can be stepped back (observed: about 600 ms, twice in 25 s, in every process at once).
 *
 * Why tests need this: Vue skips a DOM event handler when `event._vts <= invoker.attached`, and both values come from
 * `Date.now()` (Vue runtime-dom `createInvoker`; Vue Test Utils sets `_vts = Date.now() + 1` in `trigger`). When the
 * clock steps back between a render and a `trigger`, the handler is silently not called, and the test fails at
 * random: a submit that sends nothing, a click that does nothing. Only tests that were running at that moment fail.
 */
export function makeMonotonic(read: () => number): () => number {
  let highest = Number.NEGATIVE_INFINITY
  return () => {
    highest = Math.max(highest, read())
    return highest
  }
}
