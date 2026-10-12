<?php

declare(strict_types=1);

namespace LocalAws\Bootstrap;

use LocalAws\Logging\JsonLogger;

/**
 * Retries a probe until it stops throwing, or gives up after a time limit. The clock and the pause are injected
 * so that the tests need neither real time nor real sleeping.
 */
final readonly class ServiceWaiter
{
    private const PAUSE_SECONDS = 0.5;

    /**
     * @param \Closure(): float $now seconds, any origin (only differences matter)
     * @param \Closure(float): void $sleep pauses for the given number of seconds
     */
    public function __construct(
        private JsonLogger $logger,
        private \Closure $now,
        private \Closure $sleep,
    ) {}

    public static function withSystemClock(JsonLogger $logger): self
    {
        return new self(
            $logger,
            static fn(): float => microtime(true),
            static function (float $seconds): void {
                usleep((int) round($seconds * 1_000_000));
            },
        );
    }

    /**
     * @param \Closure(): void $probe throws while the service is not ready
     *
     * @throws ServiceNotReady when the probe still fails after $timeoutSeconds
     */
    public function waitFor(string $service, \Closure $probe, int $timeoutSeconds): void
    {
        $deadline = ($this->now)() + $timeoutSeconds;
        $announced = false;

        while (true) {
            try {
                $probe();
                $this->logger->info('service ready', ['service' => $service]);

                return;
            } catch (\Throwable $failure) {
                if (!$announced) {
                    $this->logger->info('waiting for service', ['service' => $service, 'reason' => $failure->getMessage()]);
                    $announced = true;
                }
                if (($this->now)() >= $deadline) {
                    throw new ServiceNotReady(
                        sprintf('%s was not ready after %d seconds: %s', $service, $timeoutSeconds, $failure->getMessage()),
                        0,
                        $failure,
                    );
                }
            }
            ($this->sleep)(self::PAUSE_SECONDS);
        }
    }
}
