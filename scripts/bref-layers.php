<?php

declare(strict_types=1);

/**
 * Derives the Bref layer versions Terraform needs from the two layers.json files that Composer installs
 * (Phase 7, D10), and writes them as a Terraform auto-loaded variables file:
 *
 *   {"bref_gd_layer_versions": {region: n}, "bref_php_layer_versions": {region: n}}
 *
 * Usage: php scripts/bref-layers.php [--php-layers FILE] [--gd-layers FILE] [--out FILE] [--check]
 *   --php-layers  default backend/vendor/bref/bref/layers.json (key php-85)
 *   --gd-layers   default backend/vendor/bref/extra-php-extensions/layers.json (key gd-php-85)
 *   --out         default infra/terraform/envs/dev/bref-layers.auto.tfvars.json
 *   --check       write nothing; exit 1 when --out is missing or differs from what would be written (drift)
 *
 * Fails (exit 1, message on stderr) on: a missing or unreadable file, invalid JSON, a missing key, a region that
 * is in one file but not the other, or a version that is not a positive integer. Output: keys sorted, four-space
 * indent, trailing newline, so the same input always gives the same bytes. Paths are relative to the repository.
 */

const PHP_KEY = 'php-85';
const GD_KEY = 'gd-php-85';

function fail(string $message): never
{
    fwrite(STDERR, 'bref-layers: ' . $message . "\n");
    exit(1);
}

/**
 * @return array<string, int> region => version, for one layer key of one layers.json file
 */
function readVersions(string $file, string $key): array
{
    $json = is_file($file) ? file_get_contents($file) : false;
    if ($json === false) {
        fail("cannot read {$file}");
    }
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail("{$file} is not valid JSON: " . $exception->getMessage());
    }
    if (!is_array($data) || !isset($data[$key]) || !is_array($data[$key]) || $data[$key] === []) {
        fail("{$file} has no \"{$key}\" key with regions");
    }
    if (array_is_list($data[$key])) {
        fail("{$file}: \"{$key}\" must map region names to versions, not be a list");
    }
    $versions = [];
    foreach ($data[$key] as $region => $version) {
        if (preg_match('/^[a-z]{2}(-[a-z]+)+-[0-9]$/', (string) $region) !== 1) {
            fail("{$file}: \"{$region}\" in {$key} is not an AWS region name");
        }
        if (!is_int($version) || $version < 1) {
            fail("{$file}: version of {$key} in region {$region} is not a positive integer");
        }
        $versions[(string) $region] = $version;
    }
    ksort($versions, SORT_STRING);

    return $versions;
}

/**
 * @param array<string, int> $php
 * @param array<string, int> $gd
 */
function requireSameRegions(array $php, array $gd, string $phpFile, string $gdFile): void
{
    foreach (array_diff_key($php, $gd) as $region => $_) {
        fail("region {$region} is in {$phpFile} (" . PHP_KEY . ") but not in {$gdFile} (" . GD_KEY . ')');
    }
    foreach (array_diff_key($gd, $php) as $region => $_) {
        fail("region {$region} is in {$gdFile} (" . GD_KEY . ") but not in {$phpFile} (" . PHP_KEY . ')');
    }
}

$root = dirname(__DIR__);
$options = [
    'php-layers' => $root . '/backend/vendor/bref/bref/layers.json',
    'gd-layers' => $root . '/backend/vendor/bref/extra-php-extensions/layers.json',
    'out' => $root . '/infra/terraform/envs/dev/bref-layers.auto.tfvars.json',
];
$check = false;
$arguments = isset($argv) ? array_slice($argv, 1) : [];
while ($arguments !== []) {
    $argument = array_shift($arguments);
    if ($argument === '--check') {
        $check = true;
        continue;
    }
    $name = ltrim($argument, '-');
    if (!str_starts_with($argument, '--') || !array_key_exists($name, $options) || $arguments === []) {
        fail("bad usage: {$argument} (see the header of scripts/bref-layers.php)");
    }
    $options[$name] = array_shift($arguments);
}

$php = readVersions($options['php-layers'], PHP_KEY);
$gd = readVersions($options['gd-layers'], GD_KEY);
requireSameRegions($php, $gd, $options['php-layers'], $options['gd-layers']);

$content = json_encode(
    ['bref_gd_layer_versions' => $gd, 'bref_php_layer_versions' => $php],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
) . "\n";

if ($check) {
    $current = is_file($options['out']) ? file_get_contents($options['out']) : false;
    if ($current !== $content) {
        fail("drift: {$options['out']} is missing or differs from the installed layers.json files; run make tf-layers");
    }
    echo "bref-layers: {$options['out']} is up to date\n";
    exit(0);
}

if (file_put_contents($options['out'], $content) === false) {
    fail("cannot write {$options['out']}");
}
echo "bref-layers: wrote {$options['out']}\n";
