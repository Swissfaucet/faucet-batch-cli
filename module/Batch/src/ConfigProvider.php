<?php

namespace Batch;

use Batch\Command\CheckPtcPayments\CheckPtcPayments;
use Batch\Command\CheckPtcPayments\CheckPtcPaymentsFactory;
use Batch\Command\DailyTaskStats\DailyTaskStats;
use Batch\Command\DailyTaskStats\DailyTaskStatsFactory;
use Batch\Command\FaucetClaimStats\FaucetClaimStats;
use Batch\Command\FaucetClaimStats\FaucetClaimStatsFactory;
use Batch\Command\MinerPayments\MinerPayments;
use Batch\Command\MinerPayments\MinerPaymentsFactory;
use Batch\Command\MinerShares\MinerShares;
use Batch\Command\MinerShares\MinerSharesFactory;
use Batch\Command\MinerStats\MinerStats;
use Batch\Command\MinerStats\MinerStatsFactory;
use Batch\Command\OfferwallStats\OfferwallStats;
use Batch\Command\OfferwallStats\OfferwallStatsFactory;
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
                'batch:miner-payments' => MinerPayments::class,
                'batch:miner-stats' => MinerStats::class,
                'batch:offerwall-stats' => OfferwallStats::class,
                'batch:daily-task-stats' => DailyTaskStats::class,
                'batch:check-ptc-payments' => CheckPtcPayments::class,
                'batch:faucet-claim-stats' => FaucetClaimStats::class
            ],
        ];
    }

    public function getDependencyConfig() : array
    {
        return [
            'factories' => [
                UserOnlineCheck::class => UserOnlineCheckFactory::class,
                MinerShares::class => MinerSharesFactory::class,
                MinerPayments::class => MinerPaymentsFactory::class,
                MinerStats::class => MinerStatsFactory::class,
                OfferwallStats::class => OfferwallStatsFactory::class,
                DailyTaskStats::class => DailyTaskStatsFactory::class,
                CheckPtcPayments::class => CheckPtcPaymentsFactory::class,
                FaucetClaimStats::class => FaucetClaimStatsFactory::class
            ],
        ];
    }
}