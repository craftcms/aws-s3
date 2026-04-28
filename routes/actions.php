<?php

use CraftCms\AwsS3\Http\Controllers\ListBucketsController;
use CraftCms\Cms\Http\Middleware\RequireAdmin;

Route::middleware([
    'auth:craft',
    RequireAdmin::class
])->group(function() {
    Route::post('aws-s3/buckets/load-bucket-data', ListBucketsController::class);
});
