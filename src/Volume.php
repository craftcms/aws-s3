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
use Aws\S3\S3Client;
use Aws\Sts\StsClient;
use Craft;
use craft\base\FlysystemVolume;
use craft\helpers\Assets;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use DateTime;
use League\Flysystem\AwsS3v3\AwsS3Adapter;

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

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function rules()
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
        $config = self::_buildConfigArray($keyId, $secret, 'us-east-1');

        $client = static::client($config);

        $objects = $client->listBuckets();

        if (empty($objects['Buckets'])) {
            return [];
        }

        $buckets = $objects['Buckets'];
        $bucketList = [];

        foreach ($buckets as $bucket) {
            try {
                $location = $client->determineBucketRegion($bucket['Name']);
            } catch (S3Exception $exception) {
                continue;
            }

            $bucketList[] = [
                'bucket' => $bucket['Name'],
                'urlPrefix' => 'http://' . $bucket['Name'] . '.s3.amazonaws.com/',
                'region' => $location ?? ''
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
            $rootUrl .= $this->_subfolder();
        }
        return $rootUrl;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return AwsS3Adapter
     */
    protected function createAdapter()
    {
        $config = $this->_getConfigArray();

        $client = static::client($config);

        return new AwsS3Adapter($client, $this->bucket, $this->_subfolder());
    }

    /**
     * Get the Amazon S3 client.
     *
     * @param $config
     * @return S3Client
     */
    protected static function client(array $config = []): S3Client
    {
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
            $config['CacheControl'] = 'max-age=' . $diff . ', must-revalidate';
        }

        return parent::addFileMetadataToConfig($config);
    }

    /**
     * @inheritdoc
     */
    protected function invalidateCdnPath(string $path): bool
    {
        if (!empty($this->cfDistributionId)) {
            // If there's a CloudFront distribution ID set, invalidate the path.
            $cfClient = $this->_getCloudFrontClient();

            try {
                $cfClient->createInvalidation(
                    [
                        'DistributionId' => Craft::parseEnv($this->cfDistributionId),
                        'InvalidationBatch' => [
                            'Paths' =>
                                [
                                    'Quantity' => 1,
                                    'Items' => ['/' . $this->_cfPrefix() . ltrim($path, '/')]
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

        return true;
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
                    'Name' => $filePath,
                    'Bucket' => $this->bucket,
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
        $keyId = Craft::parseEnv($this->keyId);
        $secret = Craft::parseEnv($this->secret);
        $region = $this->region;

        return self::_buildConfigArray($keyId, $secret, $region);
    }

    /**
     * Build the config array based on a keyID and secret
     *
     * @param $keyId
     * @param $secret
     * @param $region
     * @return array
     */
    private static function _buildConfigArray($keyId = null, $secret = null, $region = null)
    {
        $config = [
            'region' => $region,
            'version' => 'latest'
        ];

        if (empty($keyId) || empty($secret)) {
            // Assume we're running on EC2 and we have an IAM role assigned. Kick back and relax.
        } else {
            $tokenKey = static::CACHE_KEY_PREFIX . md5($keyId . $secret);
            $credentials = new Credentials($keyId, $secret);

            if (Craft::$app->cache->exists($tokenKey)) {
                $cached = Craft::$app->cache->get($tokenKey);
                $credentials->unserialize($cached);
            } else {
                $config['credentials'] = $credentials;
                $stsClient = new StsClient($config);
                $result = $stsClient->getSessionToken(['DurationSeconds' => static::CACHE_DURATION_SECONDS]);
                $credentials = $stsClient->createCredentials($result);
                Craft::$app->cache->set($tokenKey, $credentials->serialize(), static::CACHE_DURATION_SECONDS);
            }

            // TODO Add support for different credential supply methods
            $config['credentials'] = $credentials;
        }

        $client = Craft::createGuzzleClient();
        $config['http_handler'] = new GuzzleHandler($client);

        return $config;
    }
}
