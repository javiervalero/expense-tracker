<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * The invocation payload is not an SQS batch. Not a per-record failure: the whole invocation fails, as it does on
 * AWS when a function throws, and the whole batch becomes visible again.
 */
final class MalformedSqsEvent extends \RuntimeException {}
