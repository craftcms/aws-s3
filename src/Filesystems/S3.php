<?php

namespace CraftCms\AwsS3\Filesystems;

use Aws\CloudFront\CloudFrontClient;
use Aws\CloudFront\Exception\CloudFrontException;
use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\Rekognition\RekognitionClient;
use Aws\Sts\StsClient;
use CraftCms\AwsS3\Assets\AwsS3Bundle;
use CraftCms\AwsS3\Enums\BucketSelectionMode;
use CraftCms\AwsS3\Events\InvalidatingPaths;
use CraftCms\AwsS3\Exceptions\FaceDetectionException;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Filesystem\Filesystems\Filesystem;
use CraftCms\Cms\Shared\Enums\TimePeriod;
use CraftCms\Cms\Support\Arr;
use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Support\Str;
use CraftCms\Cms\View\LegacyAssets\InternalAssetRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Visibility;
use Override;
use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

class S3 extends Filesystem
{
    const int AWS_STS_CACHE_DURATION = 3600;
    const string AWS_STS_CACHE_KEY_PREFIX = 'aws-sts.';
    const string AWS_DEFAULT_REGION = 'us-east-1';
    const int AWS_REKOGNITION_MIN_CONFIDENCE = 80;

    public ?string $keyId = null;
    public ?string $secret = null;
    public string $region = self::AWS_DEFAULT_REGION;
    public ?string $bucket = null;
    public ?string $expires = null;
    public ?string $subfolder = null;
    public bool $makeUploadsPublic = true;
    public BucketSelectionMode $bucketSelectionMode = BucketSelectionMode::Choose;

    public ?string $cfDistributionId = null;
    public ?string $cfPrefix = null;

    public bool $autoFocalPoint = false;

    public bool $addSubfolderToRootUrl = true;
    protected array $pathsToInvalidate = [];

    public function __construct(object|array $config = [])
    {
        if ($config['manualBucket'] ?? false) {
            if (isset($config['bucketSelectionMode']) && $config['bucketSelectionMode'] === BucketSelectionMode::Manual) {
                $config['bucket'] = Arr::pull($config, 'manualBucket');
                $config['region'] = Arr::pull($config, 'manualRegion');
            }
        }

        unset($config['manualBucket'], $config['manualRegion']);

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
            } catch (\InvalidArgumentException $e) {
                Log::warning(sprintf('Skipped setting `options.CacheControl` when configuring an AWS S3 disk due to an invalid duration string: %s', $this->expires));
            }
        }

        return $this->getClientConfig() + [
            'driver' => 's3',
            'bucket' => $this->bucket,
            'url' => Env::parse($this->url),
            // The `prefix` option is eventually passed to the S3 adapter as `root`:
            'prefix' => Env::parse($this->subfolder),
            'visibility' => $this->makeUploadsPublic ? Visibility::PUBLIC : Visibility::PRIVATE,
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
            'options' => $options,
        ];
    }

    public function getRules(): array
    {
        return parent::getRules() + [];
    }

    public function getSettingsHtml(): ?string
    {
        app(InternalAssetRegistry::class)->register(AwsS3Bundle::class);

        return template('aws-s3/fsSettings', [
            'fs' => $this,
            'periods' => array_merge(
                ['' => ''],
                Arr::mapWithKeys(TimePeriod::cases(), fn ($p) => [$p->value => $p->label()]),
            ),
            'bucketSelectionModes' => Arr::mapWithKeys(BucketSelectionMode::cases(), fn ($mode) => [$mode->value => $mode->label()]),
        ]);
    }

    #[Override]
    public function getRootUrl(): ?string
    {
        $url = parent::getRootUrl();

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
