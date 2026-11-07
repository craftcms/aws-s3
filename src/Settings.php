<?php

declare(strict_types=1);

namespace CraftCms\AwsS3;

use CraftCms\Cms\Asset\Data\Volume;
use CraftCms\Cms\Plugin\PluginSettings;
use CraftCms\Cms\Ui\Controls\Choice;

class Settings extends PluginSettings
{
    /**
     * @var string|list<string> Handles of the volumes whose new images get focal points from detected faces. `*`, or a
     * list containing it, means all volumes, including ones added later.
     */
    public string|array $volumes = [];

    public function detectsFacesIn(Volume $volume): bool
    {
        $volumes = (array) $this->volumes;

        return in_array(Choice::ALL_VALUE, $volumes, true) || in_array($volume->handle, $volumes, true);
    }
}
