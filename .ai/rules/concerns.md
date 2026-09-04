---
paths:
  - 'app/Models/Concerns/**'
---

# Concerns

## A dead Reverb must never break a payment
TabUpdated is ShouldBroadcastNow, so the broadcast happens inline and an unreachable Reverb throws. This was observed for real: with Reverb stopped, adding an entry and settling a payment both aborted with a BroadcastException from cURL error 7 on localhost:8080.

BroadcastsTabUpdates therefore wraps the dispatch in rescue() inside DB::afterCommit(). Live updates are a courtesy; the money is not. Two tests in TabBroadcastTest assert the entry is still recorded and the payment still settles when a TabUpdated listener throws. Do not remove the rescue.

The original crash also exposed a second failure mode: the payment request row committed, then the broadcast threw before the MoMo POST ran, leaving a request in status 'created' that MoMo had never heard of. payments:reconcile-pending now closes any stale request with a null requested_at as NEVER_SENT rather than asking MoMo about it forever.
