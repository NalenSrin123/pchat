<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialPostInteractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_user_can_interact_with_a_public_post(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $post = Post::create([
            'user_id' => $author->id,
            'content' => 'A public post',
            'privacy' => 'public',
        ]);

        $this->actingAs($viewer)
            ->postJson("/posts/{$post->id}/reactions", ['reaction' => '👍'])
            ->assertOk()
            ->assertJsonPath('data.my_reaction', '👍');

        $this->actingAs($viewer)
            ->postJson("/posts/{$post->id}/comments", ['content' => 'Nice post'])
            ->assertCreated()
            ->assertJsonPath('data.content', 'Nice post');

        $this->actingAs($viewer)
            ->postJson("/posts/{$post->id}/share", ['content' => 'Worth sharing', 'privacy' => 'friends'])
            ->assertCreated()
            ->assertJsonPath('data.shared_post.id', $post->id)
            ->assertJsonPath('data.content', 'Worth sharing');
    }

    public function test_public_media_url_stays_on_the_current_host(): void
    {
        $media = new PostMedia([
            'disk' => 'public',
            'path' => 'social/images/example.jpg',
        ]);

        $this->assertSame('/storage/social/images/example.jpg', $media->url);
    }
}
