<?php

namespace Batch\Command\UnlockShipGames;

class UnlockShipGamesFactory {
    public function __invoke($controllers): UnlockShipGames
    {
        return new UnlockShipGames($controllers->get('faucetdev'));
    }
}
