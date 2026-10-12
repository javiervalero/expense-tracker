<?php

declare(strict_types=1);

namespace LocalAws\Runner;

/**
 * "Please stop": set from the SIGTERM/SIGINT handler (bin/runner.php), read by the {@see Runner} between batches.
 *
 * A stop must finish the batch in hand, but it must not wait for a long poll to time out (up to 20 s, longer than
 * Docker's 10 s grace period): while the runner only waits for messages, `request()` tells the signal handler that
 * the process may exit at once. Not final only so that a test can inject a stop at an exact moment. This is deliberately a small piece of mutable state, kept apart from the logic.
 */
class StopSignal
{
    private bool $requested = false;
    private bool $waiting = false;

    /**
     * @return bool true when the runner is only waiting for messages, so the caller may exit immediately
     */
    public function request(): bool
    {
        $this->requested = true;

        return $this->waiting;
    }

    public function isRequested(): bool
    {
        return $this->requested;
    }

    public function setWaiting(bool $waiting): void
    {
        $this->waiting = $waiting;
    }

    public function isWaiting(): bool
    {
        return $this->waiting;
    }
}
