import { defineConfig, globalIgnores } from 'eslint/config'
import tseslint from 'typescript-eslint'
import pluginVue from 'eslint-plugin-vue'
import vueParser from 'vue-eslint-parser'
import pluginVitest from '@vitest/eslint-plugin'
import skipFormatting from 'eslint-config-prettier/flat'

const COOKIE_MESSAGE = 'The client never reads the session cookie (plan phase-4 C3).'
const STORAGE_MESSAGE = 'No client-side storage of session data (plan phase-4 C3).'
const HTML_INJECTION_MESSAGE = 'No HTML injection (plan phase-4 C16).'
const REQUEST_MESSAGE =
  'Requests go through src/api/http.ts only; the app opens no other channel (plan phase-4 C3 and C16).'
const NAVIGATION_MESSAGE = 'Navigation goes through src/lib/navigation.ts only (plan phase-4 C5).'

// Shared by the app block and its overrides: an override replaces the rule, so each one is built from these.
// The ways to reach the global object: each restricted property is listed on every one of them.
const GLOBAL_OBJECTS = ['window', 'globalThis', 'self']
const COOKIE_AND_HTML_PROPERTIES = [
  { object: 'document', property: 'cookie', message: COOKIE_MESSAGE },
  { object: 'document', property: 'write', message: HTML_INJECTION_MESSAGE },
  { object: 'document', property: 'writeln', message: HTML_INJECTION_MESSAGE },
  ...GLOBAL_OBJECTS.flatMap((object) => [
    { object, property: 'document', message: COOKIE_MESSAGE },
    { object, property: 'localStorage', message: STORAGE_MESSAGE },
    { object, property: 'sessionStorage', message: STORAGE_MESSAGE },
  ]),
]
const FETCH_PROPERTIES = GLOBAL_OBJECTS.map((object) => ({
  object,
  property: 'fetch',
  message: REQUEST_MESSAGE,
}))
const LOCATION_PROPERTIES = [
  ...GLOBAL_OBJECTS.map((object) => ({
    object,
    property: 'location',
    message: NAVIGATION_MESSAGE,
  })),
  { object: 'document', property: 'location', message: NAVIGATION_MESSAGE },
]
const OTHER_PROPERTIES = [
  { object: 'navigator', property: 'sendBeacon', message: REQUEST_MESSAGE },
  { object: 'document', property: 'defaultView', message: NAVIGATION_MESSAGE },
  ...GLOBAL_OBJECTS.flatMap((object) => [
    { object, property: 'open', message: NAVIGATION_MESSAGE },
    { object, property: 'indexedDB', message: STORAGE_MESSAGE },
  ]),
]
const STORAGE_GLOBALS = [
  { name: 'localStorage', message: STORAGE_MESSAGE },
  { name: 'sessionStorage', message: STORAGE_MESSAGE },
]
const FETCH_GLOBAL = { name: 'fetch', message: REQUEST_MESSAGE }
const LOCATION_GLOBAL = { name: 'location', message: NAVIGATION_MESSAGE }
const OTHER_GLOBALS = [
  { name: 'XMLHttpRequest', message: REQUEST_MESSAGE },
  { name: 'WebSocket', message: REQUEST_MESSAGE },
  { name: 'EventSource', message: REQUEST_MESSAGE },
  { name: 'indexedDB', message: STORAGE_MESSAGE },
  { name: 'caches', message: STORAGE_MESSAGE },
  { name: 'top', message: NAVIGATION_MESSAGE },
  { name: 'parent', message: NAVIGATION_MESSAGE },
  { name: 'frames', message: NAVIGATION_MESSAGE },
]

export default defineConfig(
  {
    name: 'app/files-to-lint',
    files: ['**/*.{vue,ts,mts,tsx}'],
  },

  // schema.gen.ts is generated from contract/openapi.yaml (`make contract-gen`); it is never linted.
  globalIgnores(['**/dist/**', '**/coverage/**', 'src/api/schema.gen.ts']),

  ...pluginVue.configs['flat/recommended'],
  ...tseslint.configs.recommended,

  {
    // <script lang="ts"> blocks in .vue files are parsed by typescript-eslint's parser, through vue-eslint-parser.
    name: 'app/vue-typescript',
    files: ['**/*.vue'],
    languageOptions: {
      parser: vueParser,
      parserOptions: {
        parser: tseslint.parser,
        extraFileExtensions: ['.vue'],
        sourceType: 'module',
      },
    },
  },

  {
    ...pluginVitest.configs.recommended,
    files: ['src/**/*.spec.ts', 'vite/**/*.spec.ts'],
  },

  {
    // Security hygiene (plan phase-4 C3 and C16). vue/no-v-html is only a warning in flat/recommended, and warnings do
    // not fail `eslint .`. The core no-implied-eval is used: the typescript-eslint one needs type-aware linting.
    name: 'app/security',
    files: ['src/**/*.{ts,vue}'],
    // no-implied-eval only sees timers it knows as globals; no globals package is installed, so the three are declared.
    languageOptions: {
      globals: { window: 'readonly', setTimeout: 'readonly', setInterval: 'readonly' },
    },
    rules: {
      'vue/no-v-html': 'error',
      'vue/no-v-text-v-html-on-component': 'error',
      'no-console': 'error',
      'no-debugger': 'error',
      'no-alert': 'error',
      'no-eval': 'error',
      'no-implied-eval': 'error',
      'no-new-func': 'error',
      'no-script-url': 'error',
      // C3: no cookie access, also through window or globalThis (so window.document is banned as a whole). C16: no
      // document.write, which injects HTML.
      'no-restricted-properties': [
        'error',
        ...COOKIE_AND_HTML_PROPERTIES,
        ...FETCH_PROPERTIES,
        ...LOCATION_PROPERTIES,
        ...OTHER_PROPERTIES,
      ],
      'no-restricted-globals': [
        'error',
        ...STORAGE_GLOBALS,
        FETCH_GLOBAL,
        LOCATION_GLOBAL,
        ...OTHER_GLOBALS,
      ],
      // C16: user text is rendered by interpolation only; no assigning or inserting HTML.
      'no-restricted-syntax': [
        'error',
        {
          selector: 'AssignmentExpression[left.property.name=/^(inner|outer)HTML$/]',
          message: HTML_INJECTION_MESSAGE,
        },
        {
          selector: "CallExpression[callee.property.name='insertAdjacentHTML']",
          message: HTML_INJECTION_MESSAGE,
        },
      ],
      // C16: no target="_blank". vue/no-template-target-blank only flags external or dynamic links without rel, so
      // it backs up the selector below for dynamic :href.
      'vue/no-template-target-blank': 'error',
      'vue/no-restricted-syntax': [
        'error',
        {
          selector: "VAttribute[directive=false][key.name='target'][value.value='_blank']",
          message: 'No target="_blank" (plan phase-4 C16).',
        },
      ],
    },
  },

  {
    // The only places that may call fetch. An override replaces the whole rule, so the rest of the list is repeated.
    name: 'app/allow-fetch',
    files: ['src/api/http.ts', 'src/api/health.ts'],
    rules: {
      'no-restricted-properties': [
        'error',
        ...COOKIE_AND_HTML_PROPERTIES,
        ...LOCATION_PROPERTIES,
        ...OTHER_PROPERTIES,
      ],
      'no-restricted-globals': ['error', ...STORAGE_GLOBALS, LOCATION_GLOBAL, ...OTHER_GLOBALS],
    },
  },

  {
    // The only place that may touch location (window.location, self.location, ...).
    name: 'app/allow-location',
    files: ['src/lib/navigation.ts'],
    rules: {
      'no-restricted-properties': [
        'error',
        ...COOKIE_AND_HTML_PROPERTIES,
        ...FETCH_PROPERTIES,
        ...OTHER_PROPERTIES,
      ],
      'no-restricted-globals': ['error', ...STORAGE_GLOBALS, FETCH_GLOBAL, ...OTHER_GLOBALS],
    },
  },

  {
    // Specs stub fetch, location and the like; the cookie, storage and HTML rules still apply to them.
    name: 'app/spec-stubs',
    files: ['src/**/*.spec.ts'],
    rules: {
      'no-restricted-properties': ['error', ...COOKIE_AND_HTML_PROPERTIES],
      'no-restricted-globals': ['error', ...STORAGE_GLOBALS],
    },
  },

  skipFormatting,
)
