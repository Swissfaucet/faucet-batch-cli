<?php

namespace Batch;

class Module
{
    public function getConfig() : array
    {
        $configProvider = new ConfigProvider();

        return [
            'laminas-cli' => $configProvider->getCliConfig(),
            'service_manager' => $configProvider->getDependencyConfig(),
        ];
    }
}