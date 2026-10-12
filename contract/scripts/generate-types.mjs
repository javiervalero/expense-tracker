// Generates TypeScript types from contract/openapi.yaml with openapi-typescript's Node API (the CLI cannot set the
// header comment).
//
// Usage: node scripts/generate-types.mjs [output-file]   (a relative path is relative to the repository root)
// Without an argument it writes the committed frontend/src/api/schema.gen.ts; `make contract-check-types` passes a
// temporary path and compares it with the committed file. The file is written to a temporary sibling and renamed, so
// a reader never sees a half-written file.
import { mkdirSync, renameSync, rmSync, writeFileSync } from 'node:fs'
import { dirname, relative, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import openapiTS, { astToString } from 'openapi-typescript'

const contractDir = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const repoRoot = resolve(contractDir, '..')
const specFile = resolve(contractDir, 'openapi.yaml')
const outputFile = resolve(repoRoot, process.argv[2] ?? 'frontend/src/api/schema.gen.ts')
const shown = (file) => relative(repoRoot, file) || file

const HEADER = `/**
 * GENERATED - DO NOT EDIT.
 * Source: contract/openapi.yaml. Regenerate with \`make contract-gen\`.
 * CI regenerates this file and fails if it differs from the committed one.
 * Do not rely on the generated types of response headers: they are documentation, not a contract the code enforces.
 */
`

async function main() {
  // defaultNonNullable: false keeps a property with a `default` optional (ProblemDetails.type may be omitted).
  const ast = await openapiTS(pathToFileURL(specFile), { defaultNonNullable: false })
  const temporaryFile = `${outputFile}.tmp-${process.pid}`
  mkdirSync(dirname(outputFile), { recursive: true })
  try {
    writeFileSync(temporaryFile, HEADER + '\n' + astToString(ast))
    renameSync(temporaryFile, outputFile)
  } catch (error) {
    rmSync(temporaryFile, { force: true })
    throw error
  }
  console.log(`wrote ${shown(outputFile)}`)
}

try {
  await main()
} catch (error) {
  console.error(`generate-types: could not generate ${shown(outputFile)} from ${shown(specFile)}: ${error instanceof Error ? error.message : error}`)
  process.exitCode = 1
}
