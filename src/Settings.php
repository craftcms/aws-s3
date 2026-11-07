<?php

declare(strict_types=1);

namespace CraftCms\AwsS3;

use Closure;
use CraftCms\Cms\Asset\Data\Volume;
use CraftCms\Cms\Plugin\PluginSettings;
use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Ui\Controls\Choice;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Override;

class Settings extends PluginSettings
{
    /**
     * @var string|list<string> Handles of the volumes whose new images get focal points from detected faces. `*`, or a
     *                          list containing it, means all volumes, including ones added later.
     */
    public string|array $volumes = [];

    /**
     * @var string|null The AWS access key ID to call Rekognition and CloudFront with, or an environment variable that
     *                  holds it. When it’s empty, the disk’s credentials are used for disks on Amazon S3, and the AWS SDK’s default
     *                  credentials (such as `AWS_ACCESS_KEY_ID` or an IAM role) for disks on S3-compatible services.
     */
    public ?string $keyId = null;

    /**
     * @var string|null The AWS secret access key to call Rekognition and CloudFront with, or an environment variable that holds it.
     */
    public ?string $secret = null;

    /**
     * @var string|null The AWS region to call Rekognition in, or an environment variable that holds it. When it’s
     *                  empty, the disk’s region is used for disks on Amazon S3. Disks on S3-compatible services need one.
     */
    public ?string $region = null;

    #[Override]
    public function getRules(): array
    {
        return [
            'keyId' => ['nullable', 'string', 'required_with:secret'],
            'secret' => ['nullable', 'string', 'required_with:keyId'],
            'region' => ['nullable', 'string'],
        ];
    }

    public function detectsFacesIn(Volume $volume): bool
    {
        $volumes = (array) $this->volumes;

        return in_array(Choice::ALL_VALUE, $volumes, true) || in_array($volume->handle, $volumes, true);
    }

    /**
     * Returns the Rekognition client configuration for images on a disk, or `null` if there’s no region to call
     * Rekognition in.
     *
     * @return array<string, mixed>|null
     */
    public function rekognitionConfig(AwsS3V3Adapter $disk): ?array
    {
        $region = Env::parse($this->region) ?? ($this->isOnAws($disk) ? $disk->getClient()->getRegion() : null);

        if ($region === null) {
            return null;
        }

        return array_filter([
            'version' => 'latest',
            'region' => $region,
            'credentials' => $this->credentials($disk),
        ]);
    }

    /**
     * Returns the CloudFront client configuration for invalidating paths in front of a disk.
     *
     * @return array<string, mixed>
     */
    public function cloudFrontConfig(AwsS3V3Adapter $disk): array
    {
        // CloudFront is a global service, called in us-east-1
        return array_filter([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => $this->credentials($disk),
        ]);
    }

    /**
     * Returns whether Rekognition can read images straight from the disk’s bucket, which needs the bucket to be on
     * Amazon S3, in the region Rekognition is called in, and readable with the credentials it’s called with. Otherwise
     * the image is sent to Rekognition.
     */
    public function readsFromBucket(AwsS3V3Adapter $disk): bool
    {
        if (! $this->isOnAws($disk) || $this->hasCredentials()) {
            return false;
        }

        $region = Env::parse($this->region);

        return $region === null || $region === $disk->getClient()->getRegion();
    }

    /**
     * Returns the credentials to call AWS services with for a disk: the ones in the settings, or else the disk’s own
     * for disks on Amazon S3. `null` lets the AWS SDK find credentials itself, such as in environment variables or an
     * IAM role.
     *
     * @return array{key: string|null, secret: string|null}|Closure|null
     */
    private function credentials(AwsS3V3Adapter $disk): array|Closure|null
    {
        if ($this->hasCredentials()) {
            return ['key' => Env::parse($this->keyId), 'secret' => Env::parse($this->secret)];
        }

        if ($this->isOnAws($disk)) {
            $s3 = $disk->getClient();

            return fn () => $s3->getCredentials();
        }

        return null;
    }

    private function isOnAws(AwsS3V3Adapter $disk): bool
    {
        return empty($disk->getConfig()['endpoint']);
    }

    private function hasCredentials(): bool
    {
        return Env::parse($this->keyId) !== null && Env::parse($this->secret) !== null;
    }
}
