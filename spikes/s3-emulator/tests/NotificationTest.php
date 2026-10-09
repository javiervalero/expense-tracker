<?php

declare(strict_types=1);

namespace Spike;

/** ADR 0007 criterion 2: S3-format ObjectCreated notifications, filtered by prefix. Config via env + SDK only. */
final class NotificationTest extends S3TestCase
{
    private const STATE_FILE = '/app/.state/outage-key';

    public function testBootstrapIsIdempotent(): void
    {
        bootstrap_s3($this->s3);
        bootstrap_s3($this->s3);

        $config = $this->s3->getBucketNotificationConfiguration(['Bucket' => BUCKET_RECEIPTS]);
        $rules = $config['QueueConfigurations'] ?? [];
        $this->record(__FUNCTION__, ['rules' => $rules]);
        $this->assertCount(1, $rules, 'running bootstrap twice must not duplicate the rule');
        $this->assertSame('receipts/', $rules[0]['Filter']['Key']['FilterRules'][0]['Value']);
        $this->assertSame([], $this->s3->getBucketNotificationConfiguration(['Bucket' => BUCKET_THUMBNAILS])['QueueConfigurations'] ?? []);
    }

    public function testExactlyOneWebhookPerPostUploadAndRecordShape(): void
    {
        $key = $this->newKey();

        $result = $this->uploadValid($key);
        $captures = $this->waitForCaptures($key, 1);
        sleep(self::SETTLE_SECONDS);
        $captures = $this->capturesFor($key);

        $this->assertContains($result['status'], [200, 201, 204]);
        $this->assertCount(1, $captures, 'exactly one webhook per upload');
        $record = $captures[0]['body']['Records'][0];
        $this->record(__FUNCTION__, [
            'eventName' => $record['eventName'] ?? null,
            'eventSource' => $record['eventSource'] ?? null,
            'eventVersion' => $record['eventVersion'] ?? null,
            'recordCount' => count($captures[0]['body']['Records']),
            'topLevelKeys' => array_keys($captures[0]['body']),
        ]);
        $this->assertSame(1, count($captures[0]['body']['Records']));
        $this->assertStringStartsWith('s3:ObjectCreated:', $record['eventName']);
        $this->assertSame(BUCKET_RECEIPTS, $record['s3']['bucket']['name']);
        $this->assertSame($this->validBodySize(), $record['s3']['object']['size']);

        $fixtureDir = \env_or('FIXTURE_DIR', '');
        if ($fixtureDir !== '' && is_dir($fixtureDir)) {
            file_put_contents(
                $fixtureDir . '/silo-object-created-post.json',
                json_encode($captures[0]['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );
        }
    }

    private function validBodySize(): int
    {
        return strlen($this->validBody());
    }

    public function testAuthTokenHeaderIsSent(): void
    {
        $key = $this->newKey();

        $this->uploadValid($key);
        $captures = $this->waitForCaptures($key, 1);

        $this->assertNotEmpty($captures);
        $headers = array_change_key_case($captures[0]['headers'], CASE_LOWER);
        $this->record(__FUNCTION__, ['authorization' => $headers['authorization'] ?? null, 'headerNames' => array_keys($headers)]);
        $this->assertArrayHasKey('authorization', $headers);
        $this->assertStringContainsString(\env_or('WEBHOOK_AUTH_TOKEN', 'spikewebhooktoken'), $headers['authorization']);
    }

    public function testKeyInRecordIsUrlEncoded(): void
    {
        $key = 'receipts/user a/exp+1/receipt-' . bin2hex(random_bytes(3));

        $this->s3->putObject(['Bucket' => BUCKET_RECEIPTS, 'Key' => $key, 'Body' => $this->validBody()]);
        $captures = $this->waitForCaptures($key, 1);

        $this->assertNotEmpty($captures, 'webhook must arrive for the key with a space and a plus');
        $encoded = $captures[0]['body']['Records'][0]['s3']['object']['key'];
        $this->record(__FUNCTION__, ['original' => $key, 'encodedInRecord' => $encoded]);
        $this->assertStringNotContainsString(' ', $encoded);
        $this->assertStringContainsString('%2B', $encoded, 'a literal plus must be encoded as %2B (space is +)');
        $this->assertSame($key, urldecode($encoded), 'urldecode of the record key must give the original key');
    }

    public function testObjectOutsidePrefixSendsNoWebhook(): void
    {
        $key = $this->newKey('other');

        $this->s3->putObject(['Bucket' => BUCKET_RECEIPTS, 'Key' => $key, 'Body' => $this->validBody()]);

        $this->assertNoWebhookFor($key);
    }

    public function testThumbnailsBucketSendsNoWebhook(): void
    {
        $key = $this->newKey();

        $this->s3->putObject(['Bucket' => BUCKET_THUMBNAILS, 'Key' => $key, 'Body' => $this->validBody()]);

        $this->assertNoWebhookFor($key, BUCKET_THUMBNAILS);
    }

    public function testDeleteSendsNoWebhook(): void
    {
        $key = $this->newKey();
        $this->uploadValid($key);
        $this->assertCount(1, $this->waitForCaptures($key, 1));
        sleep(self::SETTLE_SECONDS);

        $this->s3->deleteObject(['Bucket' => BUCKET_RECEIPTS, 'Key' => $key]);
        $sentinel = $this->newKey();
        $this->s3->putObject(['Bucket' => BUCKET_RECEIPTS, 'Key' => $sentinel, 'Body' => $this->validBody()]);
        $this->waitForCaptures($sentinel, 1);
        sleep(self::SETTLE_SECONDS);

        $this->assertCount(1, $this->capturesFor($key), 'only the creation event, nothing for the delete');
    }

    public function testOverwriteSendsOneWebhookPerUpload(): void
    {
        $key = $this->newKey();

        $this->uploadValid($key);
        $this->uploadValid($key);
        $captures = $this->waitForCaptures($key, 2);
        sleep(self::SETTLE_SECONDS);
        $captures = $this->capturesFor($key);

        $this->record(__FUNCTION__, [
            'webhooks' => count($captures),
            'eventNames' => array_map(static fn (array $c): string => $c['body']['Records'][0]['eventName'], $captures),
            'etags' => array_map(static fn (array $c): ?string => $c['body']['Records'][0]['s3']['object']['eTag'] ?? null, $captures),
        ]);
        $this->assertCount(2, $captures, 'AWS sends one event per successful write, including overwrites');
    }

    /** Phase 1 of the outage scenario: run.sh stops the receiver before this test. */
    public function testOutagePhase1UploadWhileReceiverIsDown(): void
    {
        if (getenv('OUTAGE_PHASE') !== '1') {
            $this->markTestSkipped('only run by run.sh in the outage phase');
        }
        $key = $this->newKey();
        $unreachable = @file_get_contents($this->captureUrl() . '/health');
        $this->assertFalse($unreachable, 'precondition: receiver must be down');

        $result = $this->uploadValid($key);

        $this->record(__FUNCTION__, ['status' => $result['status'], 'code' => $result['code']]);
        $this->assertContains($result['status'], [200, 201, 204], 'upload must still succeed while the receiver is down');
        @mkdir(dirname(self::STATE_FILE), 0777, true);
        file_put_contents(self::STATE_FILE, $key);
    }

    /** Phase 2: run.sh restarted the receiver; the queued event must be replayed. */
    public function testOutagePhase2EventIsReplayedWhenReceiverReturns(): void
    {
        if (getenv('OUTAGE_PHASE') !== '2') {
            $this->markTestSkipped('only run by run.sh in the outage phase');
        }
        $key = trim((string) file_get_contents(self::STATE_FILE));
        $started = microtime(true);

        $captures = $this->waitForCaptures($key, 1, 90);
        sleep(self::SETTLE_SECONDS);
        $captures = $this->capturesFor($key);

        $this->record(__FUNCTION__, ['webhooks' => count($captures), 'secondsToReplay' => round(microtime(true) - $started, 1)]);
        $this->assertCount(1, $captures, 'queue_dir must replay the missed event exactly once');
    }
}
