<?php

declare(strict_types=1);

namespace CraftCms\AwsS3\Listeners;

use CraftCms\AwsS3\CloudFront\CloudFrontInvalidations;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Events\AssetFileHandling;
use CraftCms\Cms\Element\Events\ElementDeleting;
use Illuminate\Filesystem\AwsS3V3Adapter;

/**
 * Invalidates an asset’s path in CloudFront when its file is replaced, moved, renamed, or deleted.
 */
class QueueCloudFrontInvalidation
{
    public function __construct(
        private readonly CloudFrontInvalidations $invalidations,
    ) {
    }

    public function handle(AssetFileHandling|ElementDeleting $event): void
    {
        $asset = $event instanceof AssetFileHandling ? $event->asset : $event->element;

        if (!$asset instanceof Asset) {
            return;
        }

        // Nothing is cached for a new file yet. Files kept on deletion stay where they are, and the files of trashed
        // assets were already moved or deleted when the assets were trashed.
        $unchanged = $event instanceof AssetFileHandling
            ? $event->isNew
            : $asset->keepFileOnDelete || $asset->dateDeleted !== null;

        if ($unchanged) {
            return;
        }

        // This is called before the file changes, so the asset is still in its old place
        $disk = $asset->getVolume()->sourceDisk();

        if ($disk instanceof AwsS3V3Adapter) {
            $this->invalidations->add($disk, $asset->getPath());
        }
    }
}
