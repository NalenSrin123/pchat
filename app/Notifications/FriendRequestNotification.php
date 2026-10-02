<?php

namespace App\Notifications;

use App\Models\Friendship;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class FriendRequestNotification extends Notification
{
    use Queueable;

    public function __construct(private Friendship $friendship) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'friend_request', 'friendship_id' => $this->friendship->id, 'user_id' => $this->friendship->sender_id, 'message' => $this->friendship->sender->name.' sent you a friend request.'];
    }
}
