<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\ReceiptStorageWrite\Clean;

/**
 * Prose may say $client->putObject() or upload() without calling them, and the thumbnails port may be written to.
 */
final class Clean
{
    public function run(object $thumbnails, object $originals, string $key, string $jpeg): string
    {
        $text = 'putObject and PutObject in an ordinary string are fine; so are the words upload and copy';
        // $client->putObject([]) in a comment is fine too.
        $this->thumbnails->put($key, $jpeg);
        $thumbnails->put($key, $jpeg);
        $originals->delete($key);

        return $text . $originals->get($key) . $this->put;
    }

    public function put(string $key): string
    {
        return $key;
    }
}
