function isNamedError(error: unknown, name: string): boolean {
  // Duck-typed: a DOMException is not always an `Error` instance (for example under jsdom).
  return typeof error === 'object' && error !== null && 'name' in error && error.name === name
}

/** Why a request or a body read failed: the caller gave up, the timeout fired, or anything else (the network). */
export function failureReason(
  error: unknown,
  callerSignal: AbortSignal | undefined,
): 'aborted' | 'timeout' | 'network' {
  if (callerSignal?.aborted || isNamedError(error, 'AbortError')) {
    return 'aborted'
  }
  return isNamedError(error, 'TimeoutError') ? 'timeout' : 'network'
}
