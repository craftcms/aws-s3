<?php

declare(strict_types=1);

namespace CraftCms\AwsS3\Listeners;

use CraftCms\AwsS3\Jobs\DetectFocalPoint;
use CraftCms\AwsS3\Plugin;
use CraftCms\AwsS3\Settings;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Cms;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Support\Str;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Log;

class QueueFocalPointDetection
{
    /** Rekognition only reads JPEG and PNG images. */
    private const array EXTENSIONS = ['jpg', 'jpeg', 'png'];

    public function handle(ElementSaved $event): void
    {
        $asset = $event->element;

        if (
            !$event->isNew ||
            !$asset instanceof Asset ||
            !in_array(Str::lower($asset->getExtension()), self::EXTENSIONS, true) ||
            $asset->getHasFocalPoint()
        ) {
            return;
        }

        $volume = $asset->getVolume();

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if ($volume->isTemporary() || !$settings->detectsFacesIn($volume)) {
            return;
        }

        $disk = $volume->sourceDisk();

        if (!$disk instanceof AwsS3V3Adapter) {
            return;
        }

        if ($settings->rekognitionConfig($disk) === null) {
            Log::warning(sprintf('Skipped detecting faces in asset %d, because volume “%s” is on an S3-compatible service. Set a region for Amazon Rekognition in the Amazon S3 plugin’s settings.', $asset->id, $volume->handle));

            return;
        }

        dispatch(new DetectFocalPoint($asset->id))
            ->onQueue(Cms::config()->lowPriorityQueueName)
            ->afterCommit();
    }
}
