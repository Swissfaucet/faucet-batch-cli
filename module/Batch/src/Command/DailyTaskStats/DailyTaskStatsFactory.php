<?php

namespace Batch\Command\DailyTaskStats;

class DailyTaskStatsFactory {
    public function __invoke($controllers): DailyTaskStats
    {
        return new DailyTaskStats($controllers->get('faucetdev'));
    }
}