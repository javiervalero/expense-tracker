# 0005. Single origin for app and API through CloudFront

- Status: Accepted (2026-10-09)
- Amended by: [0012](0012-runtime-platform.md) (CloudFront-Viewer-Address forwarding; secret origin header check and rotation)
- Date: 2026-10-07

## Context

The Vue app is a set of static files, and the API runs on API Gateway + Lambda. If they were served from different
domains, the browser would need CORS rules for every API call, and the session cookie would become a cross-site
cookie, which is weaker and increasingly blocked by browsers.

## Decision

Serve everything from **one CloudFront distribution**:
- `/*` → private S3 bucket holding the built front-end (read through Origin Access Control);
- `/api/*` → API Gateway HTTP API, with caching disabled and cookies, headers and query strings forwarded.

CloudFront also:
- adds security headers via a response-headers policy: CSP, HSTS, `X-Content-Type-Options`, `Referrer-Policy`,
  frame protection;
- redirects HTTP to HTTPS.

The API Gateway endpoint is not meant to be called directly. It checks a secret origin header that only CloudFront
sends.

Locally, the Vite dev server proxies `/api` to the PHP container, which gives the same single-origin behaviour.

## Alternatives considered

- **Separate domains with CORS.** More configuration and a weaker cookie model.
- **Serving the front-end from PHP.** Puts static files through Lambda for no benefit.

## Consequences

- First-party cookies, no CORS, one place for TLS and security headers.
- A custom domain (Route 53 + ACM certificate) is optional. Without one, the default `*.cloudfront.net` domain
  works.
- CloudFront changes take a few minutes to roll out, which slows down infrastructure iterations slightly.

## Implemented in Phase 7

Built in `infra/terraform/modules/edge` (decisions D17 to D19). CloudFront is AWS's content delivery network, the front
door for the site and the API.

- **`/api/*`:** sent to the API with caching turned off (the AWS managed `CachingDisabled` policy). Cookies, query strings
  and five headers are forwarded: `Origin`, `X-Requested-With`, `Content-Type`, `Accept` and `CloudFront-Viewer-Address`.
- **`/assets/*`:** served from the site bucket with a one-year cache (`Cache-Control: public, max-age=31536000, immutable`).
- **Everything else (default):** served from the site bucket. A small function sends single-page-app deep links (a last
  path segment with no dot, such as `/expenses/123`) to `/index.html`. It runs on the default behaviour only.
- **Secret origin header:** CloudFront adds `X-Origin-Verify` with the active secret to every API request. PHP checks it
  (see ADR 0012).
- There are no custom error pages, so the API's error responses reach the browser unchanged.
