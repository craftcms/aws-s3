<?php

/**
 * Amazon S3 settings. Settings set here override the plugin’s settings in the control panel, so
 * uncomment only the ones you want to manage in code.
 *
 * Publish this file to `config/craft/aws-s3.php` with
 * `php artisan vendor:publish --tag=aws-s3`.
 *
 * CloudFront distributions are set on each disk in `config/filesystems.php`, with the
 * `cloudfront_distribution_id` and `cloudfront_path_prefix` keys.
 */

return [
    // Handles of the volumes whose new images get focal points from faces Amazon Rekognition
    // finds in them, or '*' for all volumes
    // 'volumes' => ['photos'],

    // Credentials to call Rekognition and CloudFront with. Leave them unset to use each disk’s
    // own credentials, or the AWS SDK’s default credentials for disks on S3-compatible services.
    // 'keyId' => env('AWS_REKOGNITION_ACCESS_KEY_ID'),
    // 'secret' => env('AWS_REKOGNITION_SECRET_ACCESS_KEY'),

    // The region to call Rekognition in. Leave it unset to use each disk’s region. Disks on
    // S3-compatible services need one.
    // 'region' => env('AWS_REKOGNITION_REGION'),
];
