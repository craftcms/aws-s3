<?php

namespace CraftCms\AwsS3;

use CraftCms\AwsS3\Filesystems\S3;
use CraftCms\Cms\Asset\Events\AssetReplacing;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Filesystem\Events\FilesystemTypesResolving;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use Illuminate\Support\Facades\Event;

class Plugin extends BasePlugin
{
    protected array $scripts = [
        __DIR__.'/../resources/js/edit-fs.js' => 'js/edit-fs.js',
    ];

    public array $events = [
        // We listen to the “before” event to capture the new + old paths for comparison:
        AssetReplacing::class => Listeners\PurgeAfterReplaceListener::class,
        ElementSaved::class => Listeners\DetectFocalPointListener::class,
    ];

    public function bootPlugin(): void
    {
        Event::listen(fn (FilesystemTypesResolving $event) => $event->types->push(S3::class));
    }
}
