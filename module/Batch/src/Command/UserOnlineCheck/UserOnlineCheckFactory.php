<?php

namespace Batch\Command\UserOnlineCheck;

class UserOnlineCheckFactory
{
    public function __invoke($controllers)
    {
        return new UserOnlineCheck($controllers->get('faucetdev'));
    }
}