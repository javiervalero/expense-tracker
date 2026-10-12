<?php

declare(strict_types=1);

namespace LocalAws\Relay;

/**
 * Where the relay puts an S3 event: one message per call.
 */
interface MessageSender
{
    /**
     * @throws MessageSendFailed when the message was not accepted by the queue
     */
    public function send(string $body): void;
}
