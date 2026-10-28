import { describe, expectTypeOf, it } from 'vitest'

import type { components } from '../schema.gen'
import type {
  Category,
  CategoryList,
  CategoryRename,
  Credentials,
  Expense,
  ExpensePage,
  ExpensePatch,
  NewCategory,
  NewExpense,
  ProblemDetails,
  User,
  ValidationProblemDetails,
} from '../types'

type Schemas = components['schemas']

describe('types.ts aliases', () => {
  // The assertions are compile-time (checked by vue-tsc in `make frontend-lint`), so the rule cannot see them.
  // eslint-disable-next-line vitest/expect-expect
  it('are exactly the generated schema types', () => {
    expectTypeOf<User>().toEqualTypeOf<Schemas['User']>()
    expectTypeOf<Credentials>().toEqualTypeOf<Schemas['Credentials']>()
    expectTypeOf<Category>().toEqualTypeOf<Schemas['Category']>()
    expectTypeOf<CategoryList>().toEqualTypeOf<Schemas['CategoryList']>()
    expectTypeOf<NewCategory>().toEqualTypeOf<Schemas['NewCategory']>()
    expectTypeOf<CategoryRename>().toEqualTypeOf<Schemas['CategoryRename']>()
    expectTypeOf<Expense>().toEqualTypeOf<Schemas['Expense']>()
    expectTypeOf<ExpensePage>().toEqualTypeOf<Schemas['ExpensePage']>()
    expectTypeOf<NewExpense>().toEqualTypeOf<Schemas['NewExpense']>()
    expectTypeOf<ExpensePatch>().toEqualTypeOf<Schemas['ExpensePatch']>()
    expectTypeOf<ProblemDetails>().toEqualTypeOf<Schemas['ProblemDetails']>()
    expectTypeOf<ValidationProblemDetails>().toEqualTypeOf<Schemas['ValidationProblemDetails']>()
  })
})
