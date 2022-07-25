<?php

namespace Batch\Command\UserOnlineCheck;

use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserOnlineCheck extends Command
{
    /**
     * User Table
     *
     * @var TableGateway $mUserTbl
     * @since 1.0.0
     */
    protected $mUserTbl;

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

        // you *must* call the parent constructor
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // log start
        $output->writeln([
            '=====================',
            'Checking online users @ '.date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        // load online users
        $onlineUsers = $this->mUserTbl->select(['is_online' => 1]);
        $onlineUserCount = $onlineUsers->count();
        $output->writeln([
            'Found '.$onlineUserCount.' online users',
        ]);

        if($onlineUserCount > 0) {
            $offlineUsers = 0;
            foreach($onlineUsers as $user) {
                // if last_action is more than 1 hour ago, set to offline
                if(strtotime($user->last_action) <= time()-3600) {
                    $this->mUserTbl->update([
                        'is_online' => 0
                    ],['User_ID' => $user->User_ID]);
                    $offlineUsers++;
                }
            }
            $output->writeln([
                'Setting '.$offlineUsers.' users to offline',
            ]);
        } else {
            $output->writeln([
                'No online users',
            ]);
        }
        $output->writeln([
            '====================='
        ]);

        return Command::SUCCESS;
    }
}