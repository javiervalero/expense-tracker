import { defineConfig, globalIgnores } from 'eslint/config'
import tseslint from 'typescript-eslint'
import pluginVue from 'eslint-plugin-vue'
import vueParser from 'vue-eslint-parser'
import pluginVitest from '@vitest/eslint-plugin'
import skipFormatting from 'eslint-config-prettier/flat'

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

  skipFormatting,
)
