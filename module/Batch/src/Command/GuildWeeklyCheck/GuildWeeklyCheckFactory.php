<?php

namespace Batch\Command\GuildWeeklyCheck;

class GuildWeeklyCheckFactory {
    public function __invoke($controllers): GuildWeeklyCheck
    {
        return new GuildWeeklyCheck($controllers->get('faucetdev'));
    }
}
