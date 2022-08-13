<?php

namespace Batch\Command\CacheGuildMembers;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CacheGuildMembers extends Command
{
    /**
     * Guild User Table
     *
     * @var TableGateway $mGuildUserTbl
     * @since 1.0.0
     */
    protected TableGateway $mGuildUserTbl;

    /**
     * Guild Table
     *
     * @var TableGateway $mGuildTbl
     * @since 1.0.0
     */
    protected TableGateway $mGuildTbl;

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
        $this->mGuildUserTbl = new TableGateway('faucet_guild_user', $mapper);
        $this->mGuildTbl = new TableGateway('faucet_guild', $mapper);

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
            'Updating Guild Member Counts @ '.date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        $lastrun = $this->mSecTools->getCoreSetting('job_gmember_cache_lastrun');

        // only run batch once per day
        if(date('Y-m-d', strtotime($lastrun)) != date('Y-m-d', time())) {
            
        } else {
            $output->writeln([
                '## ERROR - BATCH HAS ALREADY RUN TODAY',
            ]);
            return Command::SUCCESS;
        }

        $accountsFound = $this->processGuildMembers();
        $output->writeln([
            'Updated Member Count for '.$accountsFound.' guilds',
        ]);

        $this->mSecTools->updateCoreSetting('job_gmember_cache_lastrun', date('Y-m-d H:i:s', time()));

        $output->writeln([
            '-- Guild Member Counts updated successfully',
            '=====================',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Update Guild Member Counts for all Guilds
     * @return int
     */
    private function processGuildMembers(): int
    {
        // load shares
        $tWh = new Where();
        $tWh->notLike('date_joined', '0000-00-00 00:00:00');
        $tSel = new Select($this->mGuildUserTbl->getTable());
        $tSel->where($tWh);

        $allGuildMembers = $this->mGuildUserTbl->selectWith($tSel);
        $membersByGuild = [];
        foreach($allGuildMembers as $member) {
            $gKey = 'guild-'.$member->guild_idfs;
            if(!array_key_exists($gKey, $membersByGuild)) {
                $membersByGuild[$gKey] = 0;
            }
            $membersByGuild[$gKey]++;
        }

        foreach($membersByGuild as $gKey => $gMembers) {
            $guildId = substr($gKey, strlen('guild-'));
            if($guildId != 0 && is_numeric($guildId)) {
                $this->mGuildTbl->update([
                    'members' => $gMembers
                ],['Guild_ID' => $guildId]);
            }
        }

        return count($membersByGuild);
    }
}