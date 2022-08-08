<?php

namespace Batch\Command\MinerShares;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Http\ClientStatic;
use Laminas\Cli\Command\AbstractParamAwareCommand;
use Laminas\Cli\Input\ParamAwareInputInterface;
use Laminas\Cli\Input\StringParam;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class MinerShares extends AbstractParamAwareCommand
{
    /**
     * User Table
     *
     * @var TableGateway $mUserTbl
     * @since 1.0.0
     */
    protected TableGateway $mUserTbl;

    /**
     * Miner Payments Table
     *
     * @var TableGateway $mPaymentTbl
     * @since 1.0.0
     */
    protected TableGateway $mPaymentTbl;

    /**
     * Faucet Wallet Table
     *
     * @var TableGateway $mWalletTbl
     * @since 1.0.0
     */
    protected TableGateway $mWalletTbl;

    /**
     * Security Tools
     *
     * @var SecurityTools $mSecTools
     */
    protected SecurityTools $mSecTools;

    private BatchTools $mBatchTools;

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
        $this->mPaymentTbl = new TableGateway('faucet_miner_payment', $mapper);
        $this->mWalletTbl = new TableGateway('faucet_wallet', $mapper);

        $this->mSecTools = new SecurityTools($mapper);
        $this->mBatchTools = new BatchTools($mapper);

        // you *must* call the parent constructor
        parent::__construct();
    }

    /**
     * Configure Command - add parameter
     */
    protected function configure() : void
    {
        $this->addParam(
            (new StringParam('coin'))
                ->setDescription('Coin you want to process shares')
                ->setShortcut('c')
        );
    }

    /**
     * Run MinerShares Command
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $coin = $input->getParam('coin');
        switch($coin) {
            case 'xmr':
            case 'etc':
            case 'rvn':
                break;
            default:
                $output->writeln([
                    "Coin not supported"
                ]);
                return AbstractParamAwareCommand::SUCCESS;
        }

        // log start
        $output->writeln([
            '=====================',
            'Check Miner Shares @ ' . date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        // set basic info
        $isDone = $this->runMinerPaymentRun($coin, 1, $output);

        return AbstractParamAwareCommand::SUCCESS;
    }

    /**
     * Execute Payment run for miners
     * @param $currency
     * @param $hours
     * @param $output
     * @return bool
     */
    private function runMinerPaymentRun($currency, $hours, $output): bool
    {
        $nanoWallet = $this->mSecTools->getCoreSetting('nanopool-address-'.$currency);

        if(strlen($nanoWallet) < 10) {
            $output->writeln([
                '## No valid wallet address found (='.$nanoWallet.')',
            ]);
            return true;
        }

        // Load Total Average Hashrate
        $output->writeln([
            '- Get Total Average Hashrate for the last '.$hours.' hours for '.strtoupper($currency),
        ]);
        $avgHashrate = $this->loadTotalAvgHashrate($currency, $nanoWallet, $hours);
        if($avgHashrate) {
            $output->writeln([
                '- Total Avg Hashrate = '.$avgHashrate,
            ]);
        } else {
            $output->writeln([
                '## ERROR - COULD NOT LOAD TOTAL AVERAGE HASHRATE FROM API',
            ]);
            return true;
        }

        // Load Workers Average Hashrate
        $output->writeln([
            '- Get Workers Average Hashrate for the last '.$hours.' hours for '.strtoupper($currency),
        ]);
        $workersHashrate = $this->loadWorkersAvgHashrate($currency, $nanoWallet, $hours);
        $workers = [];
        if(is_array($workersHashrate)) {
            $totalPercent = 0;
            foreach($workersHashrate as $worker) {
                // skip invalid worker names
                if(!property_exists($worker, 'worker')) {
                    continue;
                }
                if(strlen($worker->worker) < 5) {
                    continue;
                }
                // Calculate % of total avg hashrate
                $percent = 0;
                if($worker->hashrate > 0 && $avgHashrate > 0) {
                    $percent = round((100 / ($avgHashrate / $worker->hashrate)), 2);
                }
                $totalPercent+=$percent;

                if($worker->hashrate > 0) {
                    $workers[$worker->worker] = (object)[
                        'name' => $worker->worker,
                        'hashrate' => $worker->hashrate,
                        'pool_percent' => $percent
                    ];

                    $output->writeln([
                        '- Worker '.$worker->worker.' has '.$worker->hashrate.' = '.$percent.'% of Pool',
                    ]);
                } else {
                    $output->writeln([
                        '- Skipping inactive worker '.$worker->worker,
                    ]);
                }
            }

            if($totalPercent > 100) {
                $output->writeln([
                    '- Total Hashrate % is bigger than 100% = '.$totalPercent,
                ]);
            }
        } else {
            $output->writeln([
                '## ERROR - COULD NOT LOAD WORKERS AVERAGE HASHRATE FROM API',
            ]);
            return true;
        }

        $output->writeln([
            '- Total active workers on pool = '.count($workers),
        ]);

        $output->writeln([
            '- Get Workers effective Shares for the last '.$hours.' hours for '.strtoupper($currency),
        ]);
        $workerShares = $this->loadSharesPerWorker($currency, $nanoWallet, $hours);
        $totalShares = 0;
        $workersWithShares = [];
        if(is_array($workerShares)) {
            foreach($workerShares as $workerShare) {
                // add shares to worker
                if(array_key_exists($workerShare->worker, $workers)) {
                    $workers[$workerShare->worker]->shares = $workerShare->shares;
                    $totalShares+=$workerShare->shares;
                }
            }
            $totalSharesPercent = 0;
            // loop all workers again to calculate and add percent
            foreach($workers as $worker) {
                if(property_exists($worker, 'shares')) {
                    // Calculate % of total avg hashrate
                    if($worker->shares > 0 && $totalShares > 0) {
                        $percent = round((100 / ($totalShares / $worker->shares)), 2);
                        $worker->shares_percent = $percent;
                        $totalSharesPercent+=$percent;
                        $workersWithShares[] = $worker;
                    } elseif($worker->shares == 0) {
                        $output->writeln([
                            '- Skipping worker '.$worker->worker.' because no shares',
                        ]);
                    }
                } else {
                    if(property_exists($worker, 'worker')) {
                        $output->writeln([
                            '- Skipping worker ' . $worker->worker . ' because no shares',
                        ]);
                    } else {
                        $output->writeln([
                            '- Skipping UNKNOWN worker because no shares / no worker name',
                        ]);
                    }
                }
            }
            if($totalSharesPercent > 100) {
                $output->writeln([
                    '- Total Shares % is bigger than 100% = '.$totalPercent,
                ]);
            }
        } else {
            $output->writeln([
                '## ERROR - COULD NOT LOAD WORKERS EFFECTIVE SHARES FROM API',
            ]);
            return true;
        }
        $output->writeln([
            '- Total active workers with shares = '.count($workersWithShares),
        ]);

        // Get Total Earnings in Crypto for timeframe
        $earningsCrypto = $this->loadCryptoEarnings($currency, $nanoWallet);
        if($earningsCrypto) {
            $output->writeln([
                '- Total Earnings in Crypto: '.$earningsCrypto,
            ]);
            if($earningsCrypto <= 0) {
                $output->writeln([
                    '## ERROR - NO EARNINGS IN CRYPTO = NOTHING TO PAY',
                ]);
                return true;
            }
        } else {
            $output->writeln([
                '## ERROR - COULD NOT LOAD BALANCE FROM API',
            ]);
            return true;
        }

        // Convert Crypto Earnings to Swissfaucet Coins
        $earningsCoins = $this->convertCryptoEarningsToCoins($currency, $earningsCrypto);
        if($earningsCoins) {
            $output->writeln([
                '- Total Earnings in Coins: '.$earningsCoins,
            ]);
        } else {
            $output->writeln([
                '## ERROR - COULD NOT COINVERT EARNINGS TO CRYPTO',
            ]);
            return true;
        }

        // Subtract the margin for Swissfaucet needed for converting crypto
        $faucetMargin = $this->mSecTools->getCoreSetting('nanopool-earnings-margin');
        $totalPayment = $earningsCoins*$faucetMargin;
        $output->writeln([
            '- Total Payment in Coins: '.$totalPayment. ' ( '.($faucetMargin*100).' % of Earnings )',
        ]);

        // Pay Users based on their % hashrate of pool
        if($this->payMiners($totalPayment, $workersWithShares, $currency,$output)) {
            $output->writeln([
                '-- Payment run completed successfully',
                '=====================',
            ]);
        } else {
            $output->writeln([
                '## ERROR WHILE DOING PAYMENT RUN',
                '=====================',
            ]);
        }

        return true;
    }

    /**
     * Load Total Average Hashrate for timeframe
     * @param $currency
     * @param $wallet
     * @param $hours
     * @return bool|string
     */
    private function loadTotalAvgHashrate($currency, $wallet, $hours): bool|string
    {
        // Load Total Average Hashrate for defined timeframe
        $response = ClientStatic::get("https://api.nanopool.org/v1/".$currency."/avghashratelimited/".$wallet."/".$hours, []);
        $apiResponse = $response->getBody();
        if(strlen($apiResponse) > 0) {
            $responseJson = json_decode($apiResponse);

            return $responseJson->data ?? false;
        } else {
            return false;
        }
    }

    /**
     * Load Workers effective Shares for timeframe
     * @param $currency
     * @param $wallet
     * @param $hours
     * @return bool|array
     */
    private function loadSharesPerWorker($currency, $wallet, $hours): bool|array
    {
        $response = ClientStatic::get("https://api.nanopool.org/v1/".$currency."/sharesperworker/".$wallet."/".$hours, []);
        $apiResponse = $response->getBody();
        if(strlen($apiResponse) > 0) {
            $responseJson = json_decode($apiResponse);

            return $responseJson->data ?? false;
        } else {
            return false;
        }
    }

    /**
     * Load Workers Average Hashrate for timeframe
     * @param $currency
     * @param $wallet
     * @param $hours
     * @return bool|array
     */
    private function loadWorkersAvgHashrate($currency, $wallet, $hours): bool|array
    {
        $response = ClientStatic::get("https://api.nanopool.org/v1/".$currency."/avghashrateworkers/".$wallet."/".$hours, []);
        $apiResponse = $response->getBody();
        if(strlen($apiResponse) > 0) {
            $responseJson = json_decode($apiResponse);

            return $responseJson->data ?? false;
        } else {
            return false;
        }
    }

    /**
     * Load Earnings in Crypto for timeframe
     * @param $currency
     * @param $wallet
     * @return bool|float
     */
    private function loadCryptoEarnings($currency, $wallet): bool|string
    {
        $cachedBalance = $this->mSecTools->getCoreSetting('nanopool-'.$currency.'-cached-balance');
        $response = ClientStatic::get("https://api.nanopool.org/v1/".$currency."/balance/".$wallet, []);
        $apiResponse = $response->getBody();
        if(strlen($apiResponse) > 0) {
            $responseJson = json_decode($apiResponse);

            if(isset($responseJson->data)) {
                $newBalance = $responseJson->data;
                if($newBalance > $cachedBalance && $cachedBalance > 0) {
                    $earnings = $newBalance-$cachedBalance;
                } else {
                    // there was a payment so the balance is lower - take full balance as earnings
                    $earnings = $newBalance;
                }

                // update cache
                $this->mSecTools->updateCoreSetting('nanopool-'.$currency.'-cached-balance', $newBalance);

                return number_format($earnings, 8);
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * Convert Crypto to Swissfaucet Coins
     * @param $currency
     * @param $amount
     * @return bool|int
     */
    private function convertCryptoEarningsToCoins($currency, $amount): bool|int
    {
        // Load Crypto info to get its current dollar value
        $coinInfo = $this->mWalletTbl->select(['coin_sign' => strtoupper($currency)]);
        if($coinInfo->count() > 0) {
            $coinInfo = $coinInfo->current();

            // Convert crypto to usd
            $dollarValue = $amount*$coinInfo->dollar_val;

            // Get Swissfaucet Coin Value for each Dollar
            $sfCoinValue = $this->mSecTools->getCoreSetting('swissfaucet-coin-dollar-value');
            if($sfCoinValue) {
                return round($dollarValue*$sfCoinValue, 0);
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * Pay Miners based on their % Hashrate
     * @param $payment
     * @param $miners
     * @param $output
     * @return bool
     */
    private function payMiners($payment, $miners, $currency, $output): bool
    {
        $output->writeln([
            '- Starting Payment for '.count($miners).' miners',
        ]);
        // build payment queue
        $paymentQueue = [];
        $queueTotal = 0;
        foreach($miners as $miner) {
            $nameTmp = explode('-', $miner->name);
            if(isset($nameTmp[0])) {
                $userId = substr($nameTmp[0], strlen('swissfaucetio'));
                // hacker achievement
                if(str_starts_with($nameTmp[0], 'hacker')) {
                    $userId = substr($nameTmp[0], strlen('hacker'));
                    $done = $this->mBatchTools->completeUserAchievement($userId, 'mininghack', 1);
                }
                // build payment queue
                if(is_numeric($userId) && $userId > 0 && !empty($userId)) {
                    // support multiple workers for same user
                    $workerName = 'default';
                    if(isset($nameTmp[1])) {
                        $workerName = substr(filter_var($nameTmp[1], FILTER_SANITIZE_STRING), 0, 50);
                    }
                    $user = $this->mUserTbl->select(['User_ID' => $userId]);
                    if($user->count() > 0) {
                        if($miner->shares_percent > 0) {
                            $amount = floor(($payment*($miner->shares_percent/100)));
                            $queueTotal+=$amount;
                            $paymentQueue[] = (object)[
                                'user_idfs' => $userId,
                                'amount' => $amount,
                                'hashrate' => $miner->hashrate,
                                'hashrate_percent' => $miner->pool_percent,
                                'shares' => $miner->shares,
                                'shares_percent' => $miner->shares_percent,
                                'worker' => $workerName
                            ];
                        } else {
                            $output->writeln([
                                '- Invalid % Hashrate = '.$miner->shares_percent.' - ignore user '.$userId,
                            ]);
                        }
                    } else {
                        $output->writeln([
                            '- User not found: '.$userId,
                        ]);
                    }
                } else {
                    $output->writeln([
                        '- Invalid UserId: '.$userId,
                    ]);
                }
            } else {
                $output->writeln([
                    '- Invalid Worker Name: '.$miner->name,
                ]);
            }
        }

        $output->writeln([
            '- Total Payments in Queue = '.count($paymentQueue)
        ]);

        // double check payment amount - have some spare for rounding
        if($queueTotal <= ($payment+100)) {
            foreach($paymentQueue as $pay) {
                // check userId again in case something went wrong above
                if($pay->user_idfs != null && $pay->user_idfs > 0 && is_numeric($pay->user_idfs)) {
                    $output->writeln([
                        '- Pay '.$pay->amount.' Coins to User '.$pay->user_idfs
                    ]);
                    // add payment to database for next payment run
                    $this->mPaymentTbl->insert([
                        'user_idfs' => $pay->user_idfs,
                        'hashrate' => $pay->hashrate,
                        'hashrate_percent' => $pay->hashrate_percent,
                        'shares' => $pay->shares,
                        'shares_percent' => $pay->shares_percent,
                        'worker' => $pay->worker,
                        'amount_coin' => $pay->amount,
                        'state' => 'open',
                        'coin' => $currency,
                        'date' => date('Y-m-d H:i:s', time())
                    ]);
                } else {
                    $output->writeln([
                        '- Skipping payment for invalid userId: '.$pay->user_idfs
                    ]);
                }
            }

            return true;
        } else {
            $output->writeln([
                '- Invalid Payment Queue Total '.$queueTotal.' > '.$payment,
            ]);
            return false;
        }
    }
}