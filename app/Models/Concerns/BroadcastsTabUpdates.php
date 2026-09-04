<?php

namespace App\Models\Concerns;

use App\Events\TabUpdated;
use Illuminate\Support\Facades\DB;

/**
 * Applied to models whose changes should make both phones on a tab look again.
 *
 * Hooking the model rather than the actions means the background paths get this
 * for free: the customer's screen clears itself when the MoMo callback job
 * settles a payment, with nobody watching a poll timer.
 *
 * Note this fires on model events only. A bulk query-builder update bypasses
 * it, which is fine where such an update sits in the same transaction as a
 * saved model, because the announcement waits for the commit.
 */
trait BroadcastsTabUpdates
{
    protected static function bootBroadcastsTabUpdates(): void
    {
        $announce = function (self $model): void {
            if ($model->tab_id === null) {
                return;
            }

            $tabId = (int) $model->tab_id;

            /**
             * Deferred to the commit so a listener that refetches cannot read a
             * half-settled tab, and rescued so that a websocket server which is
             * down cannot take a payment with it. TabUpdated broadcasts inline,
             * and an unreachable Reverb throws, which would otherwise abort the
             * settlement that triggered it. Live updates are a courtesy; the
             * money is not.
             */
            DB::afterCommit(fn () => rescue(fn () => TabUpdated::dispatch($tabId)));
        };

        static::saved($announce);
        static::deleted($announce);
    }
}
