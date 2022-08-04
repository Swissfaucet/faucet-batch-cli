<?php

namespace Batch\Command\ShortlinkStats;

class ShortlinkStatsFactory {
    public function __invoke($controllers): ShortlinkStats
    {
        return new ShortlinkStats($controllers->get('faucetdev'));
    }
}