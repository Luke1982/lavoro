<?php

namespace App\Jobs;

use App\Domain\ServiceOrders\BulkStageMove;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

/**
 * Moves a large selection of werkbonnen to another stage in the background.
 *
 * Each order is moved individually rather than by one mass update, because a
 * stage move announces itself and that is what keeps the history and the closing
 * date correct. That costs a query per order, which is why anything past a small
 * selection comes through here instead of blocking the request.
 */
class BulkMoveServiceOrderStageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @param  array<int, int>  $service_order_ids */
    public function __construct(
        public array $service_order_ids,
        public ?int $service_order_stage_id,
        public ?int $actor_id = null,
    ) {}

    /**
     * Each werkbon is held to the same rules as in the request that asked for the
     * move, as the person who asked: without them there is nobody to ask for, so
     * nothing moves.
     */
    public function handle(): void
    {
        $stage = $this->service_order_stage_id === null
            ? null
            : ServiceOrderStage::find($this->service_order_stage_id);
        $actor = $this->actor_id === null ? null : User::find($this->actor_id);

        if ($stage === null || $actor === null) {
            return;
        }

        /**
         * The signals raised below read the actor from the session, which a queue
         * worker does not have. Restoring it keeps the history naming the person
         * who asked for the move rather than the system.
         */
        Auth::setUser($actor);

        try {
            app(BulkStageMove::class)->move($actor, ServiceOrder::whereIn('id', $this->service_order_ids)->get(), $stage);
        } finally {
            Auth::forgetUser();
        }
    }
}
