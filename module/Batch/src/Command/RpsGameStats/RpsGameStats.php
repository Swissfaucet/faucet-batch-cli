<?php

namespace Batch\Command\RpsGameStats;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RpsGameStats extends Command {
    /**
     * Dailytask User Table
     *
     * @var TableGateway $mGameTbl
     * @since 1.0.0
     */
    protected TableGateway $mGameTbl;

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
        $this->mGameTbl = new TableGateway('faucet_game_match', $mapper);
        $this->mUserStatsTbl = new TableGateway('user_faucet_stat', $mapper);

        $this->mSecTools = new SecurityTools($mapper);
        $this->mBatchTools = new BatchTools($mapper);

        // you *must* call the parent constructor
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // log start
        $output->writeln([
            '=====================',
            'Generating RPS Game Statistics @ ' . date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        $lastDate = $this->mSecTools->getCoreSetting('job_rps_stats_date');
        $lastrun = $this->mSecTools->getCoreSetting('job_rps_stats_lastrun');

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

        $output->writeln([
            'Date to process: '.$stop_date->format('Y-m-d')
        ]);

        $tasksDone = $this->generateRpsGameStats($statDate);
        $output->writeln([
            '- Processed '.$tasksDone.' faucet claims',
        ]);

        $this->mSecTools->updateCoreSetting('job_rps_stats_date', date('Y-m-d', $statDate));
        $this->mSecTools->updateCoreSetting('job_rps_stats_lastrun', date('Y-m-d H:i:s', time()));

        $output->writeln([
            '-- RPS Game Statistics completed successfully',
            '=====================',
        ]);

        return Command::SUCCESS;
    }

    private function generateRpsGameStats($date) {
        // load shares
        $tWh = new Where();
        $tWh->like('date_finished', date('Y-m-d', $date).'%');
        $tSel = new Select($this->mGameTbl->getTable());
        $tSel->where($tWh);
        //  $tSel->order('date ASC');

        $gamesToday = $this->mGameTbl->selectWith($tSel);

        // Count Entries
        $gamesCount = 0;

        // get claims done by user
        $gamesByUserId = [];
        foreach($gamesToday as $game) {
            $gamesCount++;
            if(!array_key_exists('user-'.$game->host_user_idfs, $gamesByUserId)) {
                $gamesByUserId['user-'.$game->host_user_idfs] = 0;
            }
            $gamesByUserId['user-'.$game->host_user_idfs]++;
            if(!array_key_exists('user-'.$game->client_user_idfs, $gamesByUserId)) {
                $gamesByUserId['user-'.$game->client_user_idfs] = 0;
            }
            $gamesByUserId['user-'.$game->client_user_idfs]++;
        }

        // update user stats (alltime)
        $key = 'user-rps-game-total';
        $this->updateUserStatsByKey($key, $gamesByUserId);

        // update user stats (month)
        $key = 'user-rps-game-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $gamesByUserId);

        $key = 'faucet-rps-game-total';
        $this->mBatchTools->updateCoreStatsByKey($key, $gamesCount);

        return $gamesCount;
    }

    private function updateUserStatsByKey($key, $tasksByUserId) : void
    {
        foreach(array_keys($tasksByUserId) as $userIdStr) {
            $userId = substr($userIdStr, strlen('user-'));
            if(is_numeric($userId) && $userId > 0 && !empty($userId)) {
                $now = date('Y-m-d H:i:s', time());

                $check = $this->mUserStatsTbl->select(['user_idfs' => $userId, 'stat_key' => $key]);
                if($check->count() == 0) {
                    // start of a new month
                    $this->mUserStatsTbl->insert([
                        'user_idfs' => $userId,
                        'stat_key' => $key,
                        'stat_data' => $tasksByUserId[$userIdStr],
                        'date' => $now
                    ]);
                } else {
                    $currentVal = $check->current()->stat_data;
                    $this->mUserStatsTbl->update([
                        'stat_data' => $currentVal+$tasksByUserId[$userIdStr],
                        'date' => $now
                    ],['user_idfs' => $userId, 'stat_key' => $key]);
                }
            }
        }
    }
}