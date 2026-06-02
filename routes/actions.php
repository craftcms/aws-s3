<?php

use CraftCms\AwsS3\Http\Controllers\ListBucketsController;
use CraftCms\Cms\Http\Middleware\RequireAdmin;

Route::middleware([
    'auth:craft',
    RequireAdmin::class,
])->group(function () {
    Route::post('buckets/load-bucket-data', ListBucketsController::class);
});
