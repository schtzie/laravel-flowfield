<?php

namespace Schtzie\FlowField\Tests\Fixtures;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Schtzie\FlowField\Attributes\FlowField;
use Schtzie\FlowField\Concerns\HasFlowFields;

/**
 * TestVideo — second morph parent used to verify type-isolation.
 *
 * A Video and a Post share the same TestComment child table.
 * FlowField aggregates on TestPost must NOT count comments that belong
 * to TestVideo, and vice versa.
 */
class TestVideo extends Model
{
    use HasFlowFields;

    protected $table = 'test_videos';

    protected $guarded = [];

    public function comments()
    {
        return $this->morphMany(TestComment::class, 'commentable');
    }

    /** Number of comments on this video */
    #[FlowField(method: 'count', relation: 'comments')]
    protected function commentCount(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /** Sum of all comment lengths on this video */
    #[FlowField(method: 'sum', relation: 'comments', column: 'length')]
    protected function totalCommentLength(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /** Whether any comments exist for this video */
    #[FlowField(method: 'exists', relation: 'comments')]
    protected function hasComments(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }
}
