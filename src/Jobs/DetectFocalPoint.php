<?php

declare(strict_types=1);

namespace CraftCms\AwsS3\Jobs;

use Aws\Rekognition\RekognitionClient;
use CraftCms\AwsS3\Settings;
use CraftCms\Cms\Asset\AssetTransformers;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Elements;
use CraftCms\Cms\Queue\Job;
use CraftCms\Cms\Support\Arr;
use CraftCms\Cms\Support\Facades\I18N;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Log;
use Override;

/**
 * Sets an image’s focal point to the first face Amazon Rekognition finds in it.
 */
class DetectFocalPoint extends Job implements ShouldBeUnique
{
    /** Faces that Rekognition is less confident about than this percentage are ignored. */
    public const int MIN_CONFIDENCE = 80;

    /** The largest image Rekognition accepts in a request, rather than reading it from S3. */
    public const int MAX_BYTES = 5 * 1024 * 1024;

    /** A failed request, such as one denied for a missing permission, would fail the same way again. */
    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(
        public int $assetId,
    ) {
        parent::__construct();
    }

    public function handle(Elements $elements, AssetTransformers $assetTransformers, Settings $settings): void
    {
        $asset = Asset::find()->id($this->assetId)->status(null)->one();

        if (! $asset instanceof Asset || $asset->getHasFocalPoint()) {
            return;
        }

        $disk = $asset->getVolume()->sourceDisk();

        if (! $disk instanceof AwsS3V3Adapter) {
            return;
        }

        $config = $settings->rekognitionConfig($disk);

        if ($config === null) {
            return;
        }

        $path = $asset->getPath();

        if ($settings->readsFromBucket($disk)) {
            $image = ['S3Object' => [
                'Bucket' => $disk->getConfig()['bucket'],
                'Name' => $disk->path($path),
            ]];
        } elseif ($disk->size($path) <= self::MAX_BYTES) {
            $image = ['Bytes' => $disk->get($path)];
        } else {
            Log::info(sprintf('Skipped detecting faces in asset %d, which is too large to send to Amazon Rekognition.', $asset->id));

            return;
        }

        /** @var RekognitionClient $rekognition */
        $rekognition = app(RekognitionClient::class, ['args' => $config]);
        $result = $rekognition->detectFaces(['Image' => $image]);

        $faces = $result->get('FaceDetails');
        $face = is_array($faces)
            ? Arr::first($faces, fn (array $face): bool => $face['Confidence'] >= self::MIN_CONFIDENCE)
            : null;

        if ($face === null) {
            return;
        }

        $box = $face['BoundingBox'];
        $asset->setFocalPoint([
            'x' => max(0, min(1, $box['Left'] + $box['Width'] / 2)),
            'y' => max(0, min(1, $box['Top'] + $box['Height'] / 2)),
        ]);

        $elements->saveElement($asset);

        // Transforms are cropped around the focal point
        $assetTransformers->invalidate($asset);
    }

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    #[Override]
    protected function defaultDescription(): string
    {
        return I18N::prep('Detecting faces', category: 'aws-s3');
    }
}
