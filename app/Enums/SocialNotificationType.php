<?php

namespace App\Enums;

enum SocialNotificationType: string
{
    case POST_REACTION = 'post_reaction';
    case POST_COMMENT = 'post_comment';
    case POST_SHARE = 'post_share';
    case COMMENT_REACTION = 'comment_reaction';
    case COMMENT_REPLY = 'comment_reply';
    case REPLY_REACTION = 'reply_reaction';
    case POST_MENTION = 'post_mention';
    case COMMENT_MENTION = 'comment_mention';
    case REPLY_MENTION = 'reply_mention';
    case FRIEND_REQUEST = 'friend_request';
    case FRIEND_ACCEPTED = 'friend_accepted';
    case FOLLOW = 'follow';
}
