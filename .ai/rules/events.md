---
paths:
  - 'app/Events/**'
---

# Events

## TabUpdated broadcasts now, after commit, carrying only an id
TabUpdated is ShouldBroadcastNow, not ShouldBroadcast. QUEUE_CONNECTION is database, so a queued broadcast would sit still until a worker happened to be running, and the whole point is that the customer sees the item as the shopkeeper adds it.

It is also ShouldDispatchAfterCommit. ApplyMomoResult saves the payment request before settling the entries it covers, both in one transaction, so a listener firing on that save would see the tab still owing money.

The payload is only the tab id. Receivers refetch through their own policies, so tab contents never travel over the socket.

It is dispatched from the BroadcastsTabUpdates trait on TabEntry and PaymentRequest, not from the actions. That way the background paths (the MoMo callback job, payments:reconcile-pending) refresh both screens for free. The trait hooks model events, so a bulk query-builder update bypasses it; that is safe only where such an update shares a transaction with a saved model.

run `composer run dev` to get Reverb and Horizon; the channel is private-tabs.{id}, authorized in routes/channels.php via TabPolicy::view.
