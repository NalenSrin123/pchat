<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ConversationMember extends Model { protected $fillable=['conversation_id','user_id','role','joined_at','last_read_message_id']; protected function casts():array{return ['joined_at'=>'datetime'];} public function conversation():BelongsTo{return $this->belongsTo(Conversation::class);} public function user():BelongsTo{return $this->belongsTo(User::class);} public function lastReadMessage():BelongsTo{return $this->belongsTo(Message::class,'last_read_message_id');} }
