<?php

declare(strict_types=1);

namespace App\Tests\Unit\Queue;

/**
 * Test double: an exception of the project own namespace, whose message may be logged.
 */
final class RecordRejected extends \RuntimeException {}
