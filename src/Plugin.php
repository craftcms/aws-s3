<?php

declare(strict_types=1);

namespace CraftCms\AwsS3;

use CraftCms\AwsS3\Listeners\QueueCloudFrontInvalidation;
use CraftCms\AwsS3\Listeners\QueueFocalPointDetection;
use CraftCms\Cms\Asset\Data\Volume;
use CraftCms\Cms\Asset\Events\AssetFileHandling;
use CraftCms\Cms\Cp\SelectOptions;
use CraftCms\Cms\Element\Events\ElementDeleting;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Support\Facades\Volumes;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Override;

use function CraftCms\Cms\t;

/**
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** Higher than 2.x’s, so project config from 2.x installs isn’t mistaken for a newer schema. */
    public string $schemaVersion = '3.0.0';

    public bool $hasCpSettings = true;

    protected array $events = [
        ElementSaved::class => QueueFocalPointDetection::class,
        AssetFileHandling::class => QueueCloudFrontInvalidation::class,
        ElementDeleting::class => QueueCloudFrontInvalidation::class,
    ];

    public function boot(): void
    {
        $this->app->singleton(Settings::class, fn () => $this->getSettings());
    }

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        $volumeOptions = Volumes::getAllVolumes()
            ->map(fn (Volume $volume): array => [
                'label' => (string) $volume->name,
                'value' => (string) $volume->handle,
            ])
            ->values()
            ->all();

        $envTip = t('Type `$` to choose an environment variable.');
        $envTriggers = SelectOptions::getEnvTextExpanderTriggers();

        return Ui::make([
            Field::make(t('Detect faces in these volumes', category: 'aws-s3'))
                ->instructions(t('New JPEG and PNG images get their focal point set to the first face that [Amazon Rekognition]({url}) finds in them. Only volumes on a Laravel disk that uses the `s3` driver are checked, and images that already have a focal point are skipped.', ['url' => 'https://aws.amazon.com/rekognition/'], 'aws-s3'))
                ->warning(t('Amazon charges for each image Rekognition analyzes.', category: 'aws-s3'))
                ->control(Choice::make('volumes')
                    ->multiple()
                    ->options($volumeOptions)
                    ->allOption()),
            Heading::make('credentials-heading', t('AWS Credentials', category: 'aws-s3'))
                ->description(t('Amazon Rekognition and CloudFront are called with these. Leave them blank to use each disk’s own credentials, which need the `rekognition:DetectFaces` and `cloudfront:CreateInvalidation` permissions. Disks on S3-compatible services, such as Cloudflare R2 or MinIO, use these or the AWS SDK’s default credentials, such as `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY`.', category: 'aws-s3')),
            Field::make(t('Access Key ID', category: 'aws-s3'))
                ->control(Text::make('keyId')->textExpanderTriggers($envTriggers))
                ->tip($envTip),
            Field::make(t('Secret Access Key', category: 'aws-s3'))
                ->control(Text::make('secret')->textExpanderTriggers($envTriggers))
                ->tip($envTip),
            Field::make(t('Rekognition Region', category: 'aws-s3'))
                ->instructions(t('Leave this blank to use each disk’s region. Disks on S3-compatible services need one. Rekognition can read images straight from buckets in its own region; images elsewhere are sent to it, up to 5 MB.', category: 'aws-s3'))
                ->control(Text::make('region')->textExpanderTriggers($envTriggers)->placeholder('us-east-1'))
                ->tip($envTip),
        ]);
    }
}
