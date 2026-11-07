<?php

declare(strict_types=1);

namespace CraftCms\AwsS3;

use CraftCms\AwsS3\Listeners\QueueFocalPointDetection;
use CraftCms\Cms\Asset\Data\Volume;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Support\Facades\Volumes;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Override;

use function CraftCms\Cms\t;

class Plugin extends BasePlugin
{
    public bool $hasCpSettings = true;

    protected array $events = [
        ElementSaved::class => QueueFocalPointDetection::class,
    ];

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings();
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext()): Ui
    {
        $volumeOptions = Volumes::getAllVolumes()
            ->map(fn(Volume $volume): array => [
                'label' => (string) $volume->name,
                'value' => (string) $volume->handle,
            ])
            ->values()
            ->all();

        return Ui::make([
            Field::make(t('Detect faces in these volumes', category: 'aws-s3'))
                ->instructions(t('New JPEG and PNG images get their focal point set to the first face that [Amazon Rekognition]({url}) finds in them. Only volumes on a Laravel disk that uses the `s3` driver are checked, and images that already have a focal point are skipped.', ['url' => 'https://aws.amazon.com/rekognition/'], 'aws-s3'))
                ->warning(t('The disk’s credentials need the `rekognition:DetectFaces` permission. Amazon charges for each image Rekognition analyzes.', category: 'aws-s3'))
                ->control(Choice::make('volumes')
                    ->multiple()
                    ->options($volumeOptions)
                    ->allOption()),
        ]);
    }
}
