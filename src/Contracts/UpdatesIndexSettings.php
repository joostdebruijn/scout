<?php

namespace Laravel\Scout\Contracts;

interface UpdatesIndexSettings
{
    public function updateIndexSettings(string $name, array $options = []);

    public function configureSoftDeleteFilter(array $settings = []): array;
}
