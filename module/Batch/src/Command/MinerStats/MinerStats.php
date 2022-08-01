<?php

namespace Batch\Command\MinerStats;

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

        $lastDate = $this->mSecTools->getCoreSetting('job_mining_stats_date');
        $lastrun = $this->mSecTools->getCoreSetting('job_mining_stats_lastrun');

        // only run batch once per day
        if(date('Y-m-d', strtotime($lastrun)) != date('Y-m-d', time())) {
            try {
                $stop_date = new \DateTime($lastDate);
                $stop_date->modify('+1 day');
                $statDate = strtotime($stop_date->format('Y-m-d H:i:s'));
            } catch (\Exception $e) {
                $output->writeln([
                    '## ERROR - COULD NOT INITIALIZE DATE',
                ]);
                return Command::SUCCESS;
            }
        } else {
            $output->writeln([
                '## ERROR - BATCH HAS ALREADY RUN TODAY',
            ]);
            return Command::SUCCESS;
        }


        $sharesEtc = $this->calculateCoinStats($statDate, 'etc');
        $output->writeln([
            '- Processed '.$sharesEtc.' etc shares',
        ]);
        $sharesRvn = $this->calculateCoinStats($statDate, 'rvn');
        $output->writeln([
            '- Processed '.$sharesRvn.' rvn shares',
        ]);
        $sharesXmr = $this->calculateCoinStats($statDate, 'xmr');
        $output->writeln([
            '- Processed '.$sharesXmr.' xmr shares',
        ]);

        $this->mSecTools->updateCoreSetting('job_mining_stats_date', date('Y-m-d', $statDate));
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
    private function calculateCoinStats($date, $coin) : int
    {
        // load shares
        $tWh = new Where();
        $tWh->like('coin', $coin);
        $tWh->like('date', date('Y-m-d', $date).'%');
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
        }

        // update user stats (alltime)
        $key = 'user-nano-'.$coin.'-coin-total';
        $this->updateUserStatsByKey($key, $sharesByUserId);

        // update user stats (month)
        $key = 'user-nano-'.$coin.'-coin-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $sharesByUserId);

        // update user stats (week)
        $week = date('W-Y', $date);
        $weekDay = date('w', $date);
        $weekCheck = date('W', $date);
        $monthCheck = date('n', $date);
        // fix php bug - wrong iso week for first week of the year
        //$dev = 1;
        $yearFixApplied = false;
        if($monthCheck == 1 && ($weekCheck > 10 || $weekCheck == 1)) {
            // last week of last year is extended to tuesday as our week begins at wednesday
            if($weekDay != 3 && $weekDay != 4 && $weekDay != 5) {
                //$dev = 5;
                try {
                    $stop_date = new \DateTime(date('Y-m-d', $date));
                    $stop_date->modify('-5 days');
                    $statDate = strtotime($stop_date->format('Y-m-d H:i:s'));

                    $week = date('W-Y', $statDate);
                    $yearFixApplied = true;
                } catch(\Exception $e) {

                }
            }
        }
        // dont mess with fixed date from year change
        if(!$yearFixApplied) {
            //$dev = 3;
            // monday and tuesday are counted to last weeks iso week
            if($weekDay == 1 || $weekDay == 2) {
                //$dev = 4;
                $week = ($weekCheck-1).'-'.date('Y', $date);
            }
        }
        $key = 'user-nano-'.$coin.'-coin-w-'.$week;
        $this->updateUserStatsByKey($key, $sharesByUserId);

        return $sharesCount;
    }

    /**
     * Update Users stats based on provided data and key
     * @param $key
     * @param $sharesByUserId
     * @return void
     */
    private function updateUserStatsByKey($key, $sharesByUserId) : void
    {
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
            }
        }
    }
}
