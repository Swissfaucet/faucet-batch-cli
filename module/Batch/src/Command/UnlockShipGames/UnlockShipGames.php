<?php

namespace Batch\Command\UnlockShipGames;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UnlockShipGames extends Command {
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
     * @var OutputInterface
     */
    private OutputInterface $output;

    /**
     * @var TableGateway
     */
    private TableGateway $mRpsGameTbl;

    /**
     * Constructor
     *
     * UserResource constructor.
     * @param $mapper
     * @since 1.0.0
     */
    public function __construct($mapper)
    {
        $this->mGameTbl = new TableGateway('battleship_match', $mapper);
        $this->mRpsGameTbl = new TableGateway('faucet_game_match', $mapper);
        $this->mUserStatsTbl = new TableGateway('user_faucet_stat', $mapper);

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
            'Unlocking Locked Games @ ' . date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        $tasksDone = $this->generateUnlockShipGames();
        $output->writeln([
            '- Processed '.$tasksDone.' locked battleships games',
        ]);

        $tasksDone = $this->generateUnlockRpsGames();
        $output->writeln([
            '- Processed '.$tasksDone.' locked rps games',
        ]);

        $output->writeln([
            '-- Unlock Games completed successfully',
            '=====================',
        ]);

        return Command::SUCCESS;
    }

    private function generateUnlockShipGames() {
        // load shares
        $tWh = new Where();
        $tWh->lessThanOrEqualTo('date_matched', date('Y-m-d H:i:s', strtotime('-10 minutes')));
        $tWh->isNull('client_grid');
        $tSel = new Select($this->mGameTbl->getTable());
        $tSel->where($tWh);
        //  $tSel->order('date ASC');

        $gamesToday = $this->mGameTbl->selectWith($tSel);
        foreach($gamesToday as $game) {
            $this->output->writeln([
                '- Unlock Game #'.$game->Match_ID,
            ]);
            $this->mGameTbl->update([
                'client_user_idfs' => null,
                'date_matched' => null
            ],['Match_ID' => $game->Match_ID]);
        }

        return $gamesToday->count();
    }

    private function generateUnlockRpsGames() {
        // load shares
        $tWh = new Where();
        $tWh->lessThanOrEqualTo('date_matched', date('Y-m-d H:i:s', strtotime('-10 minutes')));
        $tWh->isNotNull('client_user_idfs');
        $tWh->isNull('date_finished');
        $tSel = new Select($this->mRpsGameTbl->getTable());
        $tSel->where($tWh);
        //  $tSel->order('date ASC');

        $gamesToday = $this->mRpsGameTbl->selectWith($tSel);
        foreach($gamesToday as $game) {
            $this->output->writeln([
                '- Unlock RPS Game #'.$game->Match_ID,
            ]);
            $this->mRpsGameTbl->update([
                'client_user_idfs' => null,
                'date_matched' => null
            ],['Match_ID' => $game->Match_ID]);
        }

        return $gamesToday->count();
    }
}