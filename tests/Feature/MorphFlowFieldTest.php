<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Schtzie\FlowField\Tests\Fixtures\TestComment;
use Schtzie\FlowField\Tests\Fixtures\TestPost;
use Schtzie\FlowField\Tests\Fixtures\TestVideo;

beforeEach(function () {
    $this->post = TestPost::create(['title' => 'Test Post']);
    $this->video = TestVideo::create(['title' => 'Test Video']);
});

// --- Aggregate types over morphMany ---

it('count aggregates only comments of correct morph type', function () {
    TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestPost::class, 'commentable_id' => $this->post->id,
        'body' => 'Post comment 1', 'length' => 13,
    ]));
    TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestPost::class, 'commentable_id' => $this->post->id,
        'body' => 'Post comment 2', 'length' => 13,
    ]));
    TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestVideo::class, 'commentable_id' => $this->video->id,
        'body' => 'Video comment', 'length' => 13,
    ]));

    expect($this->post->comment_count)->toBe(2);
    expect($this->video->comment_count)->toBe(1);
});

it('sum aggregates lengths per morph type', function () {
    TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestPost::class, 'commentable_id' => $this->post->id,
        'body' => 'A', 'length' => 100,
    ]));
    TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestPost::class, 'commentable_id' => $this->post->id,
        'body' => 'B', 'length' => 200,
    ]));
    TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestVideo::class, 'commentable_id' => $this->video->id,
        'body' => 'V', 'length' => 999,
    ]));

    expect((float) $this->post->total_comment_length)->toBe(300.0);
    expect((float) $this->video->total_comment_length)->toBe(999.0);
});

it('exists is true only when comments exist for correct morph type', function () {
    TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestVideo::class, 'commentable_id' => $this->video->id,
        'body' => 'V', 'length' => 5,
    ]));

    expect($this->post->has_comments)->toBeFalse();
    expect($this->video->has_comments)->toBeTrue();
});

it('avg and max are morph-type isolated', function () {
    TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestPost::class, 'commentable_id' => $this->post->id,
        'body' => 'Short', 'length' => 10,
    ]));
    TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestPost::class, 'commentable_id' => $this->post->id,
        'body' => 'Long', 'length' => 90,
    ]));
    TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestVideo::class, 'commentable_id' => $this->video->id,
        'body' => 'X', 'length' => 9999,
    ]));

    expect((float) $this->post->average_comment_length)->toEqualWithDelta(50.0, 0.01);
    expect((float) $this->post->longest_comment)->toBe(90.0);
});

// --- Cache invalidation ---

it('creating comment invalidates morph parent cache', function () {
    $this->post->calcFlowFields('comment_count');
    $key = "flowfield:test_posts:{$this->post->id}:comment_count";
    expect(Cache::store('array')->get($key))->not->toBeNull();

    TestComment::create([
        'commentable_type' => TestPost::class, 'commentable_id' => $this->post->id,
        'body' => 'Hello', 'length' => 5,
    ]);

    expect(Cache::store('array')->get($key))->toBeNull();
    expect(TestPost::find($this->post->id)->comment_count)->toBe(1);
});

it('updating comment length invalidates morph parent cache', function () {
    $comment = TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestPost::class, 'commentable_id' => $this->post->id,
        'body' => 'Hi', 'length' => 10,
    ]));

    expect((float) $this->post->total_comment_length)->toBe(10.0);
    $key = "flowfield:test_posts:{$this->post->id}:total_comment_length";
    expect(Cache::store('array')->get($key))->not->toBeNull();

    $comment->update(['length' => 50]);

    expect(Cache::store('array')->get($key))->toBeNull();
    expect((float) TestPost::find($this->post->id)->total_comment_length)->toBe(50.0);
});

it('deleting comment invalidates morph parent cache', function () {
    $comment = TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestPost::class, 'commentable_id' => $this->post->id,
        'body' => 'Delete me', 'length' => 20,
    ]));

    expect($this->post->comment_count)->toBe(1);
    $key = "flowfield:test_posts:{$this->post->id}:comment_count";
    expect(Cache::store('array')->get($key))->not->toBeNull();

    $comment->delete();

    expect(Cache::store('array')->get($key))->toBeNull();
    expect(TestPost::find($this->post->id)->comment_count)->toBe(0);
});

it('updating irrelevant column does not invalidate cache', function () {
    $comment = TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestPost::class, 'commentable_id' => $this->post->id,
        'body' => 'Original', 'length' => 30,
    ]));

    $this->post->calcFlowFields('total_comment_length');
    $key = "flowfield:test_posts:{$this->post->id}:total_comment_length";
    $valueBefore = Cache::store('array')->get($key);

    $comment->update(['body' => 'Updated body only']);

    expect(Cache::store('array')->get($key))->toBe($valueBefore);
});

// --- Morph parent reassignment ---

it('reassigning comment to different morph parent invalidates both', function () {
    $post2 = TestPost::create(['title' => 'Second Post']);

    $comment = TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestPost::class, 'commentable_id' => $this->post->id,
        'body' => 'Moveable', 'length' => 40,
    ]));

    $this->post->calcFlowFields('comment_count');
    $post2->calcFlowFields('comment_count');

    $key1 = "flowfield:test_posts:{$this->post->id}:comment_count";
    $key2 = "flowfield:test_posts:{$post2->id}:comment_count";

    expect(Cache::store('array')->get($key1))->not->toBeNull();
    expect(Cache::store('array')->get($key2))->not->toBeNull();

    $comment->update(['commentable_type' => TestPost::class, 'commentable_id' => $post2->id]);

    expect(Cache::store('array')->get($key1))->toBeNull();
    expect(Cache::store('array')->get($key2))->toBeNull();

    expect(TestPost::find($this->post->id)->comment_count)->toBe(0);
    expect(TestPost::find($post2->id)->comment_count)->toBe(1);
});

it('reassigning comment to different morph type invalidates both', function () {
    $comment = TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestPost::class, 'commentable_id' => $this->post->id,
        'body' => 'Cross-type move', 'length' => 15,
    ]));

    $this->post->calcFlowFields('comment_count');
    $this->video->calcFlowFields('comment_count');

    $postKey = "flowfield:test_posts:{$this->post->id}:comment_count";
    $videoKey = "flowfield:test_videos:{$this->video->id}:comment_count";

    expect(Cache::store('array')->get($postKey))->not->toBeNull();
    expect(Cache::store('array')->get($videoKey))->not->toBeNull();

    $comment->update(['commentable_type' => TestVideo::class, 'commentable_id' => $this->video->id]);

    expect(Cache::store('array')->get($postKey))->toBeNull();
    expect(Cache::store('array')->get($videoKey))->toBeNull();

    expect(TestPost::find($this->post->id)->comment_count)->toBe(0);
    expect(TestVideo::find($this->video->id)->comment_count)->toBe(1);
});

// --- orderByFlowField with morphMany ---

it('orderByFlowField sorts posts correctly with morph isolation', function () {
    $post2 = TestPost::create(['title' => 'Popular Post']);
    $post3 = TestPost::create(['title' => 'Silent Post']);

    foreach ([1] as $i) {
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class, 'commentable_id' => $this->post->id,
            'body' => 'C1', 'length' => 1,
        ]));
    }
    foreach ([1, 2, 3] as $i) {
        TestComment::withoutEvents(fn () => TestComment::create([
            'commentable_type' => TestPost::class, 'commentable_id' => $post2->id,
            'body' => "C{$i}", 'length' => 1,
        ]));
    }
    // Video comment must NOT affect post ordering
    TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestVideo::class, 'commentable_id' => $this->video->id,
        'body' => 'V1', 'length' => 9999,
    ]));

    $posts = TestPost::orderByFlowField('comment_count', 'desc')->get();

    expect($posts->first()->title)->toBe('Popular Post');
    expect($posts->last()->title)->toBe('Silent Post');
});

// --- withFlowFields bulk warm ---

it('withFlowFields pre-warms cache for morph aggregates', function () {
    TestComment::withoutEvents(fn () => TestComment::create([
        'commentable_type' => TestPost::class, 'commentable_id' => $this->post->id,
        'body' => 'Warm test', 'length' => 25,
    ]));

    $posts = TestPost::withFlowFields('comment_count', 'total_comment_length')->get();

    $queryCount = 0;
    DB::listen(fn () => $queryCount++);

    foreach ($posts as $p) {
        $p->comment_count;
        $p->total_comment_length;
    }

    expect($queryCount)->toBe(0);
});
