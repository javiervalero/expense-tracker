import { reload } from '@/lib/navigation'

interface BfcacheTarget {
  addEventListener(type: 'pageshow', listener: (event: PageTransitionEvent) => void): void
}

/**
 * On a shared machine, Back after sign-out (or after the expiry redirect) can restore the frozen page of the old user
 * from the back-forward cache. A page shown from that cache (`persisted`) is reloaded, so it asks the server again.
 */
export function installBfcacheGuard(target: BfcacheTarget): void {
  target.addEventListener('pageshow', (event) => {
    if (event.persisted) {
      reload()
    }
  })
}
