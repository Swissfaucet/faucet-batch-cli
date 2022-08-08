<?php

namespace Batch;

use Batch\Command\CheckPtcPayments\CheckPtcPayments;
use Batch\Command\CheckPtcPayments\CheckPtcPaymentsFactory;
use Batch\Command\DailyTaskStats\DailyTaskStats;
use Batch\Command\DailyTaskStats\DailyTaskStatsFactory;
use Batch\Command\FaucetClaimStats\FaucetClaimStats;
use Batch\Command\FaucetClaimStats\FaucetClaimStatsFactory;
use Batch\Command\GuildWeeklyCheck\GuildWeeklyCheck;
use Batch\Command\GuildWeeklyCheck\GuildWeeklyCheckFactory;
use Batch\Command\MinerPayments\MinerPayments;
use Batch\Command\MinerPayments\MinerPaymentsFactory;
use Batch\Command\MinerShares\MinerShares;
use Batch\Command\MinerShares\MinerSharesFactory;
use Batch\Command\MinerStats\MinerStats;
use Batch\Command\MinerStats\MinerStatsFactory;
use Batch\Command\OfferwallStats\OfferwallStats;
use Batch\Command\OfferwallStats\OfferwallStatsFactory;
use Batch\Command\ShortlinkStats\ShortlinkStats;
use Batch\Command\ShortlinkStats\ShortlinkStatsFactory;
use Batch\Command\TransactionStats\TransactionStats;
use Batch\Command\TransactionStats\TransactionStatsFactory;
use Batch\Command\UserAccountStats\UserAccountStats;
use Batch\Command\UserAccountStats\UserAccountStatsFactory;
use Batch\Command\UserDailyStats\UserDailyStats;
use Batch\Command\UserDailyStats\UserDailyStatsFactory;
use Batch\Command\UserOnlineCheck\UserOnlineCheck;
use Batch\Command\UserOnlineCheck\UserOnlineCheckFactory;
use Batch\Command\WithdrawStats\WithdrawStats;
use Batch\Command\WithdrawStats\WithdrawStatsFactory;
use Batch\Command\WthCurrencyStats\WthCurrencyStats;
use Batch\Command\WthCurrencyStats\WthCurrencyStatsFactory;

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
                'batch:faucet-claim-stats' => FaucetClaimStats::class,
                'batch:guild-weekly-check' => GuildWeeklyCheck::class,
                'batch:shortlink-stats' => ShortlinkStats::class,
                'batch:user-account-stats' => UserAccountStats::class,
                'batch:user-daily-stats' => UserDailyStats::class,
                'batch:withdraw-stats' => WithdrawStats::class,
                'batch:withdraw-currency-stats' => WthCurrencyStats::class,
                'batch:transaction-stats' => TransactionStats::class
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
                FaucetClaimStats::class => FaucetClaimStatsFactory::class,
                GuildWeeklyCheck::class => GuildWeeklyCheckFactory::class,
                ShortlinkStats::class => ShortlinkStatsFactory::class,
                UserAccountStats::class => UserAccountStatsFactory::class,
                UserDailyStats::class => UserDailyStatsFactory::class,
                WithdrawStats::class => WithdrawStatsFactory::class,
                WthCurrencyStats::class => WthCurrencyStatsFactory::class,
                TransactionStats::class => TransactionStatsFactory::class
            ],
        ];
    }
}