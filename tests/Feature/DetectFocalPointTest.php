<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\Rekognition\RekognitionClient;
use Aws\Result;
use CraftCms\AwsS3\Plugin;
use CraftCms\Cms\Asset\Data\Volume;
use CraftCms\Cms\Support\Facades\Volumes;
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->objects = [];
    fakeS3Disk('photos-s3', ['bucket' => 'photo-bucket', 'root' => 'site'], $this->objects);
    fakeS3Disk('photos-compatible', [
        'key' => 'minio-key',
        'secret' => 'minio-secret',
        'region' => 'auto',
        'bucket' => 'minio-bucket',
        'endpoint' => 'https://minio.example.test',
        'use_path_style_endpoint' => true,
    ], $this->objects);
    config()->set('filesystems.disks.photos-local', [
        'driver' => 'local',
        'root' => $this->localRoot = sys_get_temp_dir().'/'.uniqid('aws-s3-test-'),
    ]);

    foreach ([['photos', 'photos-s3', 'library'], ['archive', 'photos-s3', 'archive'], ['local', 'photos-local', 'library'], ['compatible', 'photos-compatible', 'library']] as [$handle, $disk, $subpath]) {
        expect(Volumes::saveVolume(new Volume(['name' => ucfirst($handle), 'handle' => $handle, 'fs' => $disk, 'subpath' => $subpath])))->toBeTrue();
    }

    Plugin::getInstance()->setSettings(['volumes' => ['photos', 'local', 'compatible']]);

    $this->rekognitionRequests = [];
    $this->rekognitionConfigs = [];
    $this->faces = [
        ['Confidence' => 60.0, 'BoundingBox' => ['Left' => 0.0, 'Top' => 0.0, 'Width' => 0.1, 'Height' => 0.1]],
        ['Confidence' => 95.0, 'BoundingBox' => ['Left' => 0.6, 'Top' => 0.2, 'Width' => 0.2, 'Height' => 0.2]],
    ];

    app()->bind(RekognitionClient::class, function ($app, array $parameters) {
        $this->rekognitionConfigs[] = $parameters['args'];

        return new RekognitionClient([
            ...$parameters['args'],
            'handler' => function (CommandInterface $command) {
                $this->rekognitionRequests[] = [$command->getName(), $command->toArray()];

                return Create::promiseFor(new Result(['FaceDetails' => $this->faces]));
            },
        ]);
    });
});

afterEach(function () {
    File::deleteDirectory($this->localRoot);
});

it('sets a new image’s focal point to the center of the first confident face', function (string $filename) {
    $asset = uploadImage('photos', $filename);

    expect($asset->getHasFocalPoint())->toBeTrue()
        ->and($asset->getFocalPoint())->toEqualWithDelta(['x' => 0.7, 'y' => 0.3], 0.0001)
        ->and($this->rekognitionRequests)->toHaveCount(1)
        ->and($this->rekognitionRequests[0][0])->toBe('DetectFaces')
        ->and($this->rekognitionConfigs[0]['region'])->toBe('eu-west-1')
        ->and($this->rekognitionRequests[0][1]['Image']['S3Object'])->toBe([
            'Bucket' => 'photo-bucket',
            'Name' => "site/library/$filename",
        ]);
})->with(['portrait.jpg', 'portrait.PNG']);

it('leaves the focal point unset when no face is confident enough', function () {
    $this->faces = [
        ['Confidence' => 79.9, 'BoundingBox' => ['Left' => 0.6, 'Top' => 0.2, 'Width' => 0.2, 'Height' => 0.2]],
    ];

    $asset = uploadImage('photos', 'crowd.jpg');

    expect($this->rekognitionRequests)->toHaveCount(1)
        ->and($asset->getHasFocalPoint())->toBeFalse();
});

it('doesn’t ask Rekognition about images it can’t or shouldn’t analyze', function (string $volume, string $filename, ?string $focalPoint) {
    $asset = uploadImage($volume, $filename, $focalPoint);

    expect($this->rekognitionRequests)->toBe([])
        ->and($asset->getFocalPoint())->toBe($focalPoint === null ? ['x' => 0.5, 'y' => 0.5] : ['x' => 0.2, 'y' => 0.4]);
})->with([
    'a volume that isn’t selected' => ['archive', 'portrait.jpg', null],
    'a volume on a local disk' => ['local', 'portrait.jpg', null],
    'a file type Rekognition can’t read' => ['photos', 'portrait.gif', null],
    'an image with a focal point' => ['photos', 'portrait.jpg', '0.2;0.4'],
]);

it('analyzes images in every volume when all volumes are selected', function (string|array $volumes) {
    Plugin::getInstance()->setSettings(['volumes' => $volumes]);

    expect(uploadImage('archive', 'portrait.jpg')->getHasFocalPoint())->toBeTrue();
})->with([
    'in a config file' => '*',
    'in the control panel' => [['*']],
]);

it('sends images on S3-compatible services to Rekognition in the region from the settings', function () {
    Plugin::getInstance()->setSettings(['region' => 'us-east-1']);
    putenv('AWS_ACCESS_KEY_ID=env-key');
    putenv('AWS_SECRET_ACCESS_KEY=env-secret');

    try {
        $asset = uploadImage('compatible', 'portrait.jpg');
    } finally {
        putenv('AWS_ACCESS_KEY_ID');
        putenv('AWS_SECRET_ACCESS_KEY');
    }

    expect($asset->getFocalPoint())->toEqualWithDelta(['x' => 0.7, 'y' => 0.3], 0.0001)
        ->and($this->rekognitionConfigs[0]['region'])->toBe('us-east-1')
        // The disk's credentials belong to the other service, so the SDK's default credentials are used
        ->and($this->rekognitionConfigs[0])->not->toHaveKey('credentials')
        ->and($this->rekognitionRequests[0][1]['Image'])->toBe(['Bytes' => $this->objects['minio-bucket/library/portrait.jpg']]);
});

it('skips images on S3-compatible services when no region is set', function () {
    $asset = uploadImage('compatible', 'portrait.jpg');

    expect($this->rekognitionRequests)->toBe([])
        ->and($asset->getHasFocalPoint())->toBeFalse();
});

it('sends images to Rekognition when it can’t read them from the bucket', function (array $settings, string $region, mixed $credentials) {
    Plugin::getInstance()->setSettings($settings);

    uploadImage('photos', 'portrait.jpg');

    $config = $this->rekognitionConfigs[0];

    expect($config['region'])->toBe($region)
        ->and($config['credentials'] instanceof Closure ? 'disk' : $config['credentials'])->toBe($credentials)
        ->and($this->rekognitionRequests[0][1]['Image'])->toBe(['Bytes' => $this->objects['photo-bucket/site/library/portrait.jpg']]);
})->with([
    'with other credentials' => [['keyId' => 'rekognition-key', 'secret' => 'rekognition-secret'], 'eu-west-1', ['key' => 'rekognition-key', 'secret' => 'rekognition-secret']],
    'in another region' => [['region' => 'us-east-1'], 'us-east-1', 'disk'],
]);
