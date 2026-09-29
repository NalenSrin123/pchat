<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $conversationId, public int $userId, public int $messageId, public string $readAt) {}

    public function broadcastOn(): array { return [new PrivateChannel('conversation.'.$this->conversationId)]; }
    public function broadcastAs(): string { return 'message.read'; }
    public function broadcastWith(): array { return ['user_id' => $this->userId, 'message_id' => $this->messageId, 'read_at' => $this->readAt]; }
}
