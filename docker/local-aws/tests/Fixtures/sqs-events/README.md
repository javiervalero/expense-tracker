# SQS event fixtures

| File | Source |
|---|---|
| `aws-lambda-sqs-event.json` | Transcribed from "Example Amazon SQS message event (standard queue)" in the AWS Lambda documentation, [Using Lambda with Amazon SQS](https://docs.aws.amazon.com/lambda/latest/dg/with-sqs.html) (checked 2026-10-09). Unchanged except for whitespace. The `receiptHandle` values are truncated (`...`) in the documentation itself. |

It is the golden file of `SqsEventBuilderTest`: the runner must build the event the Lambda event source mapping builds.
Note that `eventSourceARN` and `awsRegion` are passed to the builder here; the runner itself uses the local placeholder
account `000000000000` (see `RunnerSettings`).

`md5OfMessageAttributes` is not emitted by the builder: the worker does not use it (it appears in the AWS example only
when message attributes exist).
