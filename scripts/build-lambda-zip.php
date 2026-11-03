<?php

declare(strict_types=1);

/**
 * Builds the one Lambda package both functions use (Phase 7, D11): a reproducible zip.
 *
 * Usage: php scripts/build-lambda-zip.php [--backend DIR] [--build-dir DIR] [--tfvars FILE]
 *   --backend    default backend
 *   --build-dir  default build/lambda (gets src/ and backend.zip; both are replaced)
 *   --tfvars     default infra/terraform/envs/dev/package.auto.tfvars.json (git-ignored)
 *
 * Steps: copy {public, entrypoints, src, php/conf.d, composer.json, composer.lock} to <build-dir>/src, run
 * `composer install --no-dev --classmap-authoritative --no-scripts` there, then zip with the `zip` binary of the tools
 * image (there is no ext-zip). Reproducible: entries sorted by byte order, fixed mtime, fixed permissions, no
 * directory entries, no extra attributes, UTC. The tfvars file holds {lambda_package_path, lambda_package_sha256};
 * the path is relative to the tfvars file's directory, which is where Terraform runs (-chdir).
 * Relative arguments are relative to the current directory (run it from the repository root).
 */

const FIXED_MTIME = 1577836800; // 2020-01-01 00:00:00 UTC
const COPIED = ['public', 'entrypoints', 'src', 'php/conf.d', 'composer.json', 'composer.lock'];

function fail(string $message): never
{
    fwrite(STDERR, 'build-lambda-zip: ' . $message . "\n");
    exit(1);
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $entries = scandir($path);
    foreach ($entries === false ? [] : $entries as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            removeTree($path . '/' . $entry);
        }
    }
    rmdir($path);
}

function copyTree(string $from, string $to): void
{
    if (is_file($from)) {
        if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0755, true)) {
            fail('cannot create ' . dirname($to));
        }
        if (!copy($from, $to)) {
            fail("cannot copy {$from}");
        }

        return;
    }
    if (!is_dir($to) && !mkdir($to, 0755, true)) {
        fail("cannot create {$to}");
    }
    $entries = scandir($from);
    foreach ($entries === false ? [] : $entries as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            copyTree($from . '/' . $entry, $to . '/' . $entry);
        }
    }
}

/**
 * @param list<string> $command
 */
function run(array $command, string $cwd, ?string $stdin = null): void
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => STDERR, 2 => STDERR], $pipes, $cwd, ['TZ' => 'UTC'] + getenv());
    if (!is_resource($process)) {
        fail('cannot start ' . $command[0]);
    }
    fwrite($pipes[0], $stdin ?? '');
    fclose($pipes[0]);
    $code = proc_close($process);
    if ($code !== 0) {
        fail("{$command[0]} exited with {$code}");
    }
}

/**
 * @return list<string> every file below $root, as paths relative to it, in byte order
 */
function listFiles(string $root): array
{
    $files = [];
    $walk = static function (string $dir) use (&$walk, &$files, $root): void {
        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $walk($path);
            } elseif (is_file($path)) {
                $files[] = substr($path, strlen($root) + 1);
            }
        }
    };
    $walk($root);
    usort($files, strcmp(...));

    return $files;
}

function relativePath(string $fromDir, string $toFile): string
{
    $from = explode('/', trim($fromDir, '/'));
    $to = explode('/', trim($toFile, '/'));
    while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
        array_shift($from);
        array_shift($to);
    }

    return implode('/', [...array_fill(0, count($from), '..'), ...$to]);
}

function absolute(string $path): string
{
    $cwd = getcwd();
    if ($cwd === false) {
        fail('cannot read the current directory');
    }

    return str_starts_with($path, '/') ? rtrim($path, '/') : $cwd . '/' . rtrim($path, '/');
}

$options = [
    'backend' => 'backend',
    'build-dir' => 'build/lambda',
    'tfvars' => 'infra/terraform/envs/dev/package.auto.tfvars.json',
];
$arguments = isset($argv) ? array_slice($argv, 1) : [];
while ($arguments !== []) {
    $argument = array_shift($arguments);
    $name = ltrim($argument, '-');
    if (!str_starts_with($argument, '--') || !array_key_exists($name, $options) || $arguments === []) {
        fail("bad usage: {$argument} (see the header of scripts/build-lambda-zip.php)");
    }
    $options[$name] = array_shift($arguments);
}

$backend = absolute($options['backend']);
$buildDir = absolute($options['build-dir']);
$tfvars = absolute($options['tfvars']);
if (!is_dir($backend)) {
    fail("backend directory not found: {$options['backend']}");
}
foreach (COPIED as $item) {
    if (!file_exists($backend . '/' . $item)) {
        fail("{$options['backend']}/{$item} is missing");
    }
}

$source = $buildDir . '/src';
$zip = $buildDir . '/backend.zip';
removeTree($source);
removeTree($zip);
foreach (COPIED as $item) {
    copyTree($backend . '/' . $item, $source . '/' . $item);
}

// A fixed autoloader class suffix (only in the copy): without it Composer may pick a random one, which would change
// vendor/autoload.php, and with it the sha256, on every build. Not a relevant key of the lock's content-hash.
run(['composer', 'config', 'autoloader-suffix', 'LambdaPackage', '--working-dir=' . $source], $source);
run(['composer', 'install', '--no-dev', '--classmap-authoritative', '--no-scripts', '--no-progress', '--prefer-dist', '--working-dir=' . $source], $source);

$files = listFiles($source);
foreach ($files as $file) {
    $path = $source . '/' . $file;
    chmod($path, (fileperms($path) & 0100) !== 0 ? 0755 : 0644);
    touch($path, FIXED_MTIME);
}
run(['zip', '-X', '-D', '-q', '-@', $zip], $source, implode("\n", $files) . "\n");

$sha256 = hash_file('sha256', $zip);
if ($sha256 === false) {
    fail("cannot hash {$zip}");
}
$tfvarsDir = dirname($tfvars);
if (!is_dir($tfvarsDir) && !mkdir($tfvarsDir, 0755, true)) {
    fail("cannot create {$tfvarsDir}");
}
$json = json_encode(
    ['lambda_package_path' => relativePath($tfvarsDir, $zip), 'lambda_package_sha256' => $sha256],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
) . "\n";
if (file_put_contents($tfvars, $json) === false) {
    fail("cannot write {$tfvars}");
}
printf("build-lambda-zip: %s (%d bytes, sha256 %s)\n", $zip, filesize($zip), $sha256);
