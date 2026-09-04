<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something on a tab changed and both phones should look again.
 *
 * Broadcast now rather than queued: the whole point is that the customer sees
 * the item the moment the shopkeeper adds it, and a queued broadcast would sit
 * still until a worker happened to be running.
 *
 * Dispatched after commit so a listener that refetches can never read the tab
 * mid-transaction. This matters for settlement, where the payment request is
 * saved before the entries it settles.
 */
class TabUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $tabId) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tabs.{$this->tabId}")];
    }

    /**
     * Deliberately just the id. Receivers refetch through their own policies,
     * so a tab's contents never travel over the socket and a stale payload
     * cannot be rendered.
     *
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return ['tabId' => $this->tabId];
    }
}
