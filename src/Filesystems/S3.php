<?php

namespace CraftCms\AwsS3\Filesystems;

use Aws\CloudFront\CloudFrontClient;
use Aws\CloudFront\Exception\CloudFrontException;
use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\Exception\CredentialsException;
use Aws\Rekognition\RekognitionClient;
use Aws\S3\S3Client;
use Aws\Sts\StsClient;
use CraftCms\AwsS3\Events\InvalidatingPaths;
use CraftCms\AwsS3\Exceptions\FaceDetectionException;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Cp\SelectOptions;
use CraftCms\Cms\Filesystem\Filesystems\Filesystem;
use CraftCms\Cms\Form\Controls\Combobox;
use CraftCms\Cms\Form\Controls\Lightswitch;
use CraftCms\Cms\Form\Controls\Text;
use CraftCms\Cms\Form\Form;
use CraftCms\Cms\Form\FormContext;
use CraftCms\Cms\Form\Nodes\Callout;
use CraftCms\Cms\Form\Nodes\Field;
use CraftCms\Cms\Form\Nodes\Heading;
use CraftCms\Cms\Form\Nodes\Separator;
use CraftCms\Cms\Support\Arr;
use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Support\Str;
use CraftCms\Cms\Validation\Rules\EnvValueRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Visibility;
use Override;
use function CraftCms\Cms\t;

class S3 extends Filesystem
{
    const int AWS_STS_CACHE_DURATION = 3600;
    const string AWS_STS_CACHE_KEY_PREFIX = 'aws-sts.';
    const string AWS_DEFAULT_REGION = 'us-east-1';
    const int AWS_REKOGNITION_MIN_CONFIDENCE = 80;

    public ?string $url = null;
    public ?string $keyId = null;
    public ?string $secret = null;
    public string $region = self::AWS_DEFAULT_REGION;
    public ?string $bucket = null;
    public ?string $expires = null;
    public ?string $subfolder = null;
    public bool $makeUploadsPublic = true;

    public ?string $cfDistributionId = null;
    public ?string $cfPrefix = null;

    public bool $autoFocalPoint = false;

    public bool $addSubfolderToRootUrl = true;
    protected array $pathsToInvalidate = [];

    public function __construct(object|array $config = [])
    {
        // The plugin may be initialized with legacy configuration values!
        // Bucket and region are now both “manually” set, with the help of suggested values;
        // we don’t need to track `manualBucket` or `manualRegion` separately, and `bucketSelectionMode`
        // was only used to preserve UI state in the plugin’s settings screen.
        if ($config['manualBucket'] ?? false) {
            if (isset($config['bucketSelectionMode']) && $config['bucketSelectionMode'] === 'manual') {
                $config['bucket'] = Arr::pull($config, 'manualBucket');
                $config['region'] = Arr::pull($config, 'manualRegion');
            }
        }

        unset($config['manualBucket'], $config['manualRegion'], $config['bucketSelectionMode']);

        parent::__construct($config);
    }

    #[Override]
    public static function displayName(): string
    {
        return t('AWS S3', category: 'aws-s3');
    }

    public function getDiskConfig(): array
    {
        $options = [
            // This is the S3 default for all objects, but explicitly sending the header allows for bucket policies that require it.
            // @see https://github.com/craftcms/aws-s3/pull/172
            'ServerSideEncryption' => 'AES256',
        ];

        if (! empty($this->expires)) {
            try {
                $duration = \DateInterval::createFromDateString($this->expires);
                $now = new \DateTimeImmutable;
                $expires = $now->add($duration);

                $options['CacheControl'] = sprintf('max-age=%d', $expires->getTimestamp() - $now->getTimestamp());
            } catch (\DateMalformedIntervalStringException $e) {
                Log::warning(sprintf('Skipped setting `options.CacheControl` when configuring an AWS S3 disk due to an invalid duration string: %s', $this->expires));
            }
        }

        return $this->getClientConfig() + [
            'driver' => 's3',
            'bucket' => $this->bucket,
            'url' => $this->getRootUrl(),
            'root' => Env::parse($this->subfolder),
            'visibility' => $this->makeUploadsPublic ? Visibility::PUBLIC : Visibility::PRIVATE,
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
            'options' => $options,
        ];
    }

    public function getRules(): array
    {
        return parent::getRules() + [
            'url' => [
                new EnvValueRule(['url']),
            ],
            'expires' => [
                'string',
                function (string $attribute, mixed $value, \Closure $fail) {
                    try {
                        // All we need to do is make sure it compiles:
                        \DateInterval::createFromDateString($value);
                    } catch (\DateMalformedIntervalStringException $e) {
                        $fail(t('The cache duration must be a valid date interval string.'));
                    }
                },
            ],
        ];
    }

    public function settingsForm(FormContext $context = new FormContext): ?Form
    {
        $environmentTip = sprintf(
            '%s [%s](%s)',
            t('Type `$` to choose an environment variable.'),
            t('Learn more'),
            'https://craftcms.com/docs/5.x/configure.html#control-panel-settings',
        );
        $expansions = SelectOptions::getEnvTextExpanderTriggers();

        $settingsForm = Form::make();

        $settingsForm->add(Field::make(t('Base URL'))
            ->instructions(t('The base URL to the files in this filesystem. See the AWS documentation on [website endpoints]({url}) for more information. Leave blank if you don’t want Craft to generate URLs for assets on this filesystem.', ['url' => 'https://docs.aws.amazon.com/AmazonS3/latest/userguide/WebsiteEndpoints.html'], 'aws-s3'))
            ->control(Text::make('url')
                ->textExpanderTriggers(SelectOptions::getEnvTextExpanderTriggers(true, fn ($value): bool => Str::isUrl($value)))
                ->placeholder('https://s3.your-region.amazonaws.com/your-bucket-name/'))
            ->tip(t('Type `$` to choose an environment variable, or `@` to choose an alias.')));

        $settingsForm->add(Separator::make('credentials-separator'));

        $settingsForm->add(
            Field::make(t('Access Key ID', category: 'aws-s3'), Text::make('keyId')
                ->textExpanderTriggers($expansions))
                ->instructions(t('You can leave this field empty if you are using an EC2 instance with an applicable IAM role assignment.', category: 'aws-s3'))
                ->tip($environmentTip),
            Field::make(t('Secret Access Key', category: 'aws-s3'), Text::make('secret')
                ->textExpanderTriggers($expansions))
                ->instructions(t('You can leave this field empty if you are using an EC2 instance with an applicable IAM role assignment.', category: 'aws-s3'))
                ->tip($environmentTip),
            Field::make(t('Region', category: 'aws-s3'), Text::make('region')
                ->textExpanderTriggers($expansions))
            ->instructions(t('Select the region your desired bucket lives in.', category: 'aws-s3')),
        );

        // Default to an empty list:
        $buckets = [];

        try {
            // Try and load buckets via the API:
            $buckets = $this->listBuckets();
        } catch (CredentialsException $e) {
            Log::error(sprintf('Credentials were missing or invalid when trying to list the accessible buckets: %s', $e->getMessage()));

            $settingsForm->add(Callout::make('bucket-list-error', t('The available credentials were not sufficient to populate a list of bucket options. If you know the bucket’s name, you can enter it manually, below.', category: 'aws-s3'))->variant('warning'));
        }

        $settingsForm->add(
            Field::make(
                t('Bucket', category: 'aws-s3'),
                Combobox::make('bucket')
                    ->options([
                        ...Collection::make($buckets)
                            ->map(fn(string $name): array => [
                                'value' => $name,
                                'label' => $name,
                            ])
                            ->all(),
                        ...SelectOptions::getEnvSuggestions(),
                    ])
                    ->showAllOnEmpty()
            )
                ->instructions(t('Choose from one of the buckets accessible with the current credentials, or provide one by name or using an environment variable.', category: 'aws-s3'))
                ->tip($environmentTip)
        );

        $settingsForm->add(
            Field::make(t('Subfolder', category: 'aws-s3'), Text::make('subfolder')
                ->textExpanderTriggers($expansions)
                ->placeholder(t('path/to/subfolder', category: 'aws-s3')))
                ->instructions(t('Your filesystem will be mounted at the root of the selected bucket, unless you provide a subpath. If you intend to use a single bucket for multiple filesystems, this is required to prevent overlap.', category: 'aws-s3'))
        );

        $settingsForm->add(
            Field::make(t('Add the subfolder to the Base URL?', category: 'aws-s3'), Lightswitch::make('addSubfolderToRootUrl'))
                ->instructions(t('Turn this on if you want to add the specified subfolder to the Base URL.', category: 'aws-s3'))
        );

        $settingsForm->add(
            Field::make(t('Make Uploads Public?', category: 'aws-s3'), Lightswitch::make('makeUploadsPublic'))
                ->instructions(t('Sets the ACL for uploaded objects. This should generally be _on_ if you want assets to be accessible by URL.', category: 'aws-s3'))
                ->warning(t('This also applies to thumbnails that are stored on this filesystem!', category: 'aws-s3'))
        );

        if ($this->url && ! $this->makeUploadsPublic) {
            $settingsForm->add(Callout::make('non-public-uploads-warning', t('Craft will generate URLs for assets in this filesystem, but they may not be accessible publicly.', category: 'aws-s3'))->variant('warning'));
        }

        $settingsForm->add(
            Field::make(t('Cache Duration', category: 'aws-s3'), Text::make('expires')->monospace())
                ->instructions(t('Used to set the `CacheControl` option when an asset is uploaded. S3 then sends the resolved value as the `Cache-Control` HTTP header when serving the asset. The value must be a valid interval expression, like `1 week` or `6 months` (a combination of a `number` and `unit` from PHP’s [relative date formatting]({url}) syntax).', ['url' => 'https://www.php.net/manual/en/datetime.formats.php#datetime.formats.relative'], 'aws-s3'))
        );

        $settingsForm->add(Separator::make('additional-settings-separator'));
        $settingsForm->add(Heading::make('additional-services-heading', t('Additional Services', category: 'aws-s3')));

        $settingsForm->add(
            Field::make(t('Attempt to set the focal point automatically?', category: 'aws-s3'), Lightswitch::make('autoFocalPoint'))
                ->instructions(t('Turn this on if you want to use the [AWS Rekognition]({url}) to try setting the focal point to a detected face. You can always set focal points manually.', ['url' => 'https://aws.amazon.com/rekognition/'], 'aws-s3'))
                ->warning(t('This feature requires the `rekognition:DetectFaces` permission and may incur extra costs for each upload.', category: 'aws-s3'))
        );

        $settingsForm->add(Heading::make('cache-settings-separator', t('Cloudfront', category: 'aws-s3'))->level(3));

        $settingsForm->add(
            Field::make(t('Cloudfront Distribution ID', category: 'aws-s3'), Text::make('cfDistributionId')
                ->textExpanderTriggers($expansions))
                ->instructions(t('If you’re using CloudFront as a CDN for the connected bucket, enter its distribution ID so the plugin can purge assets when they’re modified.', category: 'aws-s3'))
        );

        if (! empty($this->cfDistributionId)) {
            $settingsForm->add(
                Field::make(t('Cloudfront Path Prefix', category: 'aws-s3'), Text::make('cfPrefix')
                    ->textExpanderTriggers($expansions))
                    ->instructions(t('If you’re using CloudFront as CDN for the connected bucket and have configured subfolders or custom behaviors, enter the path prefix the plugin should use when invalidating files.', category: 'aws-s3'))
            );
        }

        return $settingsForm;
    }

    #[Override]
    public function getRootUrl(): ?string
    {
        $url = Env::parse($this->url);

        if (! $this->addSubfolderToRootUrl) {
            return $url;
        }

        $subfolder = Env::parse($this->subfolder);

        if (! empty($subfolder)) {
            $url = $url.ltrim($subfolder, '/');
        }

        return $url;
    }

    public function getClientConfig(bool $refresh = false): array
    {
        $config = [
            'region' => Env::parse($this->region),
            // TODO: Add `http_handler` with a proxy-aware Guzzle client, once there is a drop-in replacement for `Craft::createGuzzleClient()`
        ];

        $key = Env::parse($this->keyId);
        $secret = Env::parse($this->secret);

        if (empty($key) || empty($secret)) {
            // Check for predefined access
            if (Env::get('AWS_WEB_IDENTITY_TOKEN_FILE') && Env::get('AWS_ROLE_ARN')) {
                // Check if anything is defined for a web identity provider
                // @see https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials_provider.html#assume-role-with-web-identity-provider)
                $provider = CredentialProvider::assumeRoleWithWebIdentityCredentialProvider();
                $provider = CredentialProvider::memoize($provider);

                $config['credentials'] = $provider;
            }

            // Are we running on ECS?
            if (Env::get('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI')) {
                // Check if anything is defined for an ecsCredentials provider:
                $provider = CredentialProvider::ecsCredentials();
                $provider = CredentialProvider::memoize($provider);

                $config['credentials'] = $provider;
            }

            // (One final possibility is that the app is running on EC2 and we have an implicit IAM role, so no action is required!)
        } else {
            $tokenKey = static::AWS_STS_CACHE_KEY_PREFIX.md5($key.$secret);
            $credentials = new Credentials($key, $secret);

            if (Cache::has($tokenKey) && ! $refresh) {
                $cached = Cache::get($tokenKey);
                $credentials->unserialize(Crypt::decrypt($cached));
            } else {
                // Temporarily assign to the base config object so we can instantiate an STS client:
                $config['credentials'] = $credentials;
                $stsClient = new StsClient($config);
                $result = $stsClient->getSessionToken([
                    'DurationSeconds' => static::AWS_STS_CACHE_DURATION,
                ]);
                $credentials = $stsClient->createCredentials($result);
                $cacheDuration = $credentials->getExpiration() - time();
                $cacheDuration = $cacheDuration > 0 ? $cacheDuration : static::AWS_STS_CACHE_DURATION;
                Cache::set($tokenKey, Crypt::encrypt($credentials->serialize()), $cacheDuration);
            }

            // Assign the result of either path:
            $config['credentials'] = $credentials;
        }

        return $config;
    }

    public function listBuckets(): array
    {
        $client = new S3Client($this->getClientConfig());

        $result = $client->listBuckets();

        if (empty($result['Buckets'])) {
            return [];
        }

        // Return just their names:
        return array_column($result['Buckets'], 'Name');
    }

    public function detectFocalPoint(Asset $asset): array
    {
        $volume = $asset->getVolume();

        $path = implode('/', array_filter([
            $this->subfolder ? Str::trim(Env::parse($this->subfolder), '/') : null,
            Str::trim($volume->getSubpath(), '/'),
            Str::trim($asset->getPath(), '/'),
        ]));

        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, ['jpeg', 'jpg', 'png'])) {
            return [];
        }

        $client = new RekognitionClient($this->getClientConfig());
        $params = [
            'Image' => [
                'S3Object' => [
                    'Name' => $path,
                    'Bucket' => Env::parse($this->bucket),
                ],
            ],
        ];

        $faceData = $client->detectFaces($params);

        if (empty($faceData['FaceDetails'])) {
            throw new FaceDetectionException('No face data was returned from the API.');
        }

        $face = array_shift($faceData['FaceDetails']);

        if ($face['Confidence'] < $this->getMinFaceDetectionConfidence()) {
            throw new FaceDetectionException(sprintf('Confidence was below the required threshold of %d.', $this->getMinFaceDetectionConfidence()));
        }

        $box = $face['BoundingBox'];

        return [
            number_format($box['Left'] + ($box['Width'] / 2), 4),
            number_format($box['Top'] + ($box['Height'] / 2), 4),
        ];
    }

    public function getMinFaceDetectionConfidence(): int
    {
        return self::AWS_REKOGNITION_MIN_CONFIDENCE;
    }

    public function invalidateCdnPath(string $path): void
    {
        // The first time this is called, set a "termination" handler:
        if (empty($this->pathsToInvalidate)) {
            app()->terminating($this->purgeQueuedPaths(...));
        }

        // Paths-as-keys to prevent duplicates:
        $this->pathsToInvalidate[$path] = true;
    }

    public function purgeQueuedPaths(): void
    {
        // Is there anything to do for this filesystem?
        if (empty($this->pathsToInvalidate)) {
            return;
        }

        // We may have tracked paths, but they’re meaningless if we don’t have a way to purge them:
        if (empty($this->cfDistributionId)) {
            return;
        }

        $cfClient = new CloudFrontClient($this->getClientConfig());
        $items = [];
        $cfPrefix = $this->getCloudfrontPrefix();

        foreach (array_keys($this->pathsToInvalidate) as $path) {
            $items[] = sprintf('/%s%s', $cfPrefix, ltrim($path, '/'));
        }

        event($invalidationEvent = new InvalidatingPaths($items));

        try {
            $cfClient->createInvalidation([
                'DistributionId' => Env::parse($this->cfDistributionId),
                'InvalidationBatch' => [
                    'Paths' => [
                        'Quantity' => count($invalidationEvent->paths),
                        'Items' => $invalidationEvent->paths,
                    ],
                    'CallerReference' => 'Craft-'.Str::random(24),
                ],
            ]);
        } catch (CloudFrontException $exception) {
            // Log the warning, most likely due to 404. Allow the operation to continue, though.
            Log::warning($exception->getMessage());
        }
    }

    private function getCloudfrontPrefix(): string
    {
        $prefix = rtrim(Env::parse($this->cfPrefix), '/');

        if (! $prefix) {
            return '';
        }

        return $prefix.'/';
    }
}
