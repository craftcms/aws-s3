<?php

namespace CraftCms\AwsS3\Enums;

use function CraftCms\Cms\t;

enum BucketSelectionMode: string
{
    case Choose = 'choose';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            BucketSelectionMode::Choose => t('Choose'),
            BucketSelectionMode::Manual => t('Manual'),
        };
    }
}
