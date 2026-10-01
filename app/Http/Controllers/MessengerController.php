<?php

namespace App\Http\Controllers;

use App\Events\{MessageRead, MessageSent, MessengerPayload, UserPresenceUpdated};
use App\Models\{Conversation, ConversationMember, Message, MessageReaction, MessageReport, User};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Validation\Rule;
use App\Models\AppSetting;
use Inertia\Inertia;

class MessengerController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $this->touchPresence($user);

        return Inertia::render("Messenger/Index", [
            "conversations" => $this->conversations($user),
            "currentUser" => $this->userData($user),
        ]);
    }

    public function presence(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->touchPresence($user);

        return response()->json(["data" => $this->conversations($user->fresh())]);
    }

    public function users(Request $request): JsonResponse
    {
        $q = trim((string)$request->query('q'));
        return response()->json(['data' => User::query()->whereKeyNot($request->user()->id)->when($q, fn($x) => $x->where(fn($y) => $y->where('name', 'like', "%$q%")->orWhere('username', 'like', "%$q%")->orWhere('email', 'like', "%$q%")))->limit(12)->get()->map(fn($u) => $this->userData($u))]);
    }
    public function list(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->conversations($request->user())]);
    }
    public function private(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', Rule::exists('users', 'id'), Rule::notIn([$request->user()->id])]]);
        $other = (int)$data['user_id'];
        $conversation = DB::transaction(function () use ($request, $other) {
            $existing = Conversation::where('type', 'private')->whereHas('memberships', fn($q) => $q->where('user_id', $request->user()->id))->whereHas('memberships', fn($q) => $q->where('user_id', $other))->withCount('memberships')->get()->firstWhere('memberships_count', 2);
            if ($existing) return $existing;
            $c = Conversation::create(['type' => 'private', 'created_by' => $request->user()->id]);
            $c->memberships()->createMany([['user_id' => $request->user()->id, 'role' => 'admin'], ['user_id' => $other]]);
            return $c;
        });
        return response()->json(['data' => $this->conversationData($conversation, $request->user())]);
    }
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->member($conversation, $request->user());
        $messages = $conversation->messages()->with(['sender:id,name,username,email,avatar', 'replyTo.sender:id,name', 'reactions'])->latest('id')->cursorPaginate(40);
        $receipts = $conversation->memberships()->get(['user_id', 'last_read_message_id', 'updated_at'])->map(fn($member) => ['user_id' => $member->user_id, 'message_id' => $member->last_read_message_id, 'read_at' => $member->updated_at?->toISOString()]);
        $membership = $this->member($conversation, $request->user());
        $firstUnread = $conversation->messages()->where('id', '>', $membership->last_read_message_id ?? 0)->where('sender_id', '!=', $request->user()->id)->oldest('id')->value('id');
        return response()->json(['data' => $messages->getCollection()->reverse()->values()->map(fn($m) => MessengerPayload::message($m)), 'next_cursor' => $messages->nextCursor()?->encode(), 'read_receipts' => $receipts, 'first_unread_message_id' => $firstUnread]);
    }
    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $this->member($conversation, $request->user());
        abort_if($conversation->status !== 'active', 403, 'This group is restricted.');
        $data = $request->validate(['message' => 'nullable|string|max:5000', 'reply_to_id' => ['nullable', 'integer', Rule::exists('messages', 'id')], 'attachment' => 'nullable|file|max:20480', 'voice' => 'nullable|boolean', 'audio_duration' => 'nullable|integer|min:0|max:7200']);
        if (blank($data['message'] ?? null) && !$request->hasFile('attachment')) abort(422, 'A message or attachment is required.');
        if (isset($data['reply_to_id']) && !$conversation->messages()->whereKey($data['reply_to_id'])->exists()) abort(422, 'Reply must be in this conversation.');
        $voice = $request->boolean('voice');
        if ($voice) $request->validate(['attachment' => ['required', 'file', 'max:16384', 'mimetypes:audio/webm,audio/ogg,audio/mp4,audio/mpeg,audio/wav,audio/x-wav']]);
        elseif ($request->hasFile('attachment')) $request->validate(['attachment' => 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt']);
        $payload = ['sender_id' => $request->user()->id, 'message' => filled($data['message'] ?? null) ? trim($data['message']) : null, 'reply_to_id' => $data['reply_to_id'] ?? null, 'type' => 'text'];
        if ($file = $request->file('attachment')) {
            $isImage = str_starts_with($file->getMimeType(), 'image/');
            $payload = array_merge($payload, ['type' => $voice ? 'voice' : ($isImage ? 'image' : 'file'), 'file_path' => $file->store($voice ? 'chat-voice' : ($isImage ? 'chat-images' : 'chat-files'), 'local'), 'file_name' => $voice ? 'Voice message' : $file->getClientOriginalName(), 'file_size' => $file->getSize(), 'file_mime_type' => $file->getMimeType(), 'audio_duration' => $voice ? ($data['audio_duration'] ?? null) : null]);
        }
        $message = DB::transaction(function () use ($conversation, $payload) {
            $m = $conversation->messages()->create($payload);
            $conversation->update(['last_message_id' => $m->id]);
            return $m;
        });
        $message->load(['sender', 'replyTo.sender', 'reactions']);
        broadcast(new MessageSent($message))->toOthers();
        return response()->json(['data' => MessengerPayload::message($message)], 201);
    }
    public function forward(Request $request, Message $message): JsonResponse
    {
        $user = $request->user();
        $this->member($message->conversation, $user);
        $data = $request->validate(['conversation_id' => ['required', 'integer', Rule::exists('conversations', 'id')]]);
        $destination = Conversation::findOrFail($data['conversation_id']);
        $this->member($destination, $user);

        $forwarded = DB::transaction(function () use ($destination, $message, $user) {
            $copy = $destination->messages()->create([
                'sender_id' => $user->id,
                'message' => $message->message,
                'type' => $message->type,
                'file_path' => $message->file_path,
                'file_name' => $message->file_name,
                'file_size' => $message->file_size,
                'file_mime_type' => $message->file_mime_type,
                'audio_duration' => $message->audio_duration,
            ]);
            $destination->update(['last_message_id' => $copy->id]);
            return $copy;
        });

        $forwarded->load(['sender', 'replyTo.sender', 'reactions']);
        broadcast(new MessageSent($forwarded))->toOthers();

        return response()->json(['data' => MessengerPayload::message($forwarded)], 201);
    }
    public function update(Request $request, Message $message): JsonResponse
    {
        abort_unless($message->sender_id === $request->user()->id, 403);
        $data = $request->validate(['message' => 'required|string|max:5000']);
        $message->update(['message' => trim($data['message']), 'edited_at' => now()]);
        return response()->json(['data' => MessengerPayload::message($message)]);
    }
    public function delete(Request $request, Message $message): JsonResponse
    {
        abort_unless($message->sender_id === $request->user()->id, 403);
        $message->delete();
        return response()->json(['data' => ['id' => $message->id]]);
    }
    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $membership = $this->member($conversation, $request->user());
        $id = $request->validate(['message_id' => ['nullable', 'integer']])['message_id'] ?? $conversation->last_message_id;
        if (!$id || !$conversation->messages()->whereKey($id)->exists() || $id <= (int) $membership->last_read_message_id) return response()->json(['ok' => true]);
        $membership->update(['last_read_message_id' => $id]);
        $readAt = $membership->fresh()->updated_at->toISOString();
        broadcast(new MessageRead($conversation->id, $request->user()->id, (int) $id, $readAt))->toOthers();
        return response()->json(['ok' => true, 'read_at' => $readAt]);
    }
    public function group(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'members' => 'required|array|min:1|max:'.AppSetting::valueFor('max_group_members', 100), 'members.*' => ['integer', 'distinct', Rule::exists('users', 'id')], 'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:'.AppSetting::valueFor('max_image_upload_kb', 5120)]);
        $c = DB::transaction(function () use ($request, $data) {
            $c = Conversation::create(['type' => 'group', 'name' => trim($data['name']), 'created_by' => $request->user()->id, 'avatar' => $request->file('avatar')?->store('group-avatars', 'public')]);
            $members = collect($data['members'])->push($request->user()->id)->unique();
            foreach ($members as $id) $c->memberships()->create(['user_id' => $id, 'role' => $id === $request->user()->id ? 'admin' : 'member']);
            return $c;
        });
        return response()->json(['data' => $this->conversationData($c, $request->user())], 201);
    }
    public function attachment(Request $request, Message $message)
    {
        $this->member($message->conversation, $request->user());
        abort_unless($message->file_path, 404);
         $disk = Storage::disk("local");

        if ($request->boolean('download')) {
            return $disk->download($message->file_path, $message->file_name);
        }

        if (in_array($message->type, ["image", "voice"], true)) {
            abort_unless($disk->exists($message->file_path), 404);
            return response()->file($disk->path($message->file_path), [
                "Content-Type" => $message->file_mime_type ?? "application/octet-stream",
            ]);
        }

        return $disk->download($message->file_path, $message->file_name);
    }
    public function react(Request $request, Message $message): JsonResponse
    {
        $this->member($message->conversation, $request->user());
        $data = $request->validate(['emoji' => ['required', Rule::in(['👍', '❤️', '😂', '😮', '😢', '😡'])]]);
        $r = MessageReaction::where(['message_id' => $message->id, 'user_id' => $request->user()->id, 'emoji' => $data['emoji']])->first();
        $r ? $r->delete() : MessageReaction::create(['message_id' => $message->id, 'user_id' => $request->user()->id, 'emoji' => $data['emoji']]);
        return response()->json(['data' => MessengerPayload::message($message->fresh(['sender', 'replyTo.sender', 'reactions']))]);
    }
    public function report(Request $request, Message $message): JsonResponse
    {
        $this->member($message->conversation, $request->user());
        $data = $request->validate(['reason' => ['required', Rule::in(['spam','harassment','scam','inappropriate','other'])], 'description' => 'nullable|string|max:1000']);
        $report = MessageReport::firstOrCreate(['message_id'=>$message->id, 'reported_by'=>$request->user()->id], $data);
        return response()->json(['data'=>['id'=>$report->id, 'status'=>$report->status]], $report->wasRecentlyCreated ? 201 : 200);
    }
    private function member(Conversation $c, User $u): ConversationMember
    {
        return $c->memberships()->where('user_id', $u->id)->firstOr(fn() => abort(403));
    }
    private function userData(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'username' => $u->username, 'email' => $u->email, 'avatar_url' => $u->avatar_url, 'last_seen_at' => $u->last_seen_at?->toISOString(), 'is_online' => $u->last_seen_at?->gt(now()->subMinutes(2)) ?? false];
    }
    private function conversations(User $u)
    {
        return $u->conversations()->with(['members:id,name,username,email,avatar,last_seen_at', 'lastMessage.sender:id,name,username,email,avatar'])->orderByDesc('conversations.updated_at')->get()->map(fn($c) => $this->conversationData($c, $u));
    }
    private function conversationData(Conversation $c, User $u): array
    {
        $other = $c->type === 'private' ? $c->members->firstWhere('id', '!=', $u->id) : null;
        $read = $c->memberships()->where('user_id', $u->id)->value('last_read_message_id') ?? 0;
        return ['id' => $c->id, 'type' => $c->type, 'name' => $c->type === 'private' ? ($other?->name ?? 'Unknown') : $c->name, 'avatar_url' => $c->type === 'private' ? ($other?->avatar_url) : $c->avatar_url, 'members' => $c->members->map(fn($m) => $this->userData($m)), 'last_message' => $c->lastMessage ? MessengerPayload::message($c->lastMessage) : null, 'unread_count' => $c->messages()->where('id', '>', $read)->where('sender_id', '!=', $u->id)->count()];
    }
    private function touchPresence(User $user): void
    {
        if ($user->last_seen_at?->gt(now()->subMinutes(2))) return;
        $user->forceFill(['last_seen_at' => now()])->save();
        broadcast(new UserPresenceUpdated($user, $user->conversations()->pluck('conversations.id')->all()))->toOthers();
    }
}
