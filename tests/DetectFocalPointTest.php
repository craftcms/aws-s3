<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\Rekognition\RekognitionClient;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use CraftCms\AwsS3\Plugin;
use CraftCms\Cms\Asset\Data\Volume;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Folders;
use CraftCms\Cms\Asset\Validation\AssetRules;
use CraftCms\Cms\Element\Elements;
use CraftCms\Cms\Support\Facades\Volumes;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;

beforeEach(function() {
    config()->set('filesystems.disks.photos-s3', [
        'driver' => 's3',
        'key' => 'key',
        'secret' => 'secret',
        'region' => 'eu-west-1',
        'bucket' => 'photo-bucket',
        'root' => 'site',
        // Accept every write, and report every object as missing so new files don't conflict with anything
        'handler' => fn(CommandInterface $command) => $command->getName() === 'HeadObject'
            ? Create::rejectionFor(new S3Exception('Not found', $command, ['code' => 'NotFound', 'response' => new Response(404)]))
            : Create::promiseFor(new Result([])),
    ]);
    config()->set('filesystems.disks.photos-local', [
        'driver' => 'local',
        'root' => $this->localRoot = sys_get_temp_dir() . '/' . uniqid('aws-s3-test-'),
    ]);

    foreach ([['photos', 'photos-s3', 'library'], ['archive', 'photos-s3', 'archive'], ['local', 'photos-local', 'library']] as [$handle, $disk, $subpath]) {
        expect(Volumes::saveVolume(new Volume(['name' => ucfirst($handle), 'handle' => $handle, 'fs' => $disk, 'subpath' => $subpath])))->toBeTrue();
    }

    Plugin::getInstance()->setSettings(['volumes' => ['photos', 'local']]);

    $this->rekognitionRequests = [];
    $this->faces = [
        ['Confidence' => 60.0, 'BoundingBox' => ['Left' => 0.0, 'Top' => 0.0, 'Width' => 0.1, 'Height' => 0.1]],
        ['Confidence' => 95.0, 'BoundingBox' => ['Left' => 0.6, 'Top' => 0.2, 'Width' => 0.2, 'Height' => 0.2]],
    ];

    app()->bind(RekognitionClient::class, fn($app, array $parameters) => new RekognitionClient([
        ...$parameters['args'],
        'handler' => function(CommandInterface $command) {
            $this->rekognitionRequests[] = [$command->getName(), $command->toArray()];

            return Create::promiseFor(new Result(['FaceDetails' => $this->faces]));
        },
    ]));
});

afterEach(function() {
    File::deleteDirectory($this->localRoot);
});

function uploadImage(string $volumeHandle, string $filename, ?string $focalPoint = null): Asset
{
    $volume = Volumes::getVolumeByHandle($volumeHandle);
    $image = imagecreatetruecolor(40, 30);
    $path = sys_get_temp_dir() . '/' . uniqid('upload-') . '-' . $filename;

    match (pathinfo($filename, PATHINFO_EXTENSION)) {
        'gif' => imagegif($image, $path),
        'png' => imagepng($image, $path),
        default => imagejpeg($image, $path),
    };

    $asset = new Asset();
    $asset->tempFilePath = $path;
    $asset->setFilename($filename);
    $asset->newFolderId = app(Folders::class)->getRootFolderByVolumeId($volume->id)->id;
    $asset->setVolumeId($volume->id);
    $asset->setFocalPoint($focalPoint);
    $asset->ruleset->useScenario(AssetRules::SCENARIO_CREATE);

    expect(app(Elements::class)->saveElement($asset))->toBeTrue();

    return Asset::find()->id($asset->id)->one();
}

it('sets a new image’s focal point to the center of the first confident face', function(string $filename) {
    $asset = uploadImage('photos', $filename);

    expect($asset->getHasFocalPoint())->toBeTrue()
        ->and($asset->getFocalPoint())->toEqualWithDelta(['x' => 0.7, 'y' => 0.3], 0.0001)
        ->and($this->rekognitionRequests)->toHaveCount(1)
        ->and($this->rekognitionRequests[0][0])->toBe('DetectFaces')
        ->and($this->rekognitionRequests[0][1]['Image']['S3Object'])->toBe([
            'Bucket' => 'photo-bucket',
            'Name' => "site/library/$filename",
        ]);
})->with(['portrait.jpg', 'portrait.PNG']);

it('leaves the focal point unset when no face is confident enough', function() {
    $this->faces = [
        ['Confidence' => 79.9, 'BoundingBox' => ['Left' => 0.6, 'Top' => 0.2, 'Width' => 0.2, 'Height' => 0.2]],
    ];

    $asset = uploadImage('photos', 'crowd.jpg');

    expect($this->rekognitionRequests)->toHaveCount(1)
        ->and($asset->getHasFocalPoint())->toBeFalse();
});

it('doesn’t ask Rekognition about images it can’t or shouldn’t analyze', function(string $volume, string $filename, ?string $focalPoint) {
    $asset = uploadImage($volume, $filename, $focalPoint);

    expect($this->rekognitionRequests)->toBe([])
        ->and($asset->getFocalPoint())->toBe($focalPoint === null ? ['x' => 0.5, 'y' => 0.5] : ['x' => 0.2, 'y' => 0.4]);
})->with([
    'a volume that isn’t selected' => ['archive', 'portrait.jpg', null],
    'a volume on a local disk' => ['local', 'portrait.jpg', null],
    'a file type Rekognition can’t read' => ['photos', 'portrait.gif', null],
    'an image with a focal point' => ['photos', 'portrait.jpg', '0.2;0.4'],
]);

it('analyzes images in every volume when all volumes are selected', function(string|array $volumes) {
    Plugin::getInstance()->setSettings(['volumes' => $volumes]);

    expect(uploadImage('archive', 'portrait.jpg')->getHasFocalPoint())->toBeTrue();
})->with([
    'in a config file' => '*',
    'in the control panel' => [['*']],
]);
