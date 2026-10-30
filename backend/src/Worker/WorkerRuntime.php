<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * PHP settings that belong to the receipt worker alone, applied by its Lambda handler file before the first record.
 *
 * `memory_limit` is set explicitly. Bref's default for a function is the Lambda's whole memory (10240M here), so a bug
 * that let a hostile image through the size checks would end as the operating system killing the process. About three
 * quarters of the planned 1024 MB leaves room for the runtime and the buffers outside PHP's limit, so that such a bug
 * ends as a logged PHP fatal error instead. That is NOT contained to one record: a memory-limit fatal cannot be caught,
 * it ends the whole invocation, and every record of the batch is delivered again (and the hostile one again with them).
 * Containing it needs one record per invocation, so an SQS batch size of 1 is a hard Phase 7 requirement for this
 * worker (see the Phase 5 plan's carry list). The limit lives in code rather than in an ini file
 * because `backend/php/conf.d/app.ini` is shared with the API (whose limits must not change), and because the worker's
 * zip and image then need nothing extra.
 */
final class WorkerRuntime
{
    public const string MEMORY_LIMIT = '768M';

    private function __construct() {}

    public static function configure(): void
    {
        if (ini_set('memory_limit', self::MEMORY_LIMIT) === false) {
            throw new \RuntimeException('The worker cannot set its memory limit.');
        }
    }
}
