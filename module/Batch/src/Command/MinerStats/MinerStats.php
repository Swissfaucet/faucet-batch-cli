<?php

namespace Batch\Command\MinerStats;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MinerStats extends Command {
    /**
     * Miner Shares Table
     *
     * @var TableGateway $mSharesTbl
     * @since 1.0.0
     */
    protected TableGateway $mSharesTbl;

    /**
     * User Statistics Table
     *
     * @var TableGateway $mUserStatsTbl
     * @since 1.0.0
     */
    protected TableGateway $mUserStatsTbl;

    /**
     * Security Tools
     *
     * @var SecurityTools $mSecTools
     */
    protected SecurityTools $mSecTools;

    /**
     * Batch Tools
     */
    protected BatchTools $mBatchTools;

    /**
     * Constructor
     *
     * UserResource constructor.
     * @param $mapper
     * @since 1.0.0
     */
    public function __construct($mapper)
    {
        $this->mSharesTbl = new TableGateway('faucet_miner_payment', $mapper);
        $this->mUserStatsTbl = new TableGateway('user_faucet_stat', $mapper);

        $this->mSecTools = new SecurityTools($mapper);
        $this->mBatchTools = new BatchTools($mapper);

        // you *must* call the parent constructor
        parent::__construct();
    }

    /**
     * Run MinerStats Command
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // log start
        $output->writeln([
            '=====================',
            'Generating Miner Statistics @ ' . date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        $now = time();

        $sharesEtc = $this->calculateCoinStats($now, 'etc', $output);
        $output->writeln([
            '- Processed '.$sharesEtc.' etc shares',
        ]);
        $sharesRvn = $this->calculateCoinStats($now, 'rvn', $output);
        $output->writeln([
            '- Processed '.$sharesRvn.' rvn shares',
        ]);
        $sharesXmr = $this->calculateCoinStats($now, 'xmr', $output);
        $output->writeln([
            '- Processed '.$sharesXmr.' xmr shares',
        ]);

        $this->mSecTools->updateCoreSetting('job_mining_stats_lastrun', date('Y-m-d H:i:s', time()));

        $output->writeln([
            '-- Miner Statistics completed successfully',
            '=====================',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Calculate earned coins for a specific date and coin
     * @param $date
     * @param $coin
     * @return int
     */
    private function calculateCoinStats($date, $coin, $output) : int
    {
        // load shares
        $tWh = new Where();
        $tWh->like('coin', $coin);
        //$tWh->like('date', date('Y-m-d', $date).'%');
        $tWh->equalTo('stats_processed', 0);
        $tSel = new Select($this->mSharesTbl->getTable());
        $tSel->where($tWh);
        $tSel->order('date ASC');

        $sharesToday = $this->mSharesTbl->selectWith($tSel);

        // Count Entries
        $sharesCount = $sharesToday->count();

        // get coins earned by user
        $sharesByUserId = [];
        foreach($sharesToday as $share) {
            if(!array_key_exists('user-'.$share->user_idfs, $sharesByUserId)) {
                $sharesByUserId['user-'.$share->user_idfs] = 0;
            }
            $sharesByUserId['user-'.$share->user_idfs] += $share->amount_coin;
            $this->mSharesTbl->update(['stats_processed' => 1],['id' => $share->id]);
        }

        // update user stats (alltime)
        $key = 'user-nano-'.$coin.'-coin-total';
        $this->updateUserStatsByKey($key, $sharesByUserId);

        // update user stats (month)
        $key = 'user-nano-'.$coin.'-coin-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $sharesByUserId);

        // update user stats (week)
        $weekNo = $this->mBatchTools->getWeek($date);
        $key = 'user-nano-'.$coin.'-coin-w-'.$weekNo;
        $this->updateUserStatsByKey($key, $sharesByUserId, $weekNo, $output, $coin);

        return $sharesCount;
    }

    /**
     * Update Users stats based on provided data and key
     * @param $key
     * @param $sharesByUserId
     * @return void
     */
    private function updateUserStatsByKey($key, $sharesByUserId, $week = false, $output = false, $coin = false) : void
    {
        if($week) {
            $guildByUserId = $this->mBatchTools->loadUsersInGuilds();
        }

        $tasksByGuild = [];
        foreach(array_keys($sharesByUserId) as $userIdStr) {
            $userId = substr($userIdStr, strlen('user-'));
            if(is_numeric($userId) && $userId > 0 && !empty($userId)) {
                $now = date('Y-m-d H:i:s', time());

                $check = $this->mUserStatsTbl->select(['user_idfs' => $userId, 'stat_key' => $key]);
                if($check->count() == 0) {
                    // start of a new month
                    $this->mUserStatsTbl->insert([
                        'user_idfs' => $userId,
                        'stat_key' => $key,
                        'stat_data' => $sharesByUserId[$userIdStr],
                        'date' => $now
                    ]);
                } else {
                    $currentVal = $check->current()->stat_data;
                    $this->mUserStatsTbl->update([
                        'stat_data' => $currentVal+$sharesByUserId[$userIdStr],
                        'date' => $now
                    ],['user_idfs' => $userId, 'stat_key' => $key]);
                }

                if($week) {
                    // Add Weekly Value to Guild
                    if(array_key_exists('user-'.$userId,$guildByUserId)) {
                        $guildId = $guildByUserId['user-'.$userId];
                        if(!array_key_exists('guild-'.$guildId, $tasksByGuild)) {
                            $tasksByGuild['guild-'.$guildId] = 0;
                        }
                        $tasksByGuild['guild-'.$guildId]+=$sharesByUserId[$userIdStr];
                    }
                }
            }
        }

        // Update Guild Weekly Stats
        if($week) {
            switch($coin) {
                case 'xmr':
                    $wkUpdate = $this->mBatchTools->updateGuildWeeklyStats($tasksByGuild, $week, 'cpucoins', $output);
                    break;
                case 'etc':
                case 'rvn':
                    $wkUpdate = $this->mBatchTools->updateGuildWeeklyStats($tasksByGuild, $week, 'gpucoins', $output);
                    break;
                default:
                    break;
            }
        }
    }
}
