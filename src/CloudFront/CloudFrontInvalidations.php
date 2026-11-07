<?php

declare(strict_types=1);

namespace CraftCms\AwsS3\CloudFront;

use Aws\CloudFront\CloudFrontClient;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use CraftCms\AwsS3\Plugin;
use CraftCms\AwsS3\Settings;
use CraftCms\Cms\Support\Str;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Collects paths to invalidate in the CloudFront distributions in front of S3 disks, and invalidates them together at
 * the end of the request or queue job.
 *
 * A disk’s distribution is set with its `cloudfront_distribution_id` config key, and the path its files are served
 * under with `cloudfront_path_prefix`.
 */
#[Singleton]
class CloudFrontInvalidations
{
    /** @var array<string, array{config: array<string, mixed>, paths: array<string, true>}> Indexed by distribution ID */
    private array $pending = [];

    private bool $flushing = false;

    public function add(AwsS3V3Adapter $disk, string $path): void
    {
        $diskConfig = $disk->getConfig();
        $distributionId = $diskConfig['cloudfront_distribution_id'] ?? null;

        if (!is_string($distributionId) || $distributionId === '') {
            return;
        }

        if (!$this->flushing) {
            app()->terminating($this->flush(...));
            Event::listen([JobProcessed::class, JobFailed::class], $this->flush(...));
            $this->flushing = true;
        }

        $prefix = trim((string) ($diskConfig['cloudfront_path_prefix'] ?? ''), '/');
        $path = '/' . ltrim(($prefix !== '' ? "$prefix/" : '') . $disk->path($path), '/');

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $this->pending[$distributionId]['config'] ??= $settings->cloudFrontConfig($disk);
        $this->pending[$distributionId]['paths'][$path] = true;
    }

    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $distributionId => ['config' => $config, 'paths' => $paths]) {
            try {
                /** @var CloudFrontClient $client */
                $client = app(CloudFrontClient::class, ['args' => $config]);
                $client->createInvalidation([
                    'DistributionId' => $distributionId,
                    'InvalidationBatch' => [
                        'Paths' => [
                            'Quantity' => count($paths),
                            'Items' => array_keys($paths),
                        ],
                        'CallerReference' => 'Craft-' . Str::random(24),
                    ],
                ]);
            } catch (AwsException|CredentialsException $exception) {
                // Files were already changed, so don't fail the request or job over a stale cache
                Log::warning(sprintf('Couldn’t invalidate paths in CloudFront distribution %s: %s', $distributionId, $exception->getMessage()));
            }
        }
    }
}
