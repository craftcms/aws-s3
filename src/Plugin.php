<?php

namespace CraftCms\AwsS3;

use CraftCms\AwsS3\Filesystems\S3;
use CraftCms\Cms\Asset\Events\BeforeReplaceAsset;
use CraftCms\Cms\Element\Events\AfterSaveElement;
use CraftCms\Cms\Filesystem\Events\RegisterFilesystemTypes;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use Illuminate\Support\Facades\Event;

class Plugin extends BasePlugin
{
    public array $events = [
        // We listen to the “before” event to capture the new + old paths for comparison:
        BeforeReplaceAsset::class => Listeners\PurgeAfterReplaceListener::class,
        AfterSaveElement::class => Listeners\DetectFocalPointListener::class,
    ];

    public function bootPlugin(): void
    {
        Event::listen(fn (RegisterFilesystemTypes $event) => $event->types->push(S3::class));
    }
}
