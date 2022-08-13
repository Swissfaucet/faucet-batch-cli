<?php

namespace Batch\Command\TransactionStats;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TransactionStats extends Command
{
    /**
     * User Table
     *
     * @var TableGateway $mTxTbl
     * @since 1.0.0
     */
    protected TableGateway $mTxTbl;

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
        $this->mTxTbl = new TableGateway('faucet_transaction', $mapper);

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
            'Checking for new transactions @ '.date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        $lastDate = $this->mSecTools->getCoreSetting('job_transaction_stats_date');
        $lastrun = $this->mSecTools->getCoreSetting('job_transaction_stats_lastrun');

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

        $txsFound = $this->processTransactions($statDate);
        $output->writeln([
            'Processed '.$txsFound.' transactions',
        ]);

        $this->mSecTools->updateCoreSetting('job_transaction_stats_date', date('Y-m-d', $statDate));
        $this->mSecTools->updateCoreSetting('job_transaction_stats_lastrun', date('Y-m-d H:i:s', time()));

        $output->writeln([
            '-- Transaction Statistics completed successfully',
            '=====================',
        ]);

        return Command::SUCCESS;
    }

    private function processTransactions($date)
    {
        // load shares
        $tWh = new Where();
        $tWh->like('date', date('Y-m-d', $date).'%');
        $tSel = new Select($this->mTxTbl->getTable());
        $tSel->where($tWh);
        $tSel->order('date ASC');

        $txToday = $this->mTxTbl->selectWith($tSel);

        // Count Entries
        $txCount = $txToday->count();

        $earningsByUserId = [];
        $earningsByType = [];
        foreach($txToday as $tx) {
            if($tx->is_output == 0) {
                if(!array_key_exists('user-'.$tx->user_idfs, $earningsByUserId)) {
                    $earningsByUserId['user-'.$tx->user_idfs] = 0;
                }
                $earningsByUserId['user-'.$tx->user_idfs]+=$tx->amount;
                if(!array_key_exists($tx->ref_type,$earningsByType)) {
                    $earningsByType[$tx->ref_type] = 0;
                }
                $earningsByType[$tx->ref_type]+=$tx->amount;
            }
        }

        $this->output->writeLn([
            '- Update User Earnings for '.count($earningsByUserId).' Users'
        ]);

        // update user stats (alltime)
        $key = 'user-earnings-total';
        $this->mBatchTools->updateUserStatsByKey($key, $earningsByUserId, 'earning');

        // update user stats (month)
        $key = 'user-earnings-m-'.date('n-Y', $date);
        $this->mBatchTools->updateUserStatsByKey($key, $earningsByUserId);

        // update user stats (week)
        $weekNo = $this->mBatchTools->getWeek($date);
        $key = 'user-earnings-w-'.$weekNo;
        $this->mBatchTools->updateUserStatsByKey($key, $earningsByUserId);

        // update core stats (month)
        $key = 'cost-';
        foreach($earningsByType as $tKey => $tVal) {
            $sKey = $key.$tKey.'-m-'.date('n-Y', $date);
            $this->mBatchTools->updateCoreStatsByKey($sKey, $tVal);
        }

        // update core stats (week)
        $key = 'cost-';
        foreach($earningsByType as $tKey => $tVal) {
            $sKey = $key.$tKey.'-w-'.$weekNo;
            $this->mBatchTools->updateCoreStatsByKey($sKey, $tVal);
        }

        return $txCount;
    }


}