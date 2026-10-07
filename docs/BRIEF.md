# Brief: Expense Tracker

## Purpose
A personal expense tracker used as a realistic proof of concept. Small in scope, but built and deployed the way a production application would be.

## What users can do
- Create an account, sign in and sign out.
- Record expenses with an amount, date, description and category.
- Manage their own categories.
- View, filter, edit and delete their expenses.
- Attach a receipt (image or PDF) to an expense and see a preview of it.
- See a monthly summary of spending by category.

## Expectations
- Users can only ever see and change their own data.
- Receipt files can be large; they should not have to pass through the application server.
- Receipt processing (for example, generating a thumbnail) should not block the user.
- The application should be safe to expose on the public internet.
- It should be possible to run the whole system locally without an AWS account, and to deploy it to AWS afterwards.
- Automated tests and a CI pipeline are expected.

## Out of scope
Multi-user sharing, mobile apps, multi-currency conversion, OCR, email flows (verification, password reset), internationalization.

## Done means
- The features above work end to end, locally and on AWS.
- The owner can understand how it is built from the repository's documentation.
- Infrastructure can be created and destroyed from code.
