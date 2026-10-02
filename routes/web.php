<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\MessengerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SocialController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => auth()->check()
    ? redirect()->route('feed')
    : redirect()->route('login')
)->name('home');
Route::get('/about', fn () => Inertia::render('Public/Static', [
    'title' => 'About PCHAT', 'description' => 'Learn about PCHAT, a private real-time messaging application.', 'canonicalPath' => '/about', 'heading' => 'About PCHAT', 'intro' => 'PCHAT makes everyday conversations simple, private, and immediate.',
    'sections' => [['title' => 'Built for conversations', 'body' => 'PCHAT gives people a focused place to communicate one-to-one and in groups without making private conversations public.']],
]))->name('about');
Route::get('/features', fn () => Inertia::render('Public/Static', [
    'title' => 'PCHAT Features', 'description' => 'Explore PCHAT real-time chat, group conversations, image sharing, voice messages, online status, and unread messages.', 'canonicalPath' => '/features', 'heading' => 'Messaging features that stay focused', 'intro' => 'Everything you need to keep conversations moving.',
    'sections' => [['title' => 'Real-time chat', 'body' => 'Send and receive messages instantly.'], ['title' => 'Groups and sharing', 'body' => 'Create group conversations and share images and voice messages directly with participants.'], ['title' => 'Stay up to date', 'body' => 'Online status and unread-message indicators help you keep track of what matters.']],
]))->name('features');
Route::get('/privacy', fn () => Inertia::render('Public/Static', [
    'title' => 'PCHAT Privacy', 'description' => 'Read the PCHAT privacy overview.', 'canonicalPath' => '/privacy', 'heading' => 'Privacy', 'intro' => 'PCHAT is designed so private conversations remain behind authenticated access.',
    'sections' => [['title' => 'Private content', 'body' => 'Messages, attachments, and account details are not published as public pages or included in search indexes.']],
]))->name('privacy');
Route::get('/terms', fn () => Inertia::render('Public/Static', [
    'title' => 'PCHAT Terms', 'description' => 'Read the PCHAT terms of use.', 'canonicalPath' => '/terms', 'heading' => 'Terms of use', 'intro' => 'Use PCHAT responsibly and respect the privacy of other participants.',
    'sections' => [['title' => 'Your account', 'body' => 'Keep your account credentials secure and use the service in accordance with applicable law.']],
]))->name('terms');
Route::get('/sitemap.xml', function () {
    abort_unless(config('seo.site_url'), 404);
    $urls = ['/', '/about', '/features', '/privacy', '/terms'];
    $xml = view('sitemap', ['urls' => array_map(fn ($path) => config('seo.site_url').$path, $urls)])->render();

    return response($xml, 200, ['Content-Type' => 'application/xml']);
})->name('sitemap');
Route::middleware(['auth', 'account.active'])->group(function () {
    Route::get('/feed', [SocialController::class, 'feed'])->name('feed');
    Route::get('/social/search', [SocialController::class, 'search'])->middleware('throttle:30,1')->name('social.search');
    Route::get('/search', [SocialController::class, 'searchPage'])->middleware('throttle:30,1')->name('search');
    Route::get('/search/history', [SocialController::class, 'searchHistory'])->name('search.history');
    Route::delete('/search/history', [SocialController::class, 'removeSearchHistory']);
    Route::delete('/search/history/all', [SocialController::class, 'clearSearchHistory']);
    Route::get('/notifications', [SocialController::class, 'notifications'])->name('notifications');
    Route::get('/notifications/data', [SocialController::class, 'notificationsData'])->name('notifications.data');
    Route::post('/notifications/{notification}/read', [SocialController::class, 'readNotification']);
    Route::post('/notifications/read-all', [SocialController::class, 'readAllNotifications']);
    Route::put('/notifications/preferences', [SocialController::class, 'notificationPreferences']);
    Route::get('/posts/create', [SocialController::class, 'createPost'])->name('posts.create');
    Route::get('/posts/{post}', [SocialController::class, 'show'])->name('posts.show');
    Route::post('/stories', [SocialController::class, 'storeStory'])->middleware('throttle:20,1')->name('stories.store');
    Route::get('/stories/{story}/media', [SocialController::class, 'storyMedia'])->name('stories.media');
    Route::post('/stories/{story}/views', [SocialController::class, 'viewStory'])->middleware('throttle:60,1');
    Route::post('/stories/{story}/reactions', [SocialController::class, 'reactStory'])->middleware('throttle:40,1');
    Route::post('/stories/{story}/replies', [SocialController::class, 'replyToStory'])->middleware('throttle:20,1');
    Route::post('/posts', [SocialController::class, 'store'])->middleware('throttle:20,1')->name('posts.store');
    Route::put('/posts/{post}', [SocialController::class, 'update'])->name('posts.update');
    Route::delete('/posts/{post}', [SocialController::class, 'destroy'])->name('posts.destroy');
    Route::post('/posts/{post}/reactions', [SocialController::class, 'react'])->middleware('throttle:40,1');
    Route::post('/posts/{post}/comments', [SocialController::class, 'comment'])->middleware('throttle:20,1');
    Route::get('/posts/{post}/comments', [SocialController::class, 'comments'])->middleware('throttle:30,1');
    Route::get('/comments/{comment}/replies', [SocialController::class, 'replies'])->middleware('throttle:30,1');
    Route::put('/comments/{comment}', [SocialController::class, 'updateComment']);
    Route::delete('/comments/{comment}', [SocialController::class, 'deleteComment']);
    Route::post('/comments/{comment}/reactions', [SocialController::class, 'reactComment'])->middleware('throttle:40,1');
    Route::post('/posts/{post}/share', [SocialController::class, 'share'])->middleware('throttle:10,1');
    Route::post('/posts/{post}/save', [SocialController::class, 'save']);
    Route::post('/posts/{post}/reports', [SocialController::class, 'report'])->middleware('throttle:10,1');
    Route::post('/comments/{comment}/reports', [SocialController::class, 'reportComment'])->middleware('throttle:10,1');
    Route::get('/saved', [SocialController::class, 'saved'])->name('saved');
    Route::post('/users/{user}/friendships', [SocialController::class, 'friend'])->middleware('throttle:10,1');
    Route::post('/friendships/{friendship}', [SocialController::class, 'respondFriend'])->middleware('throttle:10,1');
    Route::delete('/friendships/{friendship}', [SocialController::class, 'cancelFriend'])->middleware('throttle:10,1');
    Route::post('/users/{user}/follow', [SocialController::class, 'follow'])->middleware('throttle:20,1');
    Route::delete('/users/{user}/follow', [SocialController::class, 'unfollow'])->middleware('throttle:20,1');
    Route::get('/friends', [SocialController::class, 'friends'])->name('friends');
    Route::get('/friends/suggestions', [SocialController::class, 'friendSuggestions'])->name('friends.suggestions');
    Route::post('/friends/suggestions/{user}/dismiss', [SocialController::class, 'dismissFriendSuggestion']);
    Route::get('/profile/{user}', [SocialController::class, 'profile'])->name('social.profile');
    Route::get('/profile/{user}/{type}', [SocialController::class, 'connections'])->whereIn('type', ['followers', 'following'])->name('social.connections');
    Route::get('/post-media/{media}', [SocialController::class, 'media'])->name('post-media.show');
    Route::get('/messenger', [MessengerController::class, 'index'])->name('dashboard');
    Route::post('/messenger/presence', [MessengerController::class, 'presence']);
    Route::get('/messenger/users/search', [MessengerController::class, 'users'])->name('messenger.users');
    Route::get('/messenger/conversations', [MessengerController::class, 'list']);
    Route::post('/messenger/conversations/private', [MessengerController::class, 'private']);
    Route::get('/messenger/conversations/{conversation}/messages', [MessengerController::class, 'messages']);
    Route::get('/messenger/conversations/{conversation}/details', [MessengerController::class, 'details']);
    Route::get('/messenger/conversations/{conversation}/search', [MessengerController::class, 'searchMessages']);
    Route::post('/messenger/conversations/{conversation}/messages', [MessengerController::class, 'send']);
    Route::post('/messenger/conversations/{conversation}/read', [MessengerController::class, 'read']);
    Route::post('/messenger/groups', [MessengerController::class, 'group']);
    Route::put('/messenger/messages/{message}', [MessengerController::class, 'update']);
    Route::post('/messenger/messages/{message}/forward', [MessengerController::class, 'forward']);
    Route::delete('/messenger/messages/{message}', [MessengerController::class, 'delete']);
    Route::post('/messenger/messages/{message}/reactions', [MessengerController::class, 'react']);
    Route::post('/messenger/messages/{message}/reports', [MessengerController::class, 'report']);
    Route::get('/messenger/attachments/{message}', [MessengerController::class, 'attachment'])->name('messenger.attachments.show');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
Route::prefix('admin')->name('admin.')->middleware(['auth', 'account.active', 'admin'])->group(function () {
    Route::get('/', fn () => redirect()->route('admin.dashboard'));
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/users', [AdminController::class, 'users'])->name('users.index');
    Route::get('/users/{user}', [AdminController::class, 'user'])->name('users.show');
    Route::post('/users/{user}/moderate', [AdminController::class, 'moderate'])->name('users.moderate');
    Route::get('/conversations', [AdminController::class, 'conversations'])->name('conversations.index');
    Route::get('/groups', [AdminController::class, 'groups'])->name('groups.index');
    Route::post('/groups/{conversation}/moderate', [AdminController::class, 'moderateGroup'])->name('groups.moderate');
    Route::get('/reports', [AdminController::class, 'reports'])->name('reports.index');
    Route::get('/posts', [AdminController::class, 'posts'])->name('posts.index');
    Route::post('/posts/{post}/moderate', [AdminController::class, 'moderatePost'])->name('posts.moderate');
    Route::get('/comments', [AdminController::class, 'comments'])->name('comments.index');
    Route::post('/comments/{comment}/moderate', [AdminController::class, 'moderateComment'])->name('comments.moderate');
    Route::get('/reports/{report}', [AdminController::class, 'report'])->name('reports.show');
    Route::post('/reports/{report}/resolve', [AdminController::class, 'resolveReport'])->name('reports.resolve');
    Route::get('/audit-logs', [AdminController::class, 'auditLogs'])->name('audit-logs.index');
    Route::get('/settings', [AdminController::class, 'settings'])->name('settings');
    Route::put('/settings', [AdminController::class, 'updateSettings'])->name('settings.update');
});
require __DIR__.'/auth.php';
