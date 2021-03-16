<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\awss3;

use Aws\CloudFront\CloudFrontClient;
use Aws\CloudFront\Exception\CloudFrontException;
use Aws\Credentials\Credentials;
use Aws\Handler\GuzzleV6\GuzzleHandler;
use Aws\Rekognition\RekognitionClient;
use Aws\S3\Exception\S3Exception;
use Aws\Sts\StsClient;
use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\flysystem\base\FlysystemVolume;
use craft\helpers\ArrayHelper;
use craft\helpers\Assets;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use DateTime;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Visibility;
use yii\base\Application;

/**
 * Class Volume
 *
 * @property mixed $settingsHtml
 * @property string $rootUrl
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0
 */
class Volume extends FlysystemVolume
{
    // Constants
    // =========================================================================

    const STORAGE_STANDARD = 'STANDARD';
    const STORAGE_REDUCED_REDUNDANCY = 'REDUCED_REDUNDANCY';
    const STORAGE_STANDARD_IA = 'STANDARD_IA';

    /**
     * Cache key to use for caching purposes
     */
    const CACHE_KEY_PREFIX = 'aws.';

    /**
     * Cache duration for access token
     */
    const CACHE_DURATION_SECONDS = 3600;

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
     * @var bool Whether this is a local source or not. Defaults to false.
     */
    protected $isVolumeLocal = false;

    /**
     * @var string Subfolder to use
     */
    public $subfolder = '';

    /**
     * @var string AWS key ID
     */
    public $keyId = '';

    /**
     * @var string AWS key secret
     */
    public $secret = '';

    /**
     * @var string Bucket selection mode ('choose' or 'manual')
     */
    public $bucketSelectionMode = 'choose';

    /**
     * @var string Bucket to use
     */
    public $bucket = '';

    /**
     * @var string Region to use
     */
    public $region = '';

    /**
     * @var string Cache expiration period.
     */
    public $expires = '';

    /**
     * @var bool Set ACL for Uploads
     */
    public $makeUploadsPublic = true;

    /**
     * @var string S3 storage class to use.
     * @deprecated in 1.1.1
     */
    public $storageClass = '';

    /**
     * @var string CloudFront Distribution ID
     */
    public $cfDistributionId;

    /**
     * @var string CloudFront Distribution Prefix
     */
    public $cfPrefix;

    /**
     * @var bool Whether facial detection should be attempted to set the focal point automatically
     */
    public $autoFocalPoint = false;

    /**
     * @var bool Whether the specified sub folder shoul be added to the root URL
     */
    public $addSubfolderToRootUrl = true;

    /**
     * @var array A list of paths to invalidate at the end of request.
     */
    protected $pathsToInvalidate = [];

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
    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['bucket', 'region'], 'required'];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('aws-s3/volumeSettings', [
            'volume' => $this,
            'periods' => array_merge(['' => ''], Assets::periodList()),
        ]);
    }

    /**
     * Get the bucket list using the specified credentials.
     *
     * @param $keyId
     * @param $secret
     * @return array
     * @throws \InvalidArgumentException
     */
    public static function loadBucketList($keyId, $secret)
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

            $bucketList[] = [
                'bucket' => $bucket['Name'],
                'urlPrefix' => 'https://s3.'.$region.'.amazonaws.com/'.$bucket['Name'].'/',
                'region' => $region
            ];
        }

        return $bucketList;
    }

    /**
     * @inheritdoc
     */
    public function getRootUrl()
    {
        if (($rootUrl = parent::getRootUrl()) !== false) {
            if ($this->addSubfolderToRootUrl) {
                $rootUrl .= $this->_subfolder();
            }
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
        $config = $this->_getConfigArray();

        $client = static::client($config, $this->_getCredentials());

        return new AwsS3V3Adapter($client, Craft::parseEnv($this->bucket), $this->_subfolder(), new PortableVisibilityConverter($this->visibility()), null, [], false);
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
            $config['generateNewConfig'] = function() use ($credentials) {
                $args = [
                    $credentials['keyId'],
                    $credentials['secret'],
                    $credentials['region'],
                    true
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
            $diff = $expires->format('U') - $now->format('U');
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

            $this->pathsToInvalidate[$path] = true;
        }

        return true;
    }

    public function purgeQueuedPaths()
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
                                    'Items' => $items
                                ],
                            'CallerReference' => 'Craft-' . StringHelper::randomString(24)
                        ]
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
     * @param string|null $keyId The key ID
     * @param string|null $secret The key secret
     * @param string|null $region The region to user
     * @param bool $refreshToken If true will always refresh token
     * @return array
     */
    public static function buildConfigArray($keyId = null, $secret = null, $region = null, $refreshToken = false): array
    {
        $config = [
            'region' => $region,
            'version' => 'latest'
        ];

        $client = Craft::createGuzzleClient();
        $config['http_handler'] = new GuzzleHandler($client);

        if (empty($keyId) || empty($secret)) {
            // Assume we're running on EC2 and we have an IAM role assigned. Kick back and relax.
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
                $cacheDuration = $cacheDuration > 0 ?: static::CACHE_DURATION_SECONDS;
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
     * @return string|null
     */
    private function _subfolder(): string
    {
        if ($this->subfolder && ($subfolder = rtrim(Craft::parseEnv($this->subfolder), '/')) !== '') {
            return $subfolder . '/';
        }
        return '';
    }

    /**
     * Returns the parsed CloudFront distribution prefix
     *
     * @return string|null
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
    private function _getCloudFrontClient()
    {
        return new CloudFrontClient($this->_getConfigArray());
    }

    /**
     * Get the config array for AWS Clients.
     *
     * @return array
     */
    private function _getConfigArray()
    {
        $credentials = $this->_getCredentials();

        return self::buildConfigArray($credentials['keyId'], $credentials['secret'], $credentials['region']);
    }

    /**
     * Return the credentials as an array
     *
     * @return array
     */
    private function _getCredentials()
    {
        return [
            'keyId' => Craft::parseEnv($this->keyId),
            'secret' => Craft::parseEnv($this->secret),
            'region' => Craft::parseEnv($this->region),
        ];
    }
    /**
     * Returns the visibility setting for the Volume.
     *
     * @return string
     */
    protected function visibility(): string {
        return $this->makeUploadsPublic ? Visibility::PUBLIC : Visibility::PRIVATE;
    }
}
