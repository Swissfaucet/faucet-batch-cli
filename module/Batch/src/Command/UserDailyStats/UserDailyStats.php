<?php

namespace Batch\Command\UserDailyStats;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserDailyStats extends Command
{
    /**
     * User Table
     *
     * @var TableGateway $mUserTbl
     * @since 1.0.0
     */
    protected TableGateway $mUserTbl;

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

    private TableGateway $mStatsTbl;

    private OutputInterface $output;

    /**
     * Constructor
     *
     * UserResource constructor.
     * @param $mapper
     * @since 1.0.0
     */
    public function __construct($mapper)
    {
        $this->mUserTbl = new TableGateway('user', $mapper);
        $this->mStatsTbl = new TableGateway('core_statistic', $mapper);

        $this->mSecTools = new SecurityTools($mapper);
        $this->mBatchTools = new BatchTools($mapper);

        // you *must* call the parent constructor
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        // log start
        $output->writeln([
            '=====================',
            'Checking for active users @ '.date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        $lastDate = $this->mSecTools->getCoreSetting('job_active_users_stats_date');
        $lastrun = $this->mSecTools->getCoreSetting('job_active_users_stats_lastrun');

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

        $accountsFound = $this->processNewUserAccounts($statDate);
        $output->writeln([
            'Processed '.$accountsFound.' active users',
        ]);

        $uniqueFound = $this->processMontlyUniqueAccounts($statDate);
        $output->writeln([
            'Processed '.$uniqueFound.' unique active users',
        ]);

        $this->mSecTools->updateCoreSetting('job_active_users_stats_date', date('Y-m-d', $statDate));
        $this->mSecTools->updateCoreSetting('job_active_users_stats_lastrun', date('Y-m-d H:i:s', time()));

        $output->writeln([
            '-- Active User Stats completed successfully',
            '=====================',
        ]);

        return Command::SUCCESS;
    }

    private function processNewUserAccounts($date)
    {
        // load shares
        $tWh = new Where();
        $tWh->like('last_action', date('Y-m-d', $date).'%');
        $tSel = new Select($this->mUserTbl->getTable());
        $tSel->where($tWh);
        $tSel->order('last_action ASC');

        $accountsToday = $this->mUserTbl->selectWith($tSel);

        // Count Entries
        $accountsCount = $accountsToday->count();

        $this->output->writeLn([
            '- Update Core Stats'
        ]);

        // update core stats (month)
        $key = 'users-active-m-'.date('n-Y', $date);
        $this->updateStatsByKey($key, $accountsCount);

        // update core stats (day)
        $key = 'users-active-d-'.date('Y-m-d', $date);
        $this->updateStatsByKey($key, $accountsCount);

        return $accountsCount;
    }

    private function processMontlyUniqueAccounts($date)
    {
        // load shares
        $tWh = new Where();
        $tWh->like('last_action', date('Y-m', $date).'%');
        $tSel = new Select($this->mUserTbl->getTable());
        $tSel->where($tWh);
        $tSel->order('last_action ASC');

        $accountsToday = $this->mUserTbl->selectWith($tSel);

        // Count Entries
        $accountsCount = $accountsToday->count();

        $this->output->writeLn([
            '- Update Core Stats'
        ]);

        // update core stats (month)
        $key = 'users-unique-m-'.date('n-Y', $date);
        $this->updateStatsByKey($key, $accountsCount, true);

        return $accountsCount;
    }

    private function updateStatsByKey($key, $newVal, $replace = false) : void
    {
        $now = date('Y-m-d H:i:s', time());

        $check = $this->mStatsTbl->select(['stats_key' => $key]);
        if($check->count() == 0) {
            // start of a new month
            $this->mStatsTbl->insert([
                'stats_key' => $key,
                'data' => $newVal,
                'date' => $now
            ]);
        } else {
            if(!$replace) {
                $currentVal = $check->current()->data;
                $this->mStatsTbl->update([
                    'data' => $currentVal+$newVal,
                    'date' => $now
                ],['stats_key' => $key]);
            } else {
                $this->mStatsTbl->update([
                    'data' => $newVal,
                    'date' => $now
                ],['stats_key' => $key]);
            }
        }
    }
}