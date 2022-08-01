<?php

namespace Batch\Command\DailyTaskStats;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DailyTaskStats extends Command {
    /**
     * Dailytask User Table
     *
     * @var TableGateway $mTasksDoneTbl
     * @since 1.0.0
     */
    protected TableGateway $mTasksDoneTbl;

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
        $this->mTasksDoneTbl = new TableGateway('faucet_dailytask_user', $mapper);
        $this->mUserStatsTbl = new TableGateway('user_faucet_stat', $mapper);

        $this->mSecTools = new SecurityTools($mapper);
        $this->mBatchTools = new BatchTools();
        
        // you *must* call the parent constructor
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // log start
        $output->writeln([
            '=====================',
            'Generating Daily Task Statistics @ ' . date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        $lastDate = $this->mSecTools->getCoreSetting('job_dailys_stats_date');
        $lastrun = $this->mSecTools->getCoreSetting('job_dailys_stats_lastrun');

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

        $tasksDone = $this->generateDailyTaskStats($statDate);
        $output->writeln([
            '- Processed '.$tasksDone.' daily tasks',
        ]);

        $this->mSecTools->updateCoreSetting('job_dailys_stats_date', date('Y-m-d', $statDate));
        //$this->mSecTools->updateCoreSetting('job_dailys_stats_lastrun', date('Y-m-d H:i:s', time()));

        $output->writeln([
            '-- Daily Task Statistics completed successfully',
            '=====================',
        ]);

        return Command::SUCCESS;
    }

    private function generateDailyTaskStats($date) {
        // load shares
        $tWh = new Where();
        $tWh->like('date', date('Y-m-d', $date).'%');
        $tSel = new Select($this->mTasksDoneTbl->getTable());
        $tSel->where($tWh);
        $tSel->order('date ASC');

        $tasksToday = $this->mTasksDoneTbl->selectWith($tSel);

        // Count Entries
        $tasksCount = $tasksToday->count();

        // get offers done by user
        $dailyTasksByUserId = [];
        foreach($tasksToday as $offer) {
            if(!array_key_exists('user-'.$offer->user_idfs, $dailyTasksByUserId)) {
                $dailyTasksByUserId['user-'.$offer->user_idfs] = 0;
            }
            $dailyTasksByUserId['user-'.$offer->user_idfs]++;
        }

        // update user stats (alltime)
        $key = 'user-dailys-total';
        $this->updateUserStatsByKey($key, $dailyTasksByUserId);

        // update user stats (month)
        $key = 'user-dailys-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $dailyTasksByUserId);

        // update user stats (week)
        $key = 'user-dailys-w-'.$this->mBatchTools->getWeek($date);
        $this->updateUserStatsByKey($key, $dailyTasksByUserId);

        return $tasksCount;
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