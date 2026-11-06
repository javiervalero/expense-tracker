// CloudFront Function (runtime cloudfront-js-2.0), viewer-request, attached to the DEFAULT behaviour only (Phase 7 plan
// D19). The single-page app has client-side routes (/expenses/<id>, /login) that are not objects in the site bucket.
// A path whose last segment has no "." is such a route, so the request is sent to /index.html and the router takes over.
// Anything with a "." in the last segment (/favicon.ico, /robots.txt) is a file and stays as it is, and "/" stays "/"
// (default_root_object serves index.html for it). /assets/* and /api/* have behaviours of their own and never run this.
// request.uri holds the path only: the query string is in request.querystring and is passed on untouched.
// Keep it plain ES5, the subset every CloudFront Function runtime accepts.
function handler(event) {
  var request = event.request
  var uri = request.uri

  if (uri === '/') {
    return request
  }

  var lastSegment = uri.substring(uri.lastIndexOf('/') + 1)
  if (lastSegment.indexOf('.') === -1) {
    request.uri = '/index.html'
  }

  return request
}
