<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class FollowedNotification extends Notification
{
    use Queueable;

    public function __construct(private User $follower) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'follow', 'user_id' => $this->follower->id, 'message' => $this->follower->name.' started following you.'];
    }
}
