<?php

namespace Batch\Command\FakeAccountCheck;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FakeAccountCheck extends Command
{
    /**
     * User Table
     *
     * @var TableGateway $mWthTbl
     * @since 1.0.0
     */
    protected TableGateway $mWthTbl;

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

    private TableGateway $mStatsTbl;

    private OutputInterface $output;

    /**
     * @var TableGateway
     */
    private TableGateway $mLogTbl;

    /**
     * Constructor
     *
     * UserResource constructor.
     * @param $mapper
     * @since 1.0.0
     */
    public function __construct($mapper)
    {
        $this->mWthTbl = new TableGateway('faucet_withdraw', $mapper);
        $this->mUserStatsTbl = new TableGateway('user_faucet_stat', $mapper);
        $this->mStatsTbl = new TableGateway('core_statistic', $mapper);
        $this->mLogTbl = new TableGateway('faucet_log', $mapper);

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
            'Checking for Fake Accounts @ '.date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        $accountsFound = $this->processWithdrawals();
        $output->writeln([
            'Processed '.$accountsFound.' withdrawals',
        ]);

        $this->mSecTools->updateCoreSetting('job_fake_checker_lastrun', date('Y-m-d H:i:s', time()));

        $output->writeln([
            '-- Checking for Fake Accounts completed successfully',
            '=====================',
        ]);

        return Command::SUCCESS;
    }

    private function processWithdrawals()
    {
        // load shares
        $tWh = new Where();
        $tWh->like('state', 'new');
        $tWh->equalTo('u.multi_verified', 0);
        $tSel = new Select($this->mWthTbl->getTable());
        $tSel->join(['u' => 'user'],'u.User_ID = faucet_withdraw.user_idfs', ['multi_verified']);
        $tSel->where($tWh);

        $accountsToday = $this->mWthTbl->selectWith($tSel);

        // Count Entries
        $accountsCount = $accountsToday->count();

        $withdrawalsByIp = [];
        foreach($accountsToday as $wth) {
            if(strlen($wth->ip) > 1) {
                if(!array_key_exists('ip-'.$wth->ip, $withdrawalsByIp)) {
                    $withdrawalsByIp['ip-'.$wth->ip] = [];
                }
                if(!in_array($wth->user_idfs, $withdrawalsByIp['ip-'.$wth->ip])) {
                    $withdrawalsByIp['ip-'.$wth->ip][] = $wth->user_idfs;
                }
            }
        }

        foreach($withdrawalsByIp as $ipKey => $ipUsers) {
            if(count($ipUsers) >= 2) {
                $this->mLogTbl->insert([
                    'log_type' => 'wth-multi-ip',
                    'log_level' => 'warning',
                    'log_message' => 'Multiple Users Withdrawn from same IP',
                    'log_info' => substr($ipKey, strlen('ip-')).': '.json_encode($ipUsers),
                    'log_date' => date('Y-m-d H:i:s', time())
                ]);
            }
        }

        return $accountsCount;
    }
}