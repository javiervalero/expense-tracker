<?php

declare(strict_types=1);

namespace App\Receipts;

/** The receipt limits, all owner decisions (plan section 10). Sizes in bytes, pixels and pixels per side; times in seconds. */
final class ReceiptLimits
{
    private function __construct() {}

    public const int MAX_BYTES = 10_485_760;
    public const int MAX_PIXELS = 40_000_000;
    public const int MAX_SIDE = 12_000;

    /** How long an upload policy is valid. */
    public const int POLICY_TTL_SECONDS = 300;

    /** How long a signed view URL is valid. */
    public const int VIEW_URL_TTL_SECONDS = 300;

    /** A pending record that the worker never settled disappears after this long. */
    public const int PENDING_TTL_SECONDS = 86_400;

    /** A failed record stays this long, so the user can see why. */
    public const int FAILED_TTL_SECONDS = 604_800;
}
