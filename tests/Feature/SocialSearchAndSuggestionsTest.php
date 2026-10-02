<?php

namespace Tests\Feature;

use App\Models\Friendship;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialSearchAndSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_uses_post_visibility_rules(): void
    {
        [$owner, $friend, $follower, $stranger] = User::factory()->count(4)->create()->all();
        Post::create(['user_id' => $owner->id, 'content' => 'public search item', 'privacy' => 'public']);
        Post::create(['user_id' => $owner->id, 'content' => 'friends search item', 'privacy' => 'friends']);
        Post::create(['user_id' => $owner->id, 'content' => 'private search item', 'privacy' => 'only_me']);
        Friendship::create(['sender_id' => $owner->id, 'receiver_id' => $friend->id, 'status' => 'accepted']);
        $follower->following()->attach($owner->id);

        $this->actingAs($stranger)->getJson('/social/search?q=friends')->assertJsonCount(0, 'posts');
        $this->actingAs($follower)->getJson('/social/search?q=friends')->assertJsonCount(0, 'posts');
        $this->actingAs($friend)->getJson('/social/search?q=friends')->assertJsonCount(1, 'posts');
        $this->actingAs($owner)->getJson('/social/search?q=private')->assertJsonCount(1, 'posts');
    }

    public function test_feed_shows_only_up_to_seven_active_friends_as_contacts(): void
    {
        $viewer = User::factory()->create();
        $onlineFriends = User::factory()->count(8)->create();
        $onlineFriends->each(fn (User $friend, int $index) => $friend->update(['last_seen_at' => now()->subSeconds($index)]));
        $offlineFriend = User::factory()->create(['last_seen_at' => now()->subMinutes(3)]);

        foreach ($onlineFriends->push($offlineFriend) as $friend) {
            Friendship::create(['sender_id' => $viewer->id, 'receiver_id' => $friend->id, 'status' => 'accepted']);
        }

        $this->actingAs($viewer)->get('/feed')->assertInertia(fn ($page) => $page->component('Feed/Index')
            ->has('contacts', 7)
            ->where('contacts.0.id', $onlineFriends->first()->id)
            ->missing('contacts.7'));
    }

    public function test_mutual_friend_is_suggested_but_pending_and_dismissed_people_are_not(): void
    {
        [$viewer, $mutual, $candidate, $pending, $suspended] = User::factory()->count(5)->create()->all();
        $suspended->update(['status' => 'suspended']);
        Friendship::create(['sender_id' => $viewer->id, 'receiver_id' => $mutual->id, 'status' => 'accepted']);
        Friendship::create(['sender_id' => $mutual->id, 'receiver_id' => $candidate->id, 'status' => 'accepted']);
        Friendship::create(['sender_id' => $viewer->id, 'receiver_id' => $pending->id, 'status' => 'pending']);
        Friendship::create(['sender_id' => $mutual->id, 'receiver_id' => $pending->id, 'status' => 'accepted']);
        Friendship::create(['sender_id' => $mutual->id, 'receiver_id' => $suspended->id, 'status' => 'accepted']);
        $candidate->update(['username' => 'suggested-candidate']);

        $this->actingAs($viewer)->get('/friends/suggestions')->assertInertia(fn ($page) => $page->component('Social/FriendSuggestions')->has('suggestions.data', 1)->where('suggestions.data.0.id', $candidate->id)->where('suggestions.data.0.mutual_friends_count', 1));
        $this->actingAs($viewer)->postJson("/friends/suggestions/{$candidate->id}/dismiss")->assertOk();
        $this->actingAs($viewer)->get('/friends/suggestions')->assertInertia(fn ($page) => $page->component('Social/FriendSuggestions')->has('suggestions.data', 0));
        $this->actingAs($viewer)->getJson('/social/search?q=suggested')->assertJsonFragment(['id' => $candidate->id]);
    }

    public function test_friend_request_appears_in_the_recipient_notification_list(): void
    {
        [$sender, $recipient] = User::factory()->count(2)->create()->all();

        $this->actingAs($sender)->postJson("/users/{$recipient->id}/friendships")->assertCreated();
        $this->assertDatabaseCount('notifications', 1);
        $this->actingAs($recipient)->getJson('/notifications')->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.type', 'friend_request')
            ->assertJsonPath('data.0.person.id', $sender->id);
    }
}
