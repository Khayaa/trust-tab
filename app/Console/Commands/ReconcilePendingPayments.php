<?php

namespace App\Console\Commands;

use App\Actions\ApplyMomoResult;
use App\Enums\PaymentRequestStatus;
use App\Exceptions\MomoRequestFailed;
use App\Models\PaymentRequest;
use Illuminate\Console\Command;

class ReconcilePendingPayments extends Command
{
    protected $signature = 'payments:reconcile-pending {--minutes=1 : Only touch requests older than this}';

    protected $description = 'Ask MoMo about payments still waiting on a final status';

    /**
     * MoMo sends a callback once and never retries, so a dropped callback would
     * otherwise strand a payment as pending forever. This is the safety net,
     * and it is what lets the callback host stay optional.
     */
    public function handle(ApplyMomoResult $applyResult): int
    {
        $stale = PaymentRequest::query()
            ->awaitingFinalStatus()
            ->where('created_at', '<=', now()->subMinutes((int) $this->option('minutes')))
            ->orderBy('id')
            ->get();

        if ($stale->isEmpty()) {
            $this->components->info('Nothing pending.');

            return self::SUCCESS;
        }

        foreach ($stale as $paymentRequest) {
            if ($this->abandon($paymentRequest)) {
                continue;
            }

            try {
                $applied = $applyResult->handle($paymentRequest);

                $this->components->twoColumnDetail(
                    $paymentRequest->reference_id,
                    $applied->status->value,
                );
            } catch (MomoRequestFailed $exception) {
                $this->components->twoColumnDetail(
                    $paymentRequest->reference_id,
                    '<fg=red>'.$exception->getMessage().'</>',
                );
            }
        }

        return self::SUCCESS;
    }

    /**
     * A request with no requested_at never reached MoMo: the row committed and
     * then something failed before the call went out. Asking MoMo about a
     * reference it has never seen returns 404 forever, so these are closed
     * instead of retried. Safe because this only runs on rows old enough that
     * an in-flight send would have finished.
     */
    protected function abandon(PaymentRequest $paymentRequest): bool
    {
        if ($paymentRequest->requested_at !== null) {
            return false;
        }

        $paymentRequest->status = PaymentRequestStatus::Failed;
        $paymentRequest->failure_reason = 'NEVER_SENT';
        $paymentRequest->completed_at = now();
        $paymentRequest->save();

        $this->components->twoColumnDetail(
            $paymentRequest->reference_id,
            '<fg=yellow>never reached MoMo, closed</>',
        );

        return true;
    }
}
