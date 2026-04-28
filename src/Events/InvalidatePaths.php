<?php

namespace CraftCms\AwsS3\Events;

class InvalidatePaths
{
    public function __construct(
        public array $paths,
    ) {}
}
