<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Relay;

use LocalAws\Relay\MessageSendFailed;
use LocalAws\Relay\MessageSender;

/**
 * Test double: remembers every body it was asked to send, and can be told to fail.
 */
final class RecordingMessageSender implements MessageSender
{
    /** @var list<string> */
    public array $sent = [];

    public function __construct(private readonly bool $fails = false) {}

    public function send(string $body): void
    {
        $this->sent[] = $body;
        if ($this->fails) {
            throw new MessageSendFailed('queue down');
        }
    }
}
