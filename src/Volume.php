<?php
/**
 * @link      http://buildwithcraft.com/
 * @copyright Copyright (c) 2015 Pixel & Tonic, Inc.
 * @license   http://buildwithcraft.com/license
 */
namespace craft\awss3;

use Aws\CloudFront\CloudFrontClient;
use Aws\CloudFront\Exception\CloudFrontException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Craft;
use craft\errors\VolumeException;
use craft\helpers\Assets;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use DateTime;
use League\Flysystem\AwsS3v3\AwsS3Adapter;


/**
 * Class Volume
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Volume extends \craft\base\Volume
{
    // Constants
    // =========================================================================

    const STORAGE_STANDARD = 'STANDARD';
    const STORAGE_REDUCED_REDUNDANCY = 'REDUCED_REDUNDANCY';
    const STORAGE_STANDARD_IA = 'STANDARD_IA';

    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName()
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
     */
    public $storageClass = '';

    /**
     * @var string CloudFront Distribution ID
     */
    public $cfDistributionId;

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
        return Craft::$app->getView()->renderTemplate('awss3/volumeSettings', [
            'volume' => $this,
            'periods' => array_merge(['' => ''], Assets::periodList()),
            'storageClasses' => static::storageClasses(),
        ]);
    }

    /**
     * Get the bucket list using the specified credentials.
     *
     * @param $keyId
     * @param $secret
     *
     * @return array
     * @throws \InvalidArgumentException
     */
    public static function loadBucketList($keyId, $secret)
    {
        // Any region will do.
        $config = static::_buildConfigArray($keyId, $secret, 'us-east-1');

        $client = static::client($config);

        $objects = $client->listBuckets();

        if (empty($objects['Buckets'])) {
            return [];
        }

        $buckets = $objects['Buckets'];
        $bucketList = [];

        foreach ($buckets as $bucket) {
            try {
                $location = $client->getBucketLocation(['Bucket' => $bucket['Name']]);
            } catch (S3Exception $exception) {
                continue;
            }

            $bucketList[] = [
                'bucket' => $bucket['Name'],
                'urlPrefix' => 'http://'.$bucket['Name'].'.s3.amazonaws.com/',
                'region' => isset($location['Location']) ? $location['Location'] : ''
            ];
        }

        return $bucketList;
    }

    /**
     * @inheritdoc
     */
    public function getRootUrl()
    {
        return rtrim(rtrim($this->url, '/').'/'.$this->subfolder, '/').'/';
    }

    /**
     * Return a list of available storage classes.
     *
     * @return array
     */
    public static function storageClasses()
    {
        return [
            static::STORAGE_STANDARD => 'Standard',
            static::STORAGE_REDUCED_REDUNDANCY => 'Reduced Redundancy Storage',
            static::STORAGE_STANDARD_IA => 'Infrequent Access Storage'
        ];
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return AwsS3Adapter
     */
    protected function createAdapter()
    {
        $config = $this->_getConfigArray();

        $client = static::client($config);

        return new AwsS3Adapter($client, $this->bucket, $this->subfolder);
    }

    /**
     * Get the Amazon S3 client.
     *
     * @param $config
     *
     * @return S3Client
     */
    protected static function client($config = [])
    {
        return new S3Client($config);
    }

    /**
     * @inheritdoc
     */
    protected function addFileMetadataToConfig($config)
    {
        if (!empty($this->expires) && DateTimeHelper::isValidIntervalString($this->expires)) {
            $expires = new DateTime();
            $now = new DateTime();
            $expires->modify('+'.$this->expires);
            $diff = $expires->format('U') - $now->format('U');
            $config['CacheControl'] = 'max-age='.$diff.', must-revalidate';
        }

        if (!empty($this->storageClass)) {
            $config['StorageClass'] = $this->storageClass;
        }

        return parent::addFileMetadataToConfig($config);
    }

    /**
     * @inheritdoc
     */
    protected function invalidateCdnPath($path)
    {
        if (!empty($this->cfDistributionId)) {
            // If there's a CloudFront distribution ID set, invalidate the path.
            $cfClient = $this->_getCloudFrontClient();

            try {
                $cfClient->createInvalidation(
                    [
                        'DistributionId' => $this->cfDistributionId,
                        'InvalidationBatch' => [
                            'Paths' =>
                                [
                                    'Quantity' => 1,
                                    'Items' => ['/'.ltrim($path, '/')]
                                ],
                            'CallerReference' => 'Craft-'.StringHelper::randomString(24)
                        ]
                    ]
                );
            } catch (CloudFrontException $exception) {
                Craft::warning($exception->getMessage());
                throw new VolumeException('Failed to invalidate the CDN path for '.$path);
            }
        }

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Get a CloudFront client.
     *
     * @return CloudFrontClient
     */
    private function _getCloudFrontClient()
    {
        $config = $this->_getConfigArray();

        return CloudFrontClient::factory($config);
    }

    /**
     * Get the config array for AWS Clients.
     *
     * @return array
     */
    private function _getConfigArray()
    {
        $keyId = $this->keyId;
        $secret = $this->secret;
        $region = $this->region;

        return static::_buildConfigArray($keyId, $secret, $region);
    }

    /**
     * Build the config array based on a keyID and secret
     *
     * @param $keyId
     * @param $secret
     *
     * @return array
     */
    private static function _buildConfigArray($keyId = null, $secret = null, $region = null)
    {
        if (empty($keyId) || empty($secret)) {
            $config = [];
        } else {
            // TODO Add support for different credential supply methods
            // And look into v4 signature token caching.
            $config = [
                'credentials' => [
                    'key' => $keyId,
                    'secret' => $secret
                ]
            ];
        }

        $config['region'] = $region;
        $config['version'] = 'latest';

        return $config;
    }
}
