<?php

namespace CraftCms\AwsS3\Listeners;

use CraftCms\AwsS3\Filesystems\S3;
use CraftCms\Cms\Asset\Events\BeforeReplaceAsset;

class PurgeAfterReplaceListener
{
    public function handle(BeforeReplaceAsset $event): void
    {
        $asset = $event->asset;
        $filesystem = $asset->getVolume()->getFs();

        if (! $filesystem instanceof S3) {
            return;
        }

        $oldFilename = $asset->getFilename();
        $newFilename = $event->filename;

        // When replacing an asset wouldn’t change the filename, invalidate the CDN path for the original file, too:
        if ($oldFilename === $newFilename) {
            $filesystem->invalidateCdnPath($asset->getPath());
        }
    }
}
