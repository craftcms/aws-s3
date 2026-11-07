<?php

declare(strict_types=1);

use Aws\CloudFront\CloudFrontClient;
use Aws\CloudFront\Exception\CloudFrontException;
use Aws\CommandInterface;
use Aws\Result;
use CraftCms\Cms\Asset\Assets;
use CraftCms\Cms\Asset\Data\Volume;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Folders;
use CraftCms\Cms\Element\Elements;
use CraftCms\Cms\Support\Facades\Volumes;
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\Log;

beforeEach(function() {
    $this->objects = [];
    fakeS3Disk('cdn', ['bucket' => 'bucket', 'root' => 'site', 'cloudfront_distribution_id' => 'E1', 'cloudfront_path_prefix' => '/media/'], $this->objects);
    fakeS3Disk('cdn-unprefixed', ['bucket' => 'bucket', 'cloudfront_distribution_id' => 'E2'], $this->objects);
    fakeS3Disk('plain', ['bucket' => 'bucket'], $this->objects);

    foreach (['photos' => 'cdn', 'documents' => 'cdn-unprefixed', 'archive' => 'plain'] as $handle => $disk) {
        expect(Volumes::saveVolume(new Volume(['name' => ucfirst($handle), 'handle' => $handle, 'fs' => $disk, 'subpath' => $handle])))->toBeTrue();
    }

    $this->invalidations = [];
    $this->cloudFrontConfigs = [];
    $this->cloudFrontFails = false;

    app()->bind(CloudFrontClient::class, function($app, array $parameters) {
        $this->cloudFrontConfigs[] = $parameters['args'];

        return new CloudFrontClient([
            ...$parameters['args'],
            'handler' => function(CommandInterface $command) {
                if ($this->cloudFrontFails) {
                    return Create::rejectionFor(new CloudFrontException('Access denied', $command));
                }

                $this->invalidations[] = [
                    $command['DistributionId'],
                    $command['InvalidationBatch']['Paths']['Items'],
                ];

                return Create::promiseFor(new Result([]));
            },
        ]);
    });
});

function replaceFile(Asset $asset, string $filename): void
{
    $path = sys_get_temp_dir() . '/' . uniqid('replacement-') . '.jpg';
    imagejpeg(imagecreatetruecolor(20, 20), $path);
    app(Assets::class)->replaceAssetFile($asset, $path, $filename);
}

it('invalidates an asset’s old path when its file is replaced', function(string $filename) {
    $asset = uploadImage('photos', 'portrait.jpg');
    replaceFile($asset, $filename);

    expect($this->invalidations)->toBe([])
        ->and(app()->terminate())->toBeNull()
        ->and($this->invalidations)->toBe([['E1', ['/media/site/photos/portrait.jpg']]]);
})->with(['portrait.jpg', 'replacement.jpg']);

it('invalidates an asset’s old path when it’s moved or renamed', function() {
    $asset = uploadImage('photos', 'portrait.jpg');
    $folder = app(Folders::class)->getRootFolderByVolumeId($asset->volumeId);

    expect(app(Assets::class)->moveAsset($asset, $folder, 'renamed.jpg'))->toBeTrue()
        ->and($this->objects)->toHaveKey('bucket/site/photos/renamed.jpg');

    app()->terminate();

    expect($this->invalidations)->toBe([['E1', ['/media/site/photos/portrait.jpg']]]);
});

it('invalidates a deleted asset’s path once, when it’s trashed', function() {
    $asset = uploadImage('photos', 'portrait.jpg');

    app(Elements::class)->deleteElement($asset);
    app()->terminate();

    // Garbage collection hard-deletes it later, in another request
    app(Elements::class)->deleteElement(Asset::find()->id($asset->id)->trashed()->one(), true);
    app()->terminate();

    expect($this->invalidations)->toBe([['E1', ['/media/site/photos/portrait.jpg']]]);
});

it('doesn’t invalidate new files, or files on disks without a distribution', function() {
    uploadImage('photos', 'portrait.jpg');
    replaceFile(uploadImage('archive', 'portrait.jpg'), 'portrait.jpg');
    app()->terminate();

    expect($this->cloudFrontConfigs)->toBe([]);
});

it('invalidates each distribution’s paths together', function() {
    replaceFile(uploadImage('photos', 'one.jpg'), 'one.jpg');
    replaceFile(uploadImage('photos', 'two.jpg'), 'two.jpg');
    replaceFile(uploadImage('documents', 'three.jpg'), 'three.jpg');
    app()->terminate();

    expect($this->invalidations)->toBe([
        ['E1', ['/media/site/photos/one.jpg', '/media/site/photos/two.jpg']],
        ['E2', ['/documents/three.jpg']],
    ])->and($this->cloudFrontConfigs[0]['credentials'])->toBeInstanceOf(Closure::class);
});

it('invalidates paths when a queue job finishes', function() {
    $asset = uploadImage('photos', 'portrait.jpg');

    dispatch(fn() => app(Elements::class)->deleteElement($asset));

    expect($this->invalidations)->toBe([['E1', ['/media/site/photos/portrait.jpg']]]);
});

it('logs failed invalidations rather than failing the request', function() {
    $this->cloudFrontFails = true;
    Log::spy();

    replaceFile(uploadImage('photos', 'portrait.jpg'), 'portrait.jpg');
    app()->terminate();

    Log::shouldHaveReceived('warning')->withArgs(fn(string $message) => str_contains($message, 'E1'))->once();
});
