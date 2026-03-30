<?php

namespace Tests\Unit;

use App\Support\SubscriptionBundleMeta;
use Tests\TestCase;

class SubscriptionBundleMetaTest extends TestCase
{
    public function test_from_file_path_parses_peer_and_server_id(): void
    {
        $meta = SubscriptionBundleMeta::fromFilePath('files/31_samepeer_3_27_03_2026_10_00.zip');

        $this->assertNotNull($meta);
        $this->assertSame('31_samepeer_3_27_03_2026_10_00.zip', $meta->basename());
        $this->assertSame('samepeer', $meta->peerName());
        $this->assertSame(3, $meta->serverId());
        $this->assertSame('samepeer_3', $meta->folderName());
    }

    public function test_from_file_path_returns_null_for_invalid_format(): void
    {
        $this->assertNull(SubscriptionBundleMeta::fromFilePath('files/broken.zip'));
        $this->assertNull(SubscriptionBundleMeta::fromFilePath(''));
        $this->assertNull(SubscriptionBundleMeta::fromFilePath(null));
    }
}
