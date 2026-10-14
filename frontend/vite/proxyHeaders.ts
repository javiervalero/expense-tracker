import type { Socket } from 'node:net'

export const VIEWER_ADDRESS_HEADER = 'CloudFront-Viewer-Address'
export const ORIGIN_VERIFY_HEADER = 'X-Origin-Verify'

/** The part of an outgoing `http.ClientRequest` that is used here (so tests need no real request). */
export interface HeaderWritable {
  removeHeader(name: string): void
  setHeader(name: string, value: string): unknown
}

const IPV4_MAPPED_PREFIX = '::ffff:'

/**
 * The headers the dev proxy adds in front of the API, standing in for CloudFront (ADR 0012).
 *
 * - `CloudFront-Viewer-Address`: `host:port` of the socket that reached the dev server, in CloudFront's format:
 *   IPv4 as `a.b.c.d:port`, IPv6 as `addr:port` with no brackets. An IPv4-mapped IPv6 address
 *   (`::ffff:a.b.c.d`, which Node reports for IPv4 clients on a dual-stack socket) becomes plain IPv4.
 *   Left out, never guessed, when the socket has no address.
 * - `X-Origin-Verify`: the local, non-secret value. The API rejects any request without it (403, ADR 0012).
 */
export function buildForwardHeaders(
  remoteAddress: string | undefined,
  remotePort: number | undefined,
  originVerify: string,
): Record<string, string> {
  const headers: Record<string, string> = { [ORIGIN_VERIFY_HEADER]: originVerify }
  if (!remoteAddress || remotePort === undefined) {
    return headers
  }
  const address = remoteAddress.toLowerCase().startsWith(IPV4_MAPPED_PREFIX)
    ? remoteAddress.slice(IPV4_MAPPED_PREFIX.length)
    : remoteAddress
  return { ...headers, [VIEWER_ADDRESS_HEADER]: `${address}:${remotePort}` }
}

/**
 * Applies the forwarded headers to the request going to the API. Whatever the browser sent under these two names is
 * removed first, so it can never reach the API: CloudFront overwrites them in production, and so does this.
 */
export function applyForwardHeaders(
  proxyReq: HeaderWritable,
  socket: Pick<Socket, 'remoteAddress' | 'remotePort'>,
  originVerify: string,
): void {
  proxyReq.removeHeader(VIEWER_ADDRESS_HEADER)
  proxyReq.removeHeader(ORIGIN_VERIFY_HEADER)
  const headers = buildForwardHeaders(socket.remoteAddress, socket.remotePort, originVerify)
  for (const [name, value] of Object.entries(headers)) {
    proxyReq.setHeader(name, value)
  }
}

/** The browser origins of the Vite dev server (it only ever serves on port 5173, `strictPort`). */
const ALLOWED_ORIGINS: readonly string[] = ['http://localhost:5173', 'http://127.0.0.1:5173']

/** True when there is no Origin header (curl, health checks, same-origin GET) or it is the local dev server. */
export function isAllowedOrigin(origin: string | undefined): boolean {
  return origin === undefined || ALLOWED_ORIGINS.includes(origin)
}

/**
 * Local CSRF mitigation: a web page on another origin that makes the browser call http://localhost:5173/api/... (the
 * proxy would add X-Origin-Verify for it) gets a 403 instead. A request without an Origin header is not a cross-site
 * browser request and passes. Returns true when it answered the request.
 */
export function rejectForeignOrigin(
  req: { headers: { origin?: string } },
  res: { statusCode: number; end(): unknown },
): boolean {
  if (isAllowedOrigin(req.headers.origin)) {
    return false
  }
  res.statusCode = 403
  res.end()
  return true
}
