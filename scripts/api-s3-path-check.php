<?php

declare(strict_types=1);

/**
 * Run INSIDE the api-php container (`make test-api-s3-path` pipes this file into `php` there). Proves decision 15(a):
 * php-fpm reaches S3 only through the nginx listener of `api` (http://api:9100), and not directly.
 *
 *  1. S3_ENDPOINT is that listener.
 *  2. A SIGNED request made with the application's own code (config, S3 client, object store) through it is answered
 *     by S3: a key that does not exist is reported as absent (NoSuchKey), which only happens when the signature,
 *     computed for the Host the SDK sent, verified. (A 403 would be an exception here.)
 *  3. s3:9000 is not reachable from here (api-internal has no route to it).
 *
 * Prints one line per check, never a credential or a configuration value. Exit code 0 only when all pass.
 */

use App\Infrastructure\Config\ApiConfig;
use App\Infrastructure\S3\S3ClientFactory;
use App\Infrastructure\S3\S3ObjectStore;

$taskRoot = getenv('LAMBDA_TASK_ROOT');
require (is_string($taskRoot) && $taskRoot !== '' ? $taskRoot : '/var/task') . '/vendor/autoload.php';

$failures = 0;
$report = static function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        ++$failures;
    }
};

$report('S3_ENDPOINT is the nginx listener http://api:9100', getenv('S3_ENDPOINT') === 'http://api:9100');

try {
    $config = ApiConfig::fromEnvironment(getenv());
    $store = new S3ObjectStore(S3ClientFactory::forCalls($config->s3));
    $absent = $store->get($config->s3->receiptsBucket, 'conformance/api-path-check-' . bin2hex(random_bytes(6)));
    $report('a signed request through api:9100 is answered by S3 (NoSuchKey)', $absent === null);
} catch (Throwable $e) {
    echo 'note ' . $e::class . "\n";
    $report('a signed request through api:9100 is answered by S3 (NoSuchKey)', false);
}

$direct = @fsockopen('s3', 9000, $errorNumber, $errorText, 2);
if (is_resource($direct)) {
    fclose($direct);
}
$report('s3:9000 is NOT reachable from api-php', $direct === false);

exit($failures === 0 ? 0 : 1);
