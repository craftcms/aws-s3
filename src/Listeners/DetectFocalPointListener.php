<?php

namespace CraftCms\AwsS3\Listeners;

use CraftCms\AwsS3\Exceptions\FaceDetectionException;
use CraftCms\AwsS3\Filesystems\S3;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Models\Asset as AssetModel;
use CraftCms\Cms\Element\Events\AfterSaveElement;
use Illuminate\Support\Facades\Log;

class DetectFocalPointListener
{
    public function handle(AfterSaveElement $event): void
    {
        // Ignore non-asset element saves:
        if (! $event->element instanceof Asset) {
            return;
        }

        /** @var Asset $asset */
        $asset = $event->element;
        $volume = $asset->getVolume();
        $filesystem = $volume->getFs();

        // Ignore assets on irrelevant filesystems:
        if (! $filesystem instanceof S3 || ! $filesystem->autoFocalPoint) {
            return;
        }

        try {
            [$x, $y] = $filesystem->detectFocalPoint($asset);
        } catch (FaceDetectionException $e) {
            Log::error($e->getMessage());

            return;
        }

        $assetModel = AssetModel::find($asset->id);
        $assetModel->focalPoint = implode(';', [
            min(max($x, 0), 1),
            min(max($y, 0), 1),
        ]);
        $assetModel->save();
    }
}
