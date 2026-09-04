<?php

namespace Schtzie\FlowField\Tests\Fixtures;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Schtzie\FlowField\Attributes\FlowField;
use Schtzie\FlowField\Concerns\HasFlowFields;

/**
 * TestPost — Navision/ERP analog: a parent model with morphMany children.
 *
 * In Business Central this mirrors entities like Sales Header or Service Order
 * that aggregate over polymorphic child records (comment lines, notes, attachments).
 */
class TestPost extends Model
{
    use HasFlowFields;

    protected $table = 'test_posts';

    protected $guarded = [];

    public function comments()
    {
        return $this->morphMany(TestComment::class, 'commentable');
    }

    // -------------------------------------------------------------------------
    // morphMany FlowFields — all 5 aggregate types
    // -------------------------------------------------------------------------

    /** Total number of comments on this post */
    #[FlowField(method: 'count', relation: 'comments')]
    protected function commentCount(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /** Sum of all comment lengths (character count aggregate) */
    #[FlowField(method: 'sum', relation: 'comments', column: 'length')]
    protected function totalCommentLength(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /** Whether any comments exist for this post */
    #[FlowField(method: 'exists', relation: 'comments')]
    protected function hasComments(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /** Average length of comments on this post */
    #[FlowField(method: 'avg', relation: 'comments', column: 'length')]
    protected function averageCommentLength(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /** Length of the longest comment on this post */
    #[FlowField(method: 'max', relation: 'comments', column: 'length')]
    protected function longestComment(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }
}
