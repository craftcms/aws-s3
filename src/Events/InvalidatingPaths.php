<?php

namespace CraftCms\AwsS3\Events;

class InvalidatingPaths
{
    public function __construct(
        public array $paths,
    ) {}
}
