<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMomoCallback;
use App\Models\MomoWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MomoCallbackController extends Controller
{
    /**
     * MoMo's Request to Pay callback.
     *
     * The body is not trusted and never settles anything on its own: it is
     * recorded, then a job asks MoMo directly what happened. This endpoint is
     * unauthenticated by necessity, so treating the payload as a hint rather
     * than an instruction is the whole of its security.
     *
     * MoMo delivers a callback once and never retries, so this always answers
     * 200, even for a reference we do not recognise. A non-200 buys nothing and
     * a 500 would only look like an outage on our side.
     */
    public function __invoke(Request $request): Response
    {
        $referenceId = $request->header('X-Reference-Id')
            ?? $request->input('externalId')
            ?? $request->input('referenceId');

        if (! is_string($referenceId) || $referenceId === '') {
            Log::info('MoMo callback missing a reference id');

            return response()->noContent(Response::HTTP_OK);
        }

        /**
         * The unique reference id makes a replayed callback a no-op at the
         * database level rather than something the job has to reason about.
         */
        $status = $request->input('status');

        $event = MomoWebhookEvent::firstOrCreate(
            ['reference_id' => $referenceId],
            [
                'event_type' => 'requesttopay',
                'payload' => array_filter([
                    'status' => is_string($status) ? $status : null,
                ]),
            ],
        );

        if ($event->wasRecentlyCreated) {
            ProcessMomoCallback::dispatch($referenceId);
        }

        Log::info('MoMo callback received', [
            'reference_id' => $referenceId,
            'dispatched' => $event->wasRecentlyCreated,
        ]);

        return response()->noContent(Response::HTTP_OK);
    }
}
