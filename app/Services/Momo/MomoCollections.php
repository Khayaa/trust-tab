<?php

namespace App\Services\Momo;

use App\Exceptions\MomoRequestFailed;

interface MomoCollections
{
    /**
     * Ask the customer's wallet to approve a debit.
     *
     * Returns a pending transaction on success. MoMo answers 202 for a new
     * request and 409 for one it has already accepted under the same reference;
     * both mean the same thing to us, so both come back pending.
     *
     * @throws MomoRequestFailed
     */
    public function requestToPay(RequestToPay $command): MomoTransaction;

    /**
     * The authoritative state of a request. Always prefer this over a callback
     * payload before moving money in our own records.
     *
     * @throws MomoRequestFailed
     */
    public function status(string $referenceId): MomoTransaction;
}
