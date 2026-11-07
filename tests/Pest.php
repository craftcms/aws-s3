<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use CraftCms\AwsS3\Tests\TestCase;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Folders;
use CraftCms\Cms\Asset\Validation\AssetRules;
use CraftCms\Cms\Element\Elements;
use CraftCms\Cms\Support\Facades\Volumes;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

uses(TestCase::class)->in('.');

/**
 * Configures an S3 disk backed by an in-memory bucket, which keeps the objects written to it in `$objects`, indexed
 * by bucket and key.
 *
 * @param  array<string, mixed>  $config
 * @param  array<string, string>  $objects
 */
function fakeS3Disk(string $name, array $config, array &$objects): void
{
    config()->set("filesystems.disks.$name", [
        'driver' => 's3',
        'key' => 'key',
        'secret' => 'secret',
        'region' => 'eu-west-1',
        ...$config,
        'handler' => function(CommandInterface $command) use (&$objects) {
            $id = $command['Bucket'] . '/' . $command['Key'];
            $missing = fn() => Create::rejectionFor(new S3Exception('Not found', $command, ['code' => 'NotFound', 'response' => new Response(404)]));

            switch ($command->getName()) {
                case 'PutObject':
                    $objects[$id] = (string) Utils::streamFor($command['Body']);
                    break;
                case 'CopyObject':
                    $objects[$id] = $objects[rawurldecode(ltrim($command['CopySource'], '/'))];
                    break;
                case 'DeleteObject':
                    unset($objects[$id]);
                    break;
            }

            return match ($command->getName()) {
                'HeadObject' => isset($objects[$id]) ? Create::promiseFor(new Result(['ContentLength' => strlen($objects[$id])])) : $missing(),
                'GetObject' => isset($objects[$id]) ? Create::promiseFor(new Result(['Body' => Utils::streamFor($objects[$id])])) : $missing(),
                default => Create::promiseFor(new Result([])),
            };
        },
    ]);
}

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
