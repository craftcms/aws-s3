<?php

declare(strict_types=1);
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\awss3;

use Aws\CloudFront\CloudFrontClient;
use Aws\CloudFront\Exception\CloudFrontException;
use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\Handler\GuzzleV6\GuzzleHandler;
use Aws\Rekognition\RekognitionClient;
use Aws\S3\Exception\S3Exception;
use Aws\Sts\StsClient;
use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\flysystem\base\FlysystemFs;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Assets;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use DateTime;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Visibility;
use yii\base\Application;

/**
 * Class Fs
 *
 * @property mixed $settingsHtml
 * @property string $rootUrl
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class Fs extends FlysystemFs
{
    // Constants
    // =========================================================================

    public const STORAGE_STANDARD = 'STANDARD';
    public const STORAGE_REDUCED_REDUNDANCY = 'REDUCED_REDUNDANCY';
    public const STORAGE_STANDARD_IA = 'STANDARD_IA';

    /**
     * Cache key to use for caching purposes
     */
    public const CACHE_KEY_PREFIX = 'aws.';

    /**
     * Cache duration for access token
     */
    public const CACHE_DURATION_SECONDS = 3600;

    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Amazon S3';
    }

    // Properties
    // =========================================================================

    /**
     * @var string Subfolder to use
     */
    public string $subfolder = '';

    /**
     * @var string AWS key ID
     */
    public string $keyId = '';

    /**
     * @var string AWS key secret
     */
    public string $secret = '';

    /**
     * @var string Bucket selection mode ('choose' or 'manual')
     */
    public string $bucketSelectionMode = 'choose';

    /**
     * @var string Bucket to use
     */
    public string $bucket = '';

    /**
     * @var string Region to use
     */
    public string $region = '';

    /**
     * @var string Cache expiration period.
     */
    public string $expires = '';

    /**
     * @var bool Set ACL for Uploads
     */
    public bool $makeUploadsPublic = true;

    /**
     * @var string S3 storage class to use.
     * @deprecated in 1.1.1
     */
    public string $storageClass = '';

    /**
     * @var string CloudFront Distribution ID
     */
    public string $cfDistributionId = '';

    /**
     * @var string CloudFront Distribution Prefix
     */
    public string $cfPrefix = '';

    /**
     * @var bool Whether facial detection should be attempted to set the focal point automatically
     */
    public bool $autoFocalPoint = false;

    /**
     * @var bool Whether the specified sub folder should be added to the root URL
     */
    public bool $addSubfolderToRootUrl = true;

    /**
     * @var array A list of paths to invalidate at the end of request.
     */
    protected array $pathsToInvalidate = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function __construct(array $config = [])
    {
        if (isset($config['manualBucket'])) {
            if (isset($config['bucketSelectionMode']) && $config['bucketSelectionMode'] === 'manual') {
                $config['bucket'] = ArrayHelper::remove($config, 'manualBucket');
                $config['region'] = ArrayHelper::remove($config, 'manualRegion');
            } else {
                unset($config['manualBucket'], $config['manualRegion']);
            }
        }

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['parser'] = [
            'class' => EnvAttributeParserBehavior::class,
            'attributes' => [
                'keyId',
                'secret',
                'bucket',
                'region',
                'subfolder',
                'cfDistributionId',
                'cfPrefix',
            ],
        ];
        return $behaviors;
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['bucket', 'region'], 'required'],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('aws-s3/fsSettings', [
            'fs' => $this,
            'periods' => array_merge(['' => ''], Assets::periodList()),
        ]);
    }

    /**
     * Get the bucket list using the specified credentials.
     *
     * @param string|null $keyId The key ID
     * @param string|null $secret The key secret
     * @return array
     * @throws InvalidArgumentException
     */
    public static function loadBucketList(?string $keyId, ?string $secret): array
    {
        // Any region will do.
        $config = self::buildConfigArray($keyId, $secret, 'us-east-1');

        $client = static::client($config);

        $objects = $client->listBuckets();

        if (empty($objects['Buckets'])) {
            return [];
        }

        $buckets = $objects['Buckets'];
        $bucketList = [];

        foreach ($buckets as $bucket) {
            try {
                $region = $client->determineBucketRegion($bucket['Name']);
            } catch (S3Exception $exception) {

                // If a bucket cannot be accessed by the current policy, move along:
                // https://github.com/craftcms/aws-s3/pull/29#issuecomment-468193410
                continue;
            }

            if (str_contains($bucket['Name'], '.')) {
                $urlPrefix = 'https://s3.' . $region . '.amazonaws.com/' . $bucket['Name'] . '/';
            } else {
                $urlPrefix = 'https://' . $bucket['Name'] . '.s3.amazonaws.com/';
            }

            $bucketList[] = [
                'bucket' => $bucket['Name'],
                'urlPrefix' => $urlPrefix,
                'region' => $region,
            ];
        }

        return $bucketList;
    }

    /**
     * @inheritdoc
     */
    public function getRootUrl(): ?string
    {
        $rootUrl = parent::getRootUrl();

        if ($rootUrl) {
            $rootUrl .= $this->_getRootUrlPath();
        }

        return $rootUrl;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return AwsS3V3Adapter
     */
    protected function createAdapter(): FilesystemAdapter
    {
        $client = static::client($this->_getConfigArray(), $this->_getCredentials());
        $options = [

            // This is the S3 default for all objects, but explicitly
            // sending the header allows for bucket policies that require it.
            // @see https://github.com/craftcms/aws-s3/pull/172
            'ServerSideEncryption' => 'AES256',
        ];

        return new AwsS3V3Adapter(
            $client,
            App::parseEnv($this->bucket),
            $this->_subfolder(),
            new PortableVisibilityConverter($this->visibility()),
            null,
            $options,
            false,
        );
    }

    /**
     * Get the Amazon S3 client.
     *
     * @param array $config client config
     * @param array $credentials credentials to use when generating a new token
     * @return S3Client
     */
    protected static function client(array $config = [], array $credentials = []): S3Client
    {
        if (!empty($config['credentials']) && $config['credentials'] instanceof Credentials) {
            $config['generateNewConfig'] = static function() use ($credentials) {
                $args = [
                    $credentials['keyId'],
                    $credentials['secret'],
                    $credentials['region'],
                    true,
                ];
                return call_user_func_array(self::class . '::buildConfigArray', $args);
            };
        }

        return new S3Client($config);
    }

    /**
     * @inheritdoc
     */
    protected function addFileMetadataToConfig(array $config): array
    {
        if (!empty($this->expires) && DateTimeHelper::isValidIntervalString($this->expires)) {
            $expires = new DateTime();
            $now = new DateTime();
            $expires->modify('+' . $this->expires);
            $diff = (int)$expires->format('U') - (int)$now->format('U');
            $config['CacheControl'] = 'max-age=' . $diff;
        }

        return parent::addFileMetadataToConfig($config);
    }

    /**
     * @inheritdoc
     */
    protected function invalidateCdnPath(string $path): bool
    {
        if (!empty($this->cfDistributionId)) {
            if (empty($this->pathsToInvalidate)) {
                Craft::$app->on(Application::EVENT_AFTER_REQUEST, [$this, 'purgeQueuedPaths']);
            }

            // Ensure our paths are prefixed with configured subfolder
            $path = $this->_getRootUrlPath() . $path;

            $this->pathsToInvalidate[$path] = true;
        }

        return true;
    }

    /**
     * Purge any queued paths from the CDN.
     */
    public function purgeQueuedPaths(): void
    {
        if (!empty($this->pathsToInvalidate)) {
            // If there's a CloudFront distribution ID set, invalidate the path.
            $cfClient = $this->_getCloudFrontClient();
            $items = [];

            foreach ($this->pathsToInvalidate as $path => $bool) {
                $items[] = '/' . $this->_cfPrefix() . ltrim($path, '/');
            }

            try {
                $cfClient->createInvalidation(
                    [
                        'DistributionId' => Craft::parseEnv($this->cfDistributionId),
                        'InvalidationBatch' => [
                            'Paths' =>
                                [
                                    'Quantity' => count($items),
                                    'Items' => $items,
                                ],
                            'CallerReference' => 'Craft-' . StringHelper::randomString(24),
                        ],
                    ]
                );
            } catch (CloudFrontException $exception) {
                // Log the warning, most likely due to 404. Allow the operation to continue, though.
                Craft::warning($exception->getMessage());
            }
        }
    }

    /**
     * Attempt to detect focal point for a path on the bucket and return the
     * focal point position as an array of decimal parts
     *
     * @param string $filePath
     * @return array
     */
    public function detectFocalPoint(string $filePath): array
    {
        $extension = StringHelper::toLowerCase(pathinfo($filePath, PATHINFO_EXTENSION));

        if (!in_array($extension, ['jpeg', 'jpg', 'png'])) {
            return [];
        }


        $client = new RekognitionClient($this->_getConfigArray());
        $params = [
            'Image' => [
                'S3Object' => [
                    'Name' => Craft::parseEnv($filePath),
                    'Bucket' => Craft::parseEnv($this->bucket),
                ],
            ],
        ];

        $faceData = $client->detectFaces($params);

        if (!empty($faceData['FaceDetails'])) {
            $face = array_shift($faceData['FaceDetails']);
            if ($face['Confidence'] > 80) {
                $box = $face['BoundingBox'];
                return [
                    number_format($box['Left'] + ($box['Width'] / 2), 4),
                    number_format($box['Top'] + ($box['Height'] / 2), 4),
                ];
            }
        }

        return [];
    }

    /**
     * Build the config array based on a keyID and secret
     *
     * @param ?string $keyId The key ID
     * @param ?string $secret The key secret
     * @param ?string $region The region to user
     * @param bool $refreshToken If true will always refresh token
     * @return array
     */
    public static function buildConfigArray(?string $keyId = null, ?string $secret = null, ?string $region = null, bool $refreshToken = false): array
    {
        $config = [
            'region' => $region,
            'version' => 'latest',
        ];

        $client = Craft::createGuzzleClient();
        $config['http_handler'] = new GuzzleHandler($client);

        /** @noinspection MissingOrEmptyGroupStatementInspection */
        if (empty($keyId) || empty($secret)) {
            // Check for predefined access
            if (App::env('AWS_WEB_IDENTITY_TOKEN_FILE') && App::env('AWS_ROLE_ARN')) {
                // Check if anything is defined for a web identity provider (see: https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials_provider.html#assume-role-with-web-identity-provider)
                $provider = CredentialProvider::assumeRoleWithWebIdentityCredentialProvider();
                $provider = CredentialProvider::memoize($provider);
                $config['credentials'] = $provider;
            }
            // Check if running on ECS
            if (App::env('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI')) {
                // Check if anything is defined for an ecsCredentials provider
                $provider = CredentialProvider::ecsCredentials();
                $provider = CredentialProvider::memoize($provider);
                $config['credentials'] = $provider;
            }
            // If that didn't happen, assume we're running on EC2 and we have an IAM role assigned so no action required.
        } else {
            $tokenKey = static::CACHE_KEY_PREFIX . md5($keyId . $secret);
            $credentials = new Credentials($keyId, $secret);

            if (Craft::$app->cache->exists($tokenKey) && !$refreshToken) {
                $cached = Craft::$app->cache->get($tokenKey);
                $credentials->unserialize($cached);
            } else {
                $config['credentials'] = $credentials;
                $stsClient = new StsClient($config);
                $result = $stsClient->getSessionToken(['DurationSeconds' => static::CACHE_DURATION_SECONDS]);
                $credentials = $stsClient->createCredentials($result);
                $cacheDuration = $credentials->getExpiration() - time();
                $cacheDuration = $cacheDuration > 0 ? $cacheDuration : static::CACHE_DURATION_SECONDS;
                Craft::$app->cache->set($tokenKey, $credentials->serialize(), $cacheDuration);
            }

            // TODO Add support for different credential supply methods
            $config['credentials'] = $credentials;
        }

        return $config;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the parsed subfolder path
     *
     * @return string
     */
    private function _subfolder(): string
    {
        if ($this->subfolder && ($subfolder = rtrim(Craft::parseEnv($this->subfolder), '/')) !== '') {
            return $subfolder . '/';
        }

        return '';
    }

    /**
     * Returns the root path for URLs
     *
     * @return string
     */
    private function _getRootUrlPath(): string
    {
        if ($this->addSubfolderToRootUrl) {
            return $this->_subfolder();
        }
        return '';
    }

    /**
     * Returns the parsed CloudFront distribution prefix
     *
     * @return string
     */
    private function _cfPrefix(): string
    {
        if ($this->cfPrefix && ($cfPrefix = rtrim(Craft::parseEnv($this->cfPrefix), '/')) !== '') {
            return $cfPrefix . '/';
        }

        return '';
    }

    /**
     * Get a CloudFront client.
     *
     * @return CloudFrontClient
     */
    private function _getCloudFrontClient(): CloudFrontClient
    {
        return new CloudFrontClient($this->_getConfigArray());
    }

    /**
     * Get the config array for AWS Clients.
     *
     * @return array
     */
    private function _getConfigArray(): array
    {
        $credentials = $this->_getCredentials();

        return self::buildConfigArray($credentials['keyId'], $credentials['secret'], $credentials['region']);
    }

    /**
     * Return the credentials as an array
     *
     * @return array
     */
    private function _getCredentials(): array
    {
        return [
            'keyId' => Craft::parseEnv($this->keyId),
            'secret' => Craft::parseEnv($this->secret),
            'region' => Craft::parseEnv($this->region),
        ];
    }

    /**
     * Returns the visibility setting for the Fs.
     *
     * @return string
     */
    protected function visibility(): string
    {
        return $this->makeUploadsPublic ? Visibility::PUBLIC : Visibility::PRIVATE;
    }
}
