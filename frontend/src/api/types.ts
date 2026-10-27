// Aliases only: the shapes come from the generated schema (contract/openapi.yaml), never written by hand.
import type { components } from './schema.gen'

type Schemas = components['schemas']

export type User = Schemas['User']
export type Credentials = Schemas['Credentials']
export type Category = Schemas['Category']
export type CategoryList = Schemas['CategoryList']
export type NewCategory = Schemas['NewCategory']
/** The body of a category rename (the contract calls it `CategoryRename`). */
export type CategoryRename = Schemas['CategoryRename']
export type Expense = Schemas['Expense']
export type ExpensePage = Schemas['ExpensePage']
export type NewExpense = Schemas['NewExpense']
export type ExpensePatch = Schemas['ExpensePatch']
export type ProblemDetails = Schemas['ProblemDetails']
export type ValidationProblemDetails = Schemas['ValidationProblemDetails']
