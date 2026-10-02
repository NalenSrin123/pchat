<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_reactions_notify_the_owner_once_and_never_notify_the_actor(): void
    {
        [$owner, $actor] = User::factory()->count(2)->create();
        $post = Post::create(['user_id' => $owner->id, 'content' => 'Hello', 'privacy' => 'public']);

        $this->actingAs($actor)->postJson("/posts/{$post->id}/reactions", ['reaction' => '👍'])->assertOk();
        $this->actingAs($actor)->postJson("/posts/{$post->id}/reactions", ['reaction' => '❤️'])->assertOk();

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $owner->id]);
        $this->actingAs($owner)->postJson("/posts/{$post->id}/reactions", ['reaction' => '👍'])->assertOk();
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_reply_notifies_its_comment_author_but_not_the_post_owner(): void
    {
        [$postOwner, $commentAuthor, $actor] = User::factory()->count(3)->create();
        $post = Post::create(['user_id' => $postOwner->id, 'content' => 'Hello', 'privacy' => 'public']);
        $comment = Comment::create(['post_id' => $post->id, 'user_id' => $commentAuthor->id, 'content' => 'First']);

        $this->actingAs($actor)->postJson("/posts/{$post->id}/comments", ['content' => '@commentAuthor agreed', 'parent_id' => $comment->id, 'mentions' => [$commentAuthor->id]])->assertCreated();

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $commentAuthor->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $postOwner->id]);
    }
}
