<?php

namespace Tests\Feature;

use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoryInteractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_viewer_can_react_to_and_reply_to_a_public_story(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $story = Story::create([
            'user_id' => $author->id,
            'content' => 'A public story',
            'privacy' => 'public',
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($viewer)->postJson("/stories/{$story->id}/views")
            ->assertOk()->assertJsonPath('data.views_count', 1);
        $this->actingAs($viewer)->postJson("/stories/{$story->id}/views")
            ->assertOk()->assertJsonPath('data.views_count', 1);

        $this->actingAs($viewer)->postJson("/stories/{$story->id}/reactions", ['reaction' => '❤️'])
            ->assertOk()->assertJsonPath('data.my_reaction', '❤️');

        $this->actingAs($viewer)->postJson("/stories/{$story->id}/replies", ['message' => 'Nice story'])
            ->assertCreated()->assertJsonPath('data.message', 'Nice story')->assertJsonPath('data.story.id', $story->id);

        $this->actingAs($author)->postJson("/stories/{$story->id}/views")
            ->assertOk()->assertJsonPath('data.views_count', 1)
            ->assertJsonPath('data.viewers.0.id', $viewer->id)
            ->assertJsonPath('data.reactions.0.user.id', $viewer->id);
    }
}
