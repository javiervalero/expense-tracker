// Tests of spa-rewrite.js (Phase 7 plan D19): the CloudFront Function that sends deep links of the single-page app to
// /index.html on the default behaviour. Run with `make tf-fn-test` (node:test in the pinned node image, no dependency).
// The function is loaded into a vm context that holds nothing but the function itself, the way CloudFront runs it: no
// require, no process, no fs. The query string is not part of request.uri (CloudFront keeps it in request.querystring).
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import vm from 'node:vm'

const source = readFileSync(join(dirname(fileURLToPath(import.meta.url)), 'spa-rewrite.js'), 'utf8')

function loadHandler() {
  const context = vm.createContext({})
  vm.runInContext(source, context, { filename: 'spa-rewrite.js' })
  return context.handler
}

function rewrite(uri, extra = {}) {
  const handler = loadHandler()
  const event = { version: '1.0', request: { method: 'GET', uri, headers: {}, cookies: {}, querystring: {}, ...extra } }
  return handler(event)
}

test('defines a handler function and nothing that needs Node', () => {
  assert.equal(typeof loadHandler(), 'function')
  assert.doesNotMatch(source, /\brequire\b|\bimport\b|\bprocess\b|\beval\b|new Function/)
})

for (const uri of [
  '/expenses/0b6f5d6a-3c0e-4d65-9a1d-1f2e3d4c5b6a',
  '/expenses',
  '/login',
  '/expenses/',
  '/a.b/c',
  '/v1.2/reports/monthly',
  '/expenses/new',
]) {
  test(`rewrites the deep link ${uri} to /index.html`, () => {
    assert.equal(rewrite(uri).uri, '/index.html')
  })
}

for (const uri of ['/', '/assets/x.js', '/assets/index-Bx9.css', '/favicon.ico', '/index.html', '/robots.txt']) {
  test(`leaves ${uri} unchanged`, () => {
    assert.equal(rewrite(uri).uri, uri)
  })
}

test('only the last path segment decides: a dot in an earlier segment does not protect the path', () => {
  assert.equal(rewrite('/a.b/c').uri, '/index.html')
  assert.equal(rewrite('/a/b.c').uri, '/a/b.c')
})

test('a percent-encoded dot is not a dot (CloudFront does not decode the uri)', () => {
  assert.equal(rewrite('/expenses%2Eexport').uri, '/index.html')
})

test('the query string is not part of the uri and is passed on untouched', () => {
  const request = rewrite('/expenses', { querystring: { q: { value: 'a.b' }, page: { value: '2' } } })
  assert.equal(request.uri, '/index.html')
  assert.deepEqual(request.querystring, { q: { value: 'a.b' }, page: { value: '2' } })
})

test('method, headers and cookies are passed on untouched', () => {
  const headers = { host: { value: 'd111111abcdef8.cloudfront.net' } }
  const cookies = { sid: { value: 'example' } }
  const request = rewrite('/expenses', { headers, cookies })
  assert.equal(request.method, 'GET')
  assert.deepEqual(request.headers, headers)
  assert.deepEqual(request.cookies, cookies)
})

test('returns the request, not a response (the request goes on to the origin)', () => {
  const request = rewrite('/expenses')
  assert.equal(request.statusCode, undefined)
  assert.equal(request.method, 'GET')
})
