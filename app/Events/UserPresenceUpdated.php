<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserPresenceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public User $user, public array $conversationIds) {}

    public function broadcastOn(): array
    {
        return array_map(fn ($id) => new PrivateChannel('conversation.'.$id), $this->conversationIds);
    }

    public function broadcastAs(): string { return 'presence.updated'; }

    public function broadcastWith(): array
    {
        return ['user_id' => $this->user->id, 'last_seen_at' => $this->user->last_seen_at?->toISOString(), 'is_online' => true];
    }
}
