<?php

namespace App\Actions\Remarks;

use App\Models\Remark;
use Illuminate\Database\Eloquent\Model;

/**
 * Places a remark on a record, internal or for the customer to see. The remark
 * field on the screen and a macro place it the same way.
 */
class AddRemarkAction
{
    /**
     * @param  Model  $remarkable  Anything with a remarks() morph relation.
     */
    public function execute(Model $remarkable, string $content, bool $internal, int $user_id): Remark
    {
        $remark = Remark::create(['content' => $content, 'user_id' => $user_id]);
        $remarkable->remarks()->attach($remark->id, ['internal' => $internal]);

        return $remark;
    }
}
