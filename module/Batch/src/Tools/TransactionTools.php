<?php

namespace Batch\Tools;

use Laminas\Db\TableGateway\TableGateway;

class TransactionTools {
    /**
     * User Table
     *
     * @var TableGateway $mUserTbl
     * @since 1.1.0
     */
    protected TableGateway $mUserTbl;

    /**
     * Transaction Table
     *
     * @var TableGateway $mTxTbl
     * @since 1.1.0
     */
    protected TableGateway $mTxTbl;

    /**
     * PTC Transaction Table
     *
     * @var TableGateway $mPtcTxTbl
     * @since 1.1.0
     */
    protected TableGateway $mPtcTxTbl;

    /**
     * Constructor
     *
     * SecurityTools constructor.
     * @param $mapper
     * @since 1.1.0
     */
    public function __construct($mapper)
    {
        $this->mUserTbl = new TableGateway('user', $mapper);
        $this->mTxTbl = new TableGateway('faucet_transaction', $mapper);
        $this->mPtcTxTbl = new TableGateway('ptc_transaction', $mapper);
    }

    /**
     * Execute a Faucet User Transaction
     *
     * @param float $amount
     * @param bool $isOutput
     * @param int $userId
     * @param int $refId
     * @param string $refType
     * @param string $description
     * @param int $createdBy
     * @param bool $updateOnlineStatus
     * @return float|bool
     * @since 1.1.0
     */
    public function executeTransaction(float $amount, bool $isOutput, int $userId, int $refId, string $refType, string $description, int $createdBy = 0, $updateOnlineStatus = true): float|bool
    {
        # no negative transactions allowed
        if($amount < 0) {
            return false;
        }

        # Do not allow zero or negative values for update
        if($userId <= 0) {
            return false;
        }

        # Generate Transaction ID
        try {
            $sTransactionID = random_bytes(5);
        } catch(\Exception $e) {
            # Fallback if random bytes fails
            $sTransactionID = time();
        }
        $sTransactionID = hash("sha256",$sTransactionID);

        # Get user from database
        $userInfo = $this->mUserTbl->select(['User_ID' => $userId]);
        if(count($userInfo) > 0) {
            $userInfo = $userInfo->current();
            $txCheck = $this->mTxTbl->select([
                'amount' => $amount,
                'date' => date('Y-m-d H:i:s', time()),
                'ref_idfs' => $refId,
                'ref_type' => $refType,
                'comment' => $description,
                'user_idfs' => $userId,
            ]);
            if($txCheck->count() == 0) {
                # calculate new balance
                $newBalance = ($isOutput) ? $userInfo->token_balance-$amount : $userInfo->token_balance+$amount;
                # Insert Transaction
                if($this->mTxTbl->insert([
                    'Transaction_ID' => $sTransactionID,
                    'amount' => $amount,
                    'token_balance' => $userInfo->token_balance,
                    'token_balance_new' => $newBalance,
                    'is_output' => ($isOutput) ? 1 : 0,
                    'date' => date('Y-m-d H:i:s', time()),
                    'ref_idfs' => $refId,
                    'ref_type' => $refType,
                    'comment' => $description,
                    'user_idfs' => $userId,
                    'created_by' => ($createdBy == 0) ? $userId : $createdBy,
                ])) {
                    $userUpd = [
                        'token_balance' => $newBalance,
                    ];
                    if($updateOnlineStatus) {
                        $userUpd['last_action'] = date('Y-m-d H:i:s', time());
                        $userUpd['is_online'] = 1;
                    }
                    # update user balance
                    $this->mUserTbl->update($userUpd,[
                        'User_ID' => $userId
                    ]);
                    return $newBalance;
                } else {
                    return false;
                }
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * Execute Faucet PTC Credit Transaction for User
     *
     * @param float $amount - Amount of Token to transfer
     * @param bool $isInput - Defines if Transaction is Output
     * @param int $userId - Target User ID
     * @param int $refId - Reference ID for Transaction
     * @param string $refType - Reference Type for Transaction
     * @param string $description - Detailed Description for Transaction
     * @param int $createdBy (optional) - Source User ID
     * @since 1.0.0
     */
    public function executeCreditTransaction(float $amount, bool $isOutput, int $userId, int $refId, string $refType): float|bool
    {
        # no negative transactions allowed
        if($amount < 0) {
            return false;
        }

        # Do not allow zero for update
        if($userId == 0) {
            return false;
        }

        # Generate Transaction ID
        try {
            $sTransactionID = $bytes = random_bytes(5);
        } catch(\Exception $e) {
            # Fallback if random bytes fails
            $sTransactionID = time();
        }
        $sTransactionID = hash("sha256",$sTransactionID);

        # Get user from database
        $userInfo = $this->mUserTbl->select(['User_ID' => $userId]);
        if(count($userInfo) > 0) {
            $userInfo = $userInfo->current();
            # calculate new balance
            $newBalance = ($isOutput) ? $userInfo->credit_balance-$amount : $userInfo->credit_balance+$amount;
            # Insert Transaction
            if($this->mPtcTxTbl->insert([
                'Transaction_ID' => $sTransactionID,
                'amount' => $amount,
                'credit_balance' => $userInfo->credit_balance,
                'credit_balance_new' => $newBalance,
                'is_output' => ($isOutput) ? 1 : 0,
                'date' => date('Y-m-d H:i:s', time()),
                'ref_idfs' => $refId,
                'ref_type' => $refType,
                'user_idfs' => $userId,
            ])) {
                # update user balance
                $this->mUserTbl->update([
                    'credit_balance' => $newBalance
                ],[
                    'User_ID' => $userId
                ]);
                return $newBalance;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }
}