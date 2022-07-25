<?php

namespace Batch;

use Batch\Command\MinerShares\MinerShares;
use Batch\Command\MinerShares\MinerSharesFactory;
use Batch\Command\UserOnlineCheck\UserOnlineCheck;
use Batch\Command\UserOnlineCheck\UserOnlineCheckFactory;

class ConfigProvider
{
    public function __invoke() : array
    {
        return [
            'laminas-cli' => $this->getCliConfig(),
            'dependencies' => $this->getDependencyConfig(),
        ];
    }

    public function getCliConfig() : array
    {
        return [
            'commands' => [
                'batch:user-online-check' => UserOnlineCheck::class,
                'batch:miner-shares' => MinerShares::class,
            ],
        ];
    }

    public function getDependencyConfig() : array
    {
        return [
            'factories' => [
                UserOnlineCheck::class => UserOnlineCheckFactory::class,
                MinerShares::class => MinerSharesFactory::class
            ],
        ];
    }
}