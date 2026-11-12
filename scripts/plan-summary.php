<?php

declare(strict_types=1);

/**
 * Summarises `terraform show -json <planfile>` read from stdin: counts per action and one line per (action, type).
 *
 * Usage: terraform show -json dev.tfplan | php plan-summary.php NORMAL|DESTROY
 * Exit codes: 0 = summary printed, 1 = bad input or bad argument.
 *
 * The plan holds generated secrets (before/after values). This script reads ONLY `format_version`, `terraform_version`,
 * whether `planned_values` exists, `resource_changes`, and for each change `mode`, `type`, `change.actions` and
 * whether `change.importing` is non-null. Nothing from the input
 * is ever printed except a resource type that matches ^[a-z][a-z0-9_]*$. Every failure prints one fixed message:
 * never input, never a JsonException or PHP notice text.
 */

const FAILURE_MESSAGE = "plan-summary: input is not a usable 'terraform show -json' plan (empty, truncated or not a plan)\n";
const USAGE_MESSAGE = "usage: plan-summary.php NORMAL|DESTROY (plan JSON on stdin)\n";
const JSON_DEPTH = 512;
const TYPE_PATTERN = '/^[a-z][a-z0-9_]*$/D';
const UNRECOGNISED_TYPE = '(unrecognised type)';
const COUNT_ORDER = ['create', 'update', 'delete', 'replace', 'other', 'no-op', 'read', 'forget', 'import'];
const WARNING_ACTIONS = ['delete', 'replace', 'other'];

/**
 * @param array<mixed> $actions
 */
function classify_actions(array $actions): string
{
    if (!array_is_list($actions)) {
        return 'other';
    }
    foreach ($actions as $action) {
        if (!is_string($action)) {
            return 'other';
        }
    }
    $sorted = $actions;
    sort($sorted);

    return match (true) {
        $actions === ['create'] => 'create',
        $actions === ['update'] => 'update',
        $actions === ['delete'] => 'delete',
        $sorted === ['create', 'delete'] => 'replace',
        $actions === ['no-op'] => 'no-op',
        $actions === ['read'] => 'read',
        $actions === ['forget'] => 'forget',
        default => 'other',
    };
}

function display_type(mixed $mode, mixed $type): string
{
    if (!is_string($type) || preg_match(TYPE_PATTERN, $type) !== 1) {
        return UNRECOGNISED_TYPE;
    }
    if ($mode === 'managed') {
        return $type;
    }

    return $mode === 'data' ? 'data.' . $type : UNRECOGNISED_TYPE;
}

/**
 * @return array{counts: array<string, int>, lines: array<string, int>, warned: array<string, true>}
 */
function summarise(mixed $plan): array
{
    // `terraform show -json` of a state file has format_version and terraform_version too, but never planned_values.
    if (
        !is_array($plan)
        || !array_key_exists('format_version', $plan)
        || !is_string($plan['terraform_version'] ?? null)
        || !array_key_exists('planned_values', $plan)
    ) {
        throw new RuntimeException('not a plan');
    }
    // Terraform omits resource_changes when nothing changes; present, it must be a list (not null, not an object).
    $changes = array_key_exists('resource_changes', $plan) ? $plan['resource_changes'] : [];
    if (!is_array($changes) || !array_is_list($changes)) {
        throw new RuntimeException('not a plan');
    }

    $counts = array_fill_keys(COUNT_ORDER, 0);
    $lines = [];
    $warned = [];
    foreach ($changes as $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException('not a plan');
        }
        $change = is_array($entry['change'] ?? null) ? $entry['change'] : [];
        $actions = $change['actions'] ?? null;
        $action = is_array($actions) ? classify_actions($actions) : 'other';
        $type = display_type($entry['mode'] ?? null, $entry['type'] ?? null);

        ++$counts[$action];
        if (($change['importing'] ?? null) !== null) {
            ++$counts['import'];
        }
        if ($action !== 'no-op') {
            $key = $action . ' ' . $type;
            $lines[$key] = ($lines[$key] ?? 0) + 1;
        }
        if (in_array($action, WARNING_ACTIONS, true)) {
            $warned[$type] = true;
        }
    }
    ksort($lines, SORT_STRING);
    ksort($warned, SORT_STRING);

    return ['counts' => $counts, 'lines' => $lines, 'warned' => $warned];
}

/**
 * @param array{counts: array<string, int>, lines: array<string, int>, warned: array<string, true>} $summary
 */
function render(string $header, array $summary): string
{
    $parts = [];
    foreach (COUNT_ORDER as $name) {
        $parts[] = $name . ' ' . $summary['counts'][$name];
    }
    $out = 'plan summary: ' . $header . "\n";
    $out .= 'counts: ' . implode(', ', $parts) . "\n";
    foreach ($summary['lines'] as $key => $count) {
        $out .= $key . ' x' . $count . "\n";
    }
    if ($summary['warned'] === []) {
        return $out . "no delete, no replace\n";
    }

    return $out
        . "!!! WARNING: this plan deletes, replaces or has an unrecognised action for:\n"
        . '!!! ' . implode(', ', array_map('strval', array_keys($summary['warned']))) . "\n";
}

/**
 * @param array<mixed> $argv
 */
function main(array $argv): int
{
    ini_set('display_errors', '0');
    ini_set('memory_limit', '1G');
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    $header = $argv[1] ?? '';
    if (($header !== 'NORMAL' && $header !== 'DESTROY') || count($argv) !== 2) {
        fwrite(STDERR, USAGE_MESSAGE);

        return 1;
    }

    try {
        $input = stream_get_contents(STDIN);
        if (!is_string($input) || trim($input) === '') {
            throw new RuntimeException('empty');
        }
        $plan = json_decode($input, true, JSON_DEPTH, JSON_THROW_ON_ERROR);
        $output = render($header, summarise($plan));
    } catch (Throwable) {
        fwrite(STDERR, FAILURE_MESSAGE);

        return 1;
    }
    fwrite(STDOUT, $output);

    return 0;
}

$arguments = $_SERVER['argv'] ?? [];
exit(main(is_array($arguments) ? $arguments : []));
