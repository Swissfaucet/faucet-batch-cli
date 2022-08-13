<?php

namespace Batch\Command\RpsGameStats;

class RpsGameStatsFactory {
    public function __invoke($controllers): RpsGameStats
    {
        return new RpsGameStats($controllers->get('faucetdev'));
    }
}
