<?php

namespace Openplain\FlowField\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Openplain\FlowField\Concerns\InvalidatesFlowFields;

/**
 * TestComment — polymorphic child that belongs to either TestPost or TestVideo.
 *
 * Uses morphFlowFieldTargets to automatically invalidate whichever morph parent
 * (Post or Video) it belongs to whenever it is created, updated, or deleted.
 *
 * This is the equivalent of a Business Central "Comment Line" that can belong
 * to multiple document types via a polymorphic Document Type / Document No. key.
 */
class TestComment extends Model
{
    use InvalidatesFlowFields;

    protected $table = 'test_comments';

    protected $guarded = [];

    /**
     * Regular targets — none for this model (it only has a morph parent).
     */
    protected array $flowFieldTargets = [];

    /**
     * Polymorphic targets — resolves commentable_type + commentable_id at
     * runtime to find and invalidate the morph parent's FlowFields.
     */
    protected array $morphFlowFieldTargets = ['commentable'];

    public function commentable()
    {
        return $this->morphTo();
    }
}
