<?php

namespace App\Jobs;

use App\Actions\ApplyMomoResult;
use App\Models\MomoWebhookEvent;
use App\Models\PaymentRequest;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class ProcessMomoCallback implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $referenceId) {}

    /**
     * One in-flight job per reference. Two callbacks for the same payment
     * cannot race each other into settling the same entries twice.
     */
    public function uniqueId(): string
    {
        return $this->referenceId;
    }

    public function handle(ApplyMomoResult $applyResult): void
    {
        Context::add('momo_reference_id', $this->referenceId);

        $paymentRequest = PaymentRequest::where('reference_id', $this->referenceId)->first();

        if ($paymentRequest === null) {
            Log::info('MoMo callback ignored unknown payment', [
                'reference_id' => $this->referenceId,
            ]);

            return;
        }

        $applied = $applyResult->handle($paymentRequest);

        MomoWebhookEvent::where('reference_id', $this->referenceId)
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        Log::info('MoMo callback applied', [
            'reference_id' => $this->referenceId,
            'status' => $applied->status->value,
        ]);
    }
}
