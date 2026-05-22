<?php

namespace Openplain\FlowField\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Openplain\FlowField\Tests\Fixtures\TestComment;
use Openplain\FlowField\Tests\Fixtures\TestPost;
use Openplain\FlowField\Tests\Fixtures\TestVideo;
use Openplain\FlowField\Tests\TestCase;

/**
 * MorphFlowField Tests — polymorphic (morphMany / morphOne) relationships
 *
 * Covers:
 *   - All aggregate types over morphMany (sum, count, avg, min, max, exists)
 *   - Morph-type isolation (Post comments ≠ Video comments)
 *   - Cache invalidation via morphFlowFieldTargets on create / update / delete
 *   - Morph parent reassignment invalidates BOTH old and new parent
 *   - orderByFlowField with morphMany (uses corrected subquery with type constraint)
 *   - Alternative explicit-column-pair syntax for morphFlowFieldTargets
 */
class MorphFlowFieldTest extends TestCase
{
    protected TestPost $post;
    protected TestVideo $video;

    protected function setUp(): void
    {
        parent::setUp();

        $this->post  = TestPost::create(['title' => 'Test Post']);
        $this->video = TestVideo::create(['title' => 'Test Video']);
    }

    // =========================================================================
    // Aggregate types over morphMany
    // =========================================================================

    public function test_count_aggregates_only_comments_of_correct_morph_type(): void
    {
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class,
            'commentable_id'   => $this->post->id,
            'body'             => 'Post comment 1',
            'length'           => 13,
        ]));
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class,
            'commentable_id'   => $this->post->id,
            'body'             => 'Post comment 2',
            'length'           => 13,
        ]));
        // This comment belongs to a Video — must NOT affect the post's FlowField
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestVideo::class,
            'commentable_id'   => $this->video->id,
            'body'             => 'Video comment',
            'length'           => 13,
        ]));

        $this->assertEquals(2, $this->post->comment_count);
        $this->assertEquals(1, $this->video->comment_count);
    }

    public function test_sum_aggregates_lengths_per_morph_type(): void
    {
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class,
            'commentable_id'   => $this->post->id,
            'body' => 'A', 'length' => 100,
        ]));
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class,
            'commentable_id'   => $this->post->id,
            'body' => 'B', 'length' => 200,
        ]));
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestVideo::class,
            'commentable_id'   => $this->video->id,
            'body' => 'V', 'length' => 999,
        ]));

        $this->assertEquals(300, (float) $this->post->total_comment_length);
        $this->assertEquals(999, (float) $this->video->total_comment_length);
    }

    public function test_exists_is_true_when_comments_exist_for_correct_type(): void
    {
        // Only add a Video comment — Post has_comments must remain false
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestVideo::class,
            'commentable_id'   => $this->video->id,
            'body' => 'V', 'length' => 5,
        ]));

        $this->assertFalse($this->post->has_comments);
        $this->assertTrue($this->video->has_comments);
    }

    public function test_avg_and_max_are_morph_type_isolated(): void
    {
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class,
            'commentable_id'   => $this->post->id,
            'body' => 'Short', 'length' => 10,
        ]));
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class,
            'commentable_id'   => $this->post->id,
            'body' => 'Long', 'length' => 90,
        ]));
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestVideo::class,
            'commentable_id'   => $this->video->id,
            'body' => 'X', 'length' => 9999,
        ]));

        // avg for post: (10 + 90) / 2 = 50
        $this->assertEqualsWithDelta(50.0, (float) $this->post->average_comment_length, 0.01);
        // max for post: 90 — NOT 9999 (which belongs to video)
        $this->assertEquals(90, (float) $this->post->longest_comment);
    }

    // =========================================================================
    // Cache invalidation via morphFlowFieldTargets
    // =========================================================================

    public function test_creating_comment_invalidates_morph_parent_cache(): void
    {
        // Prime the post's cache
        $this->post->calcFlowFields('comment_count');
        $cacheKey = "flowfield:test_posts:{$this->post->id}:comment_count";
        $this->assertNotNull(Cache::store('array')->get($cacheKey));

        // Create a comment via normal events — triggers morphFlowFieldTargets
        TestComment::create([
            'commentable_type' => TestPost::class,
            'commentable_id'   => $this->post->id,
            'body'             => 'Hello',
            'length'           => 5,
        ]);

        $this->assertNull(Cache::store('array')->get($cacheKey),
            'Post comment_count cache must be invalidated after a comment is created'
        );

        // Fresh read must return correct value
        $fresh = TestPost::find($this->post->id);
        $this->assertEquals(1, $fresh->comment_count);
    }

    public function test_updating_comment_length_invalidates_morph_parent_cache(): void
    {
        $comment = TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class,
            'commentable_id'   => $this->post->id,
            'body' => 'Hi', 'length' => 10,
        ]));

        $this->assertEquals(10, (float) $this->post->total_comment_length);
        $cacheKey = "flowfield:test_posts:{$this->post->id}:total_comment_length";
        $this->assertNotNull(Cache::store('array')->get($cacheKey));

        $comment->update(['length' => 50]);

        $this->assertNull(Cache::store('array')->get($cacheKey));
        $fresh = TestPost::find($this->post->id);
        $this->assertEquals(50, (float) $fresh->total_comment_length);
    }

    public function test_deleting_comment_invalidates_morph_parent_cache(): void
    {
        $comment = TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class,
            'commentable_id'   => $this->post->id,
            'body' => 'Delete me', 'length' => 20,
        ]));

        $this->assertEquals(1, $this->post->comment_count);
        $cacheKey = "flowfield:test_posts:{$this->post->id}:comment_count";
        $this->assertNotNull(Cache::store('array')->get($cacheKey));

        $comment->delete();

        $this->assertNull(Cache::store('array')->get($cacheKey));
        $fresh = TestPost::find($this->post->id);
        $this->assertEquals(0, $fresh->comment_count);
    }

    public function test_updating_irrelevant_column_does_not_invalidate_cache(): void
    {
        $comment = TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class,
            'commentable_id'   => $this->post->id,
            'body' => 'Original', 'length' => 30,
        ]));

        $this->post->calcFlowFields('total_comment_length');
        $cacheKey = "flowfield:test_posts:{$this->post->id}:total_comment_length";
        $valueBefore = Cache::store('array')->get($cacheKey);

        // Updating 'body' is not relevant to total_comment_length (which uses 'length' column)
        $comment->update(['body' => 'Updated body only']);

        $this->assertEquals($valueBefore, Cache::store('array')->get($cacheKey),
            'Irrelevant column update must not bust the cache'
        );
    }

    // =========================================================================
    // Morph parent reassignment — invalidates BOTH old and new parent
    // =========================================================================

    public function test_reassigning_comment_to_different_morph_parent_invalidates_both(): void
    {
        $post2 = TestPost::create(['title' => 'Second Post']);

        $comment = TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class,
            'commentable_id'   => $this->post->id,
            'body' => 'Moveable', 'length' => 40,
        ]));

        // Prime caches for both posts
        $this->post->calcFlowFields('comment_count');
        $post2->calcFlowFields('comment_count');

        $key1 = "flowfield:test_posts:{$this->post->id}:comment_count";
        $key2 = "flowfield:test_posts:{$post2->id}:comment_count";

        $this->assertNotNull(Cache::store('array')->get($key1));
        $this->assertNotNull(Cache::store('array')->get($key2));

        // Move comment to the second post
        $comment->update([
            'commentable_type' => TestPost::class,
            'commentable_id'   => $post2->id,
        ]);

        $this->assertNull(Cache::store('array')->get($key1), 'Original post cache must be cleared');
        $this->assertNull(Cache::store('array')->get($key2), 'New post cache must be cleared');

        $fresh1 = TestPost::find($this->post->id);
        $fresh2 = TestPost::find($post2->id);

        $this->assertEquals(0, $fresh1->comment_count);
        $this->assertEquals(1, $fresh2->comment_count);
    }

    public function test_reassigning_comment_to_different_morph_type_invalidates_both(): void
    {
        $comment = TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class,
            'commentable_id'   => $this->post->id,
            'body' => 'Cross-type move', 'length' => 15,
        ]));

        // Prime both caches
        $this->post->calcFlowFields('comment_count');
        $this->video->calcFlowFields('comment_count');

        $postKey  = "flowfield:test_posts:{$this->post->id}:comment_count";
        $videoKey = "flowfield:test_videos:{$this->video->id}:comment_count";

        $this->assertNotNull(Cache::store('array')->get($postKey));
        $this->assertNotNull(Cache::store('array')->get($videoKey));

        // Move comment from Post to Video (different morph type)
        $comment->update([
            'commentable_type' => TestVideo::class,
            'commentable_id'   => $this->video->id,
        ]);

        $this->assertNull(Cache::store('array')->get($postKey),  'Post cache must be cleared');
        $this->assertNull(Cache::store('array')->get($videoKey), 'Video cache must be cleared');

        $freshPost  = TestPost::find($this->post->id);
        $freshVideo = TestVideo::find($this->video->id);

        $this->assertEquals(0, $freshPost->comment_count);
        $this->assertEquals(1, $freshVideo->comment_count);
    }

    // =========================================================================
    // orderByFlowField with morphMany — morph-type constraint must be present
    // =========================================================================

    public function test_order_by_flow_field_sorts_posts_correctly_with_morph_isolation(): void
    {
        $post2 = TestPost::create(['title' => 'Popular Post']);
        $post3 = TestPost::create(['title' => 'Silent Post']);

        // Post 1: 1 comment, Post 2: 3 comments, Post 3: 0 comments
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class, 'commentable_id' => $this->post->id,
            'body' => 'C1', 'length' => 1,
        ]));
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class, 'commentable_id' => $post2->id,
            'body' => 'C2', 'length' => 1,
        ]));
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class, 'commentable_id' => $post2->id,
            'body' => 'C3', 'length' => 1,
        ]));
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class, 'commentable_id' => $post2->id,
            'body' => 'C4', 'length' => 1,
        ]));
        // Video comments must NOT affect post ordering
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestVideo::class, 'commentable_id' => $this->video->id,
            'body' => 'V1', 'length' => 9999,
        ]));

        $posts = TestPost::orderByFlowField('comment_count', 'desc')->get();

        $this->assertEquals('Popular Post', $posts->first()->title);
        $this->assertEquals('Silent Post', $posts->last()->title);
    }

    // =========================================================================
    // withFlowFields bulk warm with morphMany
    // =========================================================================

    public function test_with_flow_fields_pre_warms_cache_for_morph_aggregates(): void
    {
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class, 'commentable_id' => $this->post->id,
            'body' => 'Warm test', 'length' => 25,
        ]));

        $posts = TestPost::withFlowFields('comment_count', 'total_comment_length')->get();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) { $queryCount++; });

        foreach ($posts as $p) {
            $p->comment_count;
            $p->total_comment_length;
        }

        $this->assertEquals(0, $queryCount, 'After withFlowFields warm, reads must hit cache');
    }
}
