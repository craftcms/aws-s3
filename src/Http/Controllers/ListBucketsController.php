<?php

namespace CraftCms\AwsS3\Http\Controllers;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use CraftCms\AwsS3\Filesystems\S3;
use CraftCms\Cms\Component\ComponentHelper;
use CraftCms\Cms\Filesystem\Contracts\FsInterface;
use CraftCms\Cms\Support\Env;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListBucketsController
{
    public function __invoke(Request $request)
    {
        $key = Env::parse($request->string('keyId'));
        $secret = Env::parse($request->string('secret'));

        // Create a temporary filesystem instance so we can use the client factory:
        /** @var S3 $fs */
        $fs = ComponentHelper::createComponent([
            'type' => S3::class,
            'keyId' => $key,
            'secret' => $secret,
        ], FsInterface::class);

        $client = new S3Client($fs->getClientConfig());

        $result = $client->listBuckets();

        if (empty($result['Buckets'])) {
            return [];
        }

        $buckets = [];

        foreach ($result['Buckets'] as $bucket) {
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

            $buckets[] = [
                'name' => $bucket['Name'],
                'urlPrefix' => $urlPrefix,
                'region' => $region,
            ];
        }

        return new JsonResponse([
            'buckets' => $buckets,
        ]);
    }
}
