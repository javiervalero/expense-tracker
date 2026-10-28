// One table drives the specs of isInternalPath/navigateTo and of safeRedirect, so the two checks cannot drift apart.
// `internal`: what the shared string check says. `routes`: the value also resolves to a private route (safeRedirect
// then returns it; every other row gives /expenses there).
export interface InternalPathCase {
  name: string
  value: string
  internal: boolean
  routes: boolean
}

// Built by concatenation: the lint rule no-script-url bans the literal.
const SCRIPT_URL = 'java' + 'script:alert(1)'

function accepted(name: string, value: string, routes: boolean): InternalPathCase {
  return { name, value, internal: true, routes }
}
function refused(name: string, value: string): InternalPathCase {
  return { name, value, internal: false, routes: false }
}

export const INTERNAL_PATH_CASES: InternalPathCase[] = [
  accepted('private path', '/expenses', true),
  accepted('private path with a query', '/expenses?q=a', true),
  accepted('guest path (internal, but not a private route)', '/login?redirect=%2Fexpenses', false),
  accepted('unknown path', '/nope', false),
  accepted('encoded backslash (the check accepts it; it resolves to NotFound)', '/%5Cx', false),
  accepted('full-width solidus after the slash (not a separator)', '/／x', false),
  refused('empty', ''),
  refused('relative', 'expenses'),
  refused('leading space', ' /expenses'),
  refused('trailing newline', '/expenses\n'),
  refused('tab', '/exp\tenses'),
  refused('NUL', '/expenses\u0000'),
  refused('bidi override', '/expenses‮evil'),
  refused('no-break space', '/expenses '),
  refused('protocol-relative', '//x'),
  refused('slash-backslash', '/\\x'),
  refused('backslash later', '/expenses\\x'),
  refused('full-width solidus first', '／expenses'),
  refused('absolute URL', 'https://evil.example'),
  refused('script URL', SCRIPT_URL),
]
