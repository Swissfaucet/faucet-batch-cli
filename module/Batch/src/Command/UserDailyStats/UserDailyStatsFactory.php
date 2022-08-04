<?php

namespace Batch\Command\UserDailyStats;

class UserDailyStatsFactory
{
    public function __invoke($controllers)
    {
        return new UserDailyStats($controllers->get('faucetdev'));
    }
}