<?php

namespace CraftCms\AwsS3;

use CraftCms\AwsS3\Filesystems\S3;
use CraftCms\Cms\Asset\Events\AssetReplacing;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;

class Plugin extends BasePlugin
{
    public array $events = [
        // We listen to the “before” event to capture the new + old paths for comparison:
        AssetReplacing::class => Listeners\PurgeAfterReplaceListener::class,
        ElementSaved::class => Listeners\DetectFocalPointListener::class,
    ];

    protected array $filesystemTypes = [
        S3::class,
    ];
}
