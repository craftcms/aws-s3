<?php

namespace CraftCms\AwsS3\Assets;

use CraftCms\AwsS3\Plugin;
use CraftCms\Cms\View\HtmlStack;
use CraftCms\Cms\View\LegacyAssets\CpAsset;
use CraftCms\Cms\View\LegacyAssets\LegacyAssetInterface;

class AwsS3Bundle implements LegacyAssetInterface
{
    public array $depends = [
        CpAsset::class,
    ];

    public function register(HtmlStack $htmlStack): void
    {
        $path = Plugin::getInstance()->getPublishablePath('js/edit-fs.js');

        $htmlStack->jsFile(asset($path));
    }
}
