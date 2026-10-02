<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import axios from 'axios';
import { echo } from '@laravel/echo-vue';

type User = { id: number; name: string; username: string; email: string; avatar_url: string | null; last_seen_at?: string | null; is_online?: boolean };
type Reaction = { emoji: string; user_id: number };
type ReadReceipt = { user_id: number; message_id: number | null; read_at: string | null };
type Message = {
    id: number; conversation_id: number; sender_id: number; message: string | null;
    type: 'text' | 'image' | 'file' | 'voice'; file_name?: string; file_size?: number; file_url?: string; audio_duration?: number | null;
    created_at: string; edited_at?: string | null; deleted_at?: string | null; sender: User;
    reply_to?: { id: number; message: string; sender: string } | null; reactions: Reaction[];
};
type Conversation = {
    id: number; type: 'private' | 'group'; name: string; avatar_url: string | null;
    members: User[]; last_message: Message | null; unread_count: number;
};

const props = defineProps<{ conversations: Conversation[]; currentUser: User }>();

const conversations = ref<Conversation[]>(props.conversations);
const active = ref<Conversation | null>(null);
const messages = ref<Message[]>([]);
const draft = ref('');
const filter = ref('');
const tab = ref<'all' | 'unread'>('all');
const query = ref('');
const people = ref<User[]>([]);
const showSearch = ref(false);
const showGroup = ref(false);
const groupName = ref('');
const selected = ref<User[]>([]);
const attachment = ref<File | null>(null);
const uploadError = ref("");
const selectAttachment = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    uploadError.value = "";
    attachment.value = file;
};
const reply = ref<Message | null>(null);
const imageViewer = ref<Message | null>(null);
const detailsOpen = ref(false);
const detailsLoading = ref(false);
const detailsConversationId = ref<number | null>(null);
const details = ref<{ media: Message[]; files: Message[]; links: { id: number; url: string; created_at: string }[] }>({ media: [], files: [], links: [] });
const detailsSections = ref({ media: true, files: false, links: false });
const messageSearch = ref('');
const searchResults = ref<Message[]>([]);
const searchBox = ref<HTMLInputElement | null>(null);
const reactFor = ref<number | null>(null);
const emojiOpen = ref(false);
const highlightId = ref<number | null>(null);
const imageLoadFailures = ref(new Set<number>());
const forwardMessage = ref<Message | null>(null);
const showForward = ref(false);
const reportMessage = ref<Message | null>(null);
const reportReason = ref('spam');
const reportDescription = ref('');
const reportError = ref('');
const editTarget = ref<Message | null>(null);
const editText = ref('');
const editError = ref('');
const busy = ref(false);
const readReceipts = ref<ReadReceipt[]>([]);
const notification = ref<{ conversation: Conversation; message: Message } | null>(null);
const loading = ref(false);
const chat = ref<HTMLElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const composer = ref<HTMLTextAreaElement | null>(null);
const firstUnreadMessageId = ref<number | null>(null);
const atBottom = ref(true);
const newBelow = ref(0);
const recording = ref(false);
const recordingSeconds = ref(0);
const recordingError = ref('');
// Ticks every 30s so "Active 4 hours ago" stays accurate without a reload.
const now = ref(Date.now());
let nowTimer: number | undefined;
let recorder: MediaRecorder | null = null;
let recordingStream: MediaStream | null = null;
let recordingTimer: number | undefined;

const icons = {
    reply: 'M9 14 4 9l5-5M4 9h10a6 6 0 0 1 6 6v3',
    forward: 'm15 14 5-5-5-5M20 9H10a6 6 0 0 0-6 6v3',
    edit: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
    trash: 'M3 6h18M8 6V4h8v2m-9 0 1 14h8l1-14',
    flag: 'M5 21V4m0 0h11l-2 4 2 4H5',
    send: 'M22 2 11 13M22 2l-7 20-4-9-9-4z',
    mic: 'M12 15a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zM19 11a7 7 0 0 1-14 0M12 18v3',
    home: 'm3 11 9-7 9 7v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1zM9 21v-6h6v6',
    down: 'M12 5v14M19 12l-7 7-7-7',
    clip: 'M20 11.5l-8 8a5 5 0 0 1-7-7l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7L9.5 17a1.7 1.7 0 0 1-2.4-2.4L14 7.7',
    group: 'M3 19c.6-3 3-4.6 6-4.6s5.4 1.6 6 4.6M9 11.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4zM17 5.5a3 3 0 0 1 0 5.6M18.5 14.6c1.6.6 2.5 2 2.8 4',
};

const palette = ['bg-blue-100 text-[#0756d6]', 'bg-amber-100 text-amber-800', 'bg-sky-100 text-sky-800', 'bg-rose-100 text-rose-800', 'bg-violet-100 text-violet-800', 'bg-lime-100 text-lime-800'];
const initials = (name: string) => name.split(' ').filter(Boolean).map(x => x[0]).join('').slice(0, 2).toUpperCase();
const tone = (seed: string) => palette[[...seed].reduce((a, c) => a + c.charCodeAt(0), 0) % palette.length];
const time = (v?: string) => v ? new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(new Date(v)) : '';
const dayLabel = (v: string) => {
    const d = new Date(v), today = new Date(), y = new Date();
    y.setDate(today.getDate() - 1);
    if (d.toDateString() === today.toDateString()) return 'Today';
    if (d.toDateString() === y.toDateString()) return 'Yesterday';
    return new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' }).format(d);
};
const listTime = (v?: string) => {
    if (!v) return '';
    const d = new Date(v);
    if (d.toDateString() === new Date(now.value).toDateString()) return time(v);
    const days = Math.floor((now.value - d.getTime()) / 86400000);
    if (days < 7) return new Intl.DateTimeFormat(undefined, { weekday: 'short' }).format(d);
    return new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short' }).format(d);
};
const peer = (c: Conversation) => c.type === 'private' ? c.members.find(u => u.id !== props.currentUser.id) : undefined;
const preview = (c: Conversation) => {
    const m = c.last_message;
    if (!m) return 'No messages yet';
    if (m.deleted_at) return 'Message deleted';
    const body = m.type === 'image' ? 'Photo' : m.type === 'voice' ? '🎤 Voice message' : m.type === 'file' ? (m.file_name ?? 'File') : (m.message ?? '');
    return (m.sender_id === props.currentUser.id ? 'You: ' : '') + body;
};
const listPreview = (c: Conversation) => {
    const m = c.last_message;
    if (!m) return c.type === 'private' ? 'Say hello 👋' : 'No messages yet';
    const who = c.type === 'group' && m.sender_id !== props.currentUser.id && !m.deleted_at ? `${m.sender?.name?.split(' ')[0] ?? ''}: ` : '';
    return who + preview(c);
};
const fileSize = (n?: number) => !n ? '' : n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
const notificationText = (m: Message) =>
    m.message || (m.type === 'image' ? 'Sent a photo' : 'Sent a file');

const totalUnread = computed(() => conversations.value.reduce((sum, c) => sum + (c.unread_count || 0), 0));
const filtered = computed(() => {
    const q = filter.value.trim().toLowerCase();
    return conversations.value
        .filter(c => tab.value === 'all' || c.unread_count > 0)
        .filter(c => !q || c.name.toLowerCase().includes(q));
});
const lastActive = (value?: string | null) => {
    if (!value) return 'Offline';
    const minutes = Math.max(0, Math.floor((now.value - new Date(value).getTime()) / 60000));
    if (minutes < 2) return 'Active just now';
    if (minutes < 60) return `Active ${minutes} minutes ago`;
    if (minutes < 1440) return `Active ${Math.floor(minutes / 60)} hour${minutes >= 120 ? 's' : ''} ago`;
    return minutes < 2880 ? 'Active yesterday' : `Active ${Math.floor(minutes / 1440)} days ago`;
};
const status = computed(() => {
    if (!active.value) return '';
    if (active.value.type === 'group') return `${active.value.members.length} members`;
    return peer(active.value)?.is_online ? 'Active now' : lastActive(peer(active.value)?.last_seen_at);
});
const isOnline = computed(() => !!active.value && active.value.type === 'private' && !!peer(active.value)?.is_online);

const rows = computed(() => messages.value.map((m, i) => {
    const prev = messages.value[i - 1];
    const nextMsg = messages.value[i + 1];
    const newDay = !prev || new Date(prev.created_at).toDateString() !== new Date(m.created_at).toDateString();
    const nextNewDay = !!nextMsg && new Date(nextMsg.created_at).toDateString() !== new Date(m.created_at).toDateString();
    // A new run also starts after a 5 minute pause, like Messenger.
    const gap = !!prev && new Date(m.created_at).getTime() - new Date(prev.created_at).getTime() > 300000;
    const nextGap = !!nextMsg && new Date(nextMsg.created_at).getTime() - new Date(m.created_at).getTime() > 300000;
    const first = newDay || gap || prev.sender_id !== m.sender_id;
    const last = !nextMsg || nextNewDay || nextGap || nextMsg.sender_id !== m.sender_id;
    return { m, newDay, gap, first, last, mine: m.sender_id === props.currentUser.id };
}));
// Messenger-style: corners touching a neighbour in the same run go nearly square.
const shape = (mine: boolean, first: boolean, last: boolean) => mine
    ? `rounded-[18px] ${first ? '' : 'rounded-tr-[4px]'} ${last ? '' : 'rounded-br-[4px]'}`
    : `rounded-[18px] ${first ? '' : 'rounded-tl-[4px]'} ${last ? '' : 'rounded-bl-[4px]'}`;
// Emoji-only messages render large with no bubble, like Messenger.
const jumbo = (m: Message) => m.type === 'text' && !m.deleted_at && !m.reply_to && !!m.message
    && m.message.length <= 16 && /^(?:\p{Extended_Pictographic}|\uFE0F|\u200D|\s)+$/u.test(m.message);
// Centered timestamp shown above a new day or after a pause.
const stamp = (v: string) => `${dayLabel(v)}, ${time(v)}`;
const sendLike = () => { draft.value = '👍'; void send(); };
const unreadCountInView = computed(() => firstUnreadMessageId.value ? messages.value.filter(m => m.id >= firstUnreadMessageId.value! && m.sender_id !== props.currentUser.id).length : 0);
const isFirstUnread = (m: Message) => m.id === firstUnreadMessageId.value;
const duration = (seconds?: number | null) => {
    const value = Math.max(0, Math.round(seconds ?? 0));
    return `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
};
const grouped = (m: Message) => {
    const map = new Map<string, number>();
    m.reactions.forEach(r => map.set(r.emoji, (map.get(r.emoji) ?? 0) + 1));
    return [...map].map(([emoji, count]) => ({ emoji, count, mine: m.reactions.some(r => r.emoji === emoji && r.user_id === props.currentUser.id) }));
};
const readStatus = (message: Message) => {
    if (message.sender_id !== props.currentUser.id) return null;
    const seen = readReceipts.value.filter(receipt => receipt.user_id !== props.currentUser.id && (receipt.message_id ?? 0) >= message.id);
    if (!seen.length) return 'Delivered';
    const latest = seen.reduce((value, receipt) => !value || (receipt.read_at ?? '') > (value.read_at ?? '') ? receipt : value, seen[0]);
    return active.value?.type === 'group' ? `Seen by ${seen.length} · ${time(latest.read_at ?? undefined)}` : `Seen · ${time(latest.read_at ?? undefined)}`;
};
const lastMineId = computed(() => [...messages.value].reverse()
    .find(m => m.sender_id === props.currentUser.id && !m.deleted_at)?.id);
const isSeen = (m: Message) => readReceipts.value
    .some(r => r.user_id !== props.currentUser.id && (r.message_id ?? 0) >= m.id);
const canSend = computed(() => !!draft.value.trim() || !!attachment.value);
const viewerIndex = computed(() => details.value.media.findIndex(m => m.id === imageViewer.value?.id));
const viewerMove = (direction: number) => {
    if (!details.value.media.length || viewerIndex.value < 0) return;
    imageViewer.value = details.value.media[(viewerIndex.value + direction + details.value.media.length) % details.value.media.length];
};
async function openDetails() {
    if (!active.value) return;
    detailsOpen.value = true;
    if (detailsConversationId.value === active.value.id) return;
    detailsLoading.value = true;
    try { details.value = (await axios.get(`/messenger/conversations/${active.value.id}/details`)).data.data; detailsConversationId.value = active.value.id; }
    finally { detailsLoading.value = false; }
}
function focusSearch() { detailsOpen.value = true; nextTick(() => searchBox.value?.focus()); }
let messageSearchTimer: number | undefined;
watch(messageSearch, () => {
    window.clearTimeout(messageSearchTimer);
    messageSearchTimer = window.setTimeout(async () => {
        if (!active.value || !messageSearch.value.trim()) { searchResults.value = []; return; }
        searchResults.value = (await axios.get(`/messenger/conversations/${active.value.id}/search`, { params: { q: messageSearch.value } })).data.data;
    }, 250);
});
function jumpToMessage(message: Message) {
    if (!messages.value.some(item => item.id === message.id)) messages.value = mergeMessages(messages.value, [message]);
    highlightId.value = message.id;
    window.setTimeout(() => { if (highlightId.value === message.id) highlightId.value = null; }, 1800);
    nextTick(() => document.getElementById(`message-${message.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
}
// Splits text into plain and link parts so URLs become clickable without v-html.
const parts = (text: string | null) => (text ?? '').split(/(https?:\/\/[^\s<]+)/g).filter(Boolean)
    .map(t => /^https?:\/\//.test(t) ? { text: t, url: t } : { text: t, url: '' });
const emojis = ['😀', '😂', '🥹', '😍', '😘', '😎', '🤔', '😮', '😢', '😭', '😡', '🥳', '👍', '👎', '👏', '🙏', '💪', '❤️', '🔥', '🎉', '✨', '💯', '👋', '🙌'];
function addEmoji(emoji: string) {
    const el = composer.value;
    const start = el?.selectionStart ?? draft.value.length;
    const end = el?.selectionEnd ?? start;
    draft.value = draft.value.slice(0, start) + emoji + draft.value.slice(end);
    emojiOpen.value = false;
    nextTick(() => { el?.focus(); el?.setSelectionRange(start + emoji.length, start + emoji.length); });
}

const menuId = ref<number | null>(null);
const menuMessage = computed(() => messages.value.find(m => m.id === menuId.value) ?? null);
let pressTimer: number | undefined;
let pressX = 0;
let pressY = 0;
let touchPress = false;
function pressStart(e: PointerEvent, m: Message) {
    if (e.pointerType === 'mouse' || m.deleted_at) return;
    touchPress = true;
    pressX = e.clientX;
    pressY = e.clientY;
    window.clearTimeout(pressTimer);
    pressTimer = window.setTimeout(() => {
        menuId.value = m.id;
        navigator.vibrate?.(15);
    }, 400);
}
function pressMove(e: PointerEvent) {
    if (Math.abs(e.clientX - pressX) > 10 || Math.abs(e.clientY - pressY) > 10) pressCancel();
}
const pressCancel = () => window.clearTimeout(pressTimer);
function menuDo(fn: (m: Message) => unknown) {
    const m = menuMessage.value;
    menuId.value = null;
    if (m) fn(m);
}
const startReply = (m: Message) => { reply.value = m; composer.value?.focus(); };
const copyText = (m: Message) => navigator.clipboard?.writeText(m.message ?? '');
const imageFailed = (m: Message) => imageLoadFailures.value = new Set([...imageLoadFailures.value, m.id]);
const retryImage = (m: Message) => imageLoadFailures.value = new Set([...imageLoadFailures.value].filter(id => id !== m.id));
function openForward(m: Message) {
    forwardMessage.value = m;
    showForward.value = true;
}
async function forwardTo(conversation: Conversation) {
    const message = forwardMessage.value;
    if (!message || busy.value) return;
    busy.value = true;
    try {
        const { data } = await axios.post(`/messenger/messages/${message.id}/forward`, { conversation_id: conversation.id });
        conversation.last_message = data.data;
        if (sameId(active.value?.id, conversation.id)) {
            messages.value = mergeMessages(messages.value, [data.data]);
            scrollDown(true);
        }
        showForward.value = false;
        forwardMessage.value = null;
    } finally { busy.value = false; }
}

let presenceTimer: number | undefined;
const heartbeat = async () => {
    try {
        const { data } = await axios.post("/messenger/presence");
        conversations.value = data.data;
        if (active.value) active.value = conversations.value.find((conversation: Conversation) => conversation.id === active.value?.id) ?? null;
    } catch {
        // Presence will be refreshed on the next successful request.
    }
};

function cleanupRecording() {
    window.clearInterval(recordingTimer);
    recorder = null;
    recordingStream?.getTracks().forEach(track => track.stop());
    recordingStream = null;
    recording.value = false;
}
async function startRecording() {
    if (recording.value || busy.value) return;
    recordingError.value = '';
    if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
        recordingError.value = 'Voice recording is not supported by this browser.';
        return;
    }
    try {
        recordingStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const preferred = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4'].find(type => MediaRecorder.isTypeSupported(type));
        recorder = new MediaRecorder(recordingStream, preferred ? { mimeType: preferred } : undefined);
        const chunks: BlobPart[] = [];
        recorder.ondataavailable = event => { if (event.data.size) chunks.push(event.data); };
        recorder.onerror = () => { recordingError.value = 'Recording failed. Please try again.'; cleanupRecording(); };
        recorder.onstop = async () => {
            const type = recorder?.mimeType || preferred || 'audio/webm';
            const blob = new Blob(chunks, { type });
            const seconds = recordingSeconds.value;
            cleanupRecording();
            if (blob.size) await sendVoice(blob, seconds, type);
        };
        recordingSeconds.value = 0;
        recorder.start();
        recording.value = true;
        recordingTimer = window.setInterval(() => recordingSeconds.value++, 1000);
    } catch (error: any) {
        recordingError.value = error?.name === 'NotAllowedError' ? 'Microphone permission was denied.' : 'Unable to start microphone recording.';
        cleanupRecording();
    }
}
function cancelRecording() {
    if (recorder && recorder.state !== 'inactive') {
        recorder.onstop = null;
        recorder.stop();
    }
    cleanupRecording();
}
function stopRecordingAndSend() { if (recorder?.state === 'recording') recorder.stop(); }
async function sendVoice(blob: Blob, seconds: number, mime: string) {
    if (!active.value) return;
    busy.value = true;
    const extension = mime.includes('ogg') ? 'ogg' : mime.includes('mp4') ? 'm4a' : 'webm';
    const form = new FormData();
    form.append('attachment', new File([blob], `voice.${extension}`, { type: mime }));
    form.append('voice', '1');
    form.append('audio_duration', String(seconds));
    try {
        const { data } = await axios.post(`/messenger/conversations/${active.value.id}/messages`, form);
        messages.value = mergeMessages(messages.value, [data.data]);
        active.value.last_message = data.data;
        scrollDown(true);
    } catch (error: any) {
        uploadError.value = error.response?.data?.errors?.attachment?.[0] ?? 'Unable to send voice message.';
    } finally { busy.value = false; }
}
function pauseOtherAudio(event: Event) {
    document.querySelectorAll<HTMLAudioElement>('audio[data-voice-message]').forEach(audio => {
        if (audio !== event.target) audio.pause();
    });
}

let notificationTimer: number | undefined;
let notificationAudio: AudioContext | undefined;

function playNotificationSound() {
    try {
        notificationAudio ??= new AudioContext();
        const play = () => {
            const t = notificationAudio!.currentTime;
            [
                { frequency: 659.25, start: t, end: t + 0.14 },
                { frequency: 987.77, start: t + 0.11, end: t + 0.34 },
            ].forEach(({ frequency, start, end }) => {
                const oscillator = notificationAudio!.createOscillator();
                const gain = notificationAudio!.createGain();
                oscillator.type = "sine";
                oscillator.frequency.setValueAtTime(frequency, start);
                gain.gain.setValueAtTime(0.0001, start);
                gain.gain.exponentialRampToValueAtTime(0.13, start + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, end);
                oscillator.connect(gain).connect(notificationAudio!.destination);
                oscillator.start(start);
                oscillator.stop(end);
            });
        };
        if (notificationAudio.state === "suspended") {
            void notificationAudio.resume().then(play).catch(() => undefined);
        } else {
            play();
        }
    } catch {
        // Sound is optional.
    }
}

function unlockNotificationSound() {
    try {
        notificationAudio ??= new AudioContext();
        if (notificationAudio.state === "suspended") {
            void notificationAudio.resume().catch(() => undefined);
        }
    } catch {
        // Notifications still remain visible.
    }
}

function notify(conversation: Conversation, message: Message) {
    notification.value = { conversation, message };
    window.clearTimeout(notificationTimer);
    notificationTimer = window.setTimeout(() => notification.value = null, 5000);
    if ('Notification' in window && Notification.permission === 'granted') new Notification(conversation.name, { body: message.message || (message.type === 'image' ? 'Sent a photo' : 'Sent a file') });
}

let searchTimer: number | undefined;
watch(query, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(async () => {
        if (!query.value.trim()) { people.value = []; return; }
        people.value = (await axios.get('/messenger/users/search', { params: { q: query.value } })).data.data
            .filter((user: User) => user.id !== props.currentUser.id);
    }, 250);
});
watch(draft, () => nextTick(fit));
function fit() {
    const el = composer.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 128) + 'px';
}
const scrollDown = (smooth = false) => nextTick(() => chat.value?.scrollTo({ top: chat.value.scrollHeight, behavior: smooth ? 'smooth' : 'auto' }));
function onScroll() {
    const el = chat.value;
    if (!el) return;
    atBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 120;
    if (atBottom.value) newBelow.value = 0;
}
function jumpToBottom() { newBelow.value = 0; scrollDown(true); }

const listeningIds = new Set<number>();
const sameId = (left: number | string | null | undefined, right: number | string | null | undefined) =>
    left != null && right != null && String(left) === String(right);
const orderMessages = (items: Message[]) => [...items].sort((left, right) => {
    const byCreatedAt = new Date(left.created_at).getTime() - new Date(right.created_at).getTime();
    return byCreatedAt || Number(left.id) - Number(right.id);
});
const mergeMessages = (...groups: Message[][]) => orderMessages(groups.flat().filter((message, index, all) =>
    all.findIndex(candidate => sameId(candidate.id, message.id)) === index,
));

function subscribe(c: Conversation) {
    if (listeningIds.has(c.id)) return;
    listeningIds.add(c.id);
    echo().private('conversation.' + c.id).listen('.message.sent', (event: { message?: Message } | Message) => {
        const message = ('message' in event && typeof event.message === 'object' && event.message?.id ? event.message : event) as Message;
        const conversation = conversations.value.find(item => sameId(item.id, message.conversation_id)) ?? c;
        const isActiveConversation = sameId(active.value?.id, message.conversation_id);

        if (!message?.id || !sameId(conversation.id, message.conversation_id)) return;

        conversation.last_message = message;
        if (isActiveConversation) {
            messages.value = mergeMessages(messages.value, [message]);
            if (!sameId(message.sender_id, props.currentUser.id) && document.hidden) {
                conversation.unread_count++;
                firstUnreadMessageId.value ??= message.id;
            } else if (!document.hidden) {
                void markRead(conversation, message.id);
                if (atBottom.value || sameId(message.sender_id, props.currentUser.id)) scrollDown(true);
                else newBelow.value++;
            }
        } else if (!sameId(message.sender_id, props.currentUser.id)) {
            playNotificationSound();
            conversation.unread_count++;
            notify(conversation, message);
        }
    }).listen('.message.read', (event: ReadReceipt) => {
        if (!sameId(active.value?.id, c.id)) return;
        const index = readReceipts.value.findIndex(receipt => receipt.user_id === event.user_id);
        if (index < 0) readReceipts.value.push(event);
        else if ((event.message_id ?? 0) > (readReceipts.value[index].message_id ?? 0)) readReceipts.value[index] = event;
    }).listen('.presence.updated', (event: { user_id: number; last_seen_at: string; is_online: boolean }) => {
        conversations.value.forEach(conversation => conversation.members.forEach(member => {
            if (sameId(member.id, event.user_id)) Object.assign(member, { last_seen_at: event.last_seen_at, is_online: event.is_online });
        }));
    });
}
async function open(c: Conversation) {
    subscribe(c);
    active.value = c;
    messages.value = [];
    reply.value = null;
    attachment.value = null;
    firstUnreadMessageId.value = null;
    atBottom.value = true;
    newBelow.value = 0;
    detailsOpen.value = false;
    detailsConversationId.value = null;
    messageSearch.value = '';
    searchResults.value = [];
    reactFor.value = null;
    emojiOpen.value = false;
    loading.value = true;
    try {
        const { data } = await axios.get(`/messenger/conversations/${c.id}/messages`);
        messages.value = mergeMessages(messages.value, data.data);
        readReceipts.value = data.read_receipts;
        firstUnreadMessageId.value = data.first_unread_message_id ?? null;
        if (!document.hidden) await markRead(c, messages.value.at(-1)?.id);
    } finally { loading.value = false; }
    scrollDown();
}
async function markRead(c: Conversation, messageId?: number) {
    if (document.hidden || !messageId) return;
    await axios.post(`/messenger/conversations/${c.id}/read`, { message_id: messageId });
    c.unread_count = 0;
}
function visibilityChanged() {
    if (!document.hidden && active.value) void markRead(active.value, messages.value.at(-1)?.id);
}
function closeModal() {
    showSearch.value = false; showGroup.value = false;
    query.value = ''; people.value = []; groupName.value = ''; selected.value = [];
}
async function start(user: User) {
    const { data } = await axios.post('/messenger/conversations/private', { user_id: user.id });
    let c = conversations.value.find(x => x.id === data.data.id);
    if (!c) { c = data.data as Conversation; conversations.value.unshift(c); }
    closeModal();
    await open(c);
}
async function send() {
    if (!active.value || busy.value || (!draft.value.trim() && !attachment.value)) return;
    busy.value = true;
    const form = new FormData();
    form.append('message', draft.value.trim());
    if (reply.value) form.append('reply_to_id', String(reply.value.id));
    if (attachment.value) form.append('attachment', attachment.value);
    uploadError.value = "";
    try {
        const { data } = await axios.post(`/messenger/conversations/${active.value.id}/messages`, form);
        messages.value = mergeMessages(messages.value, [data.data]);
        active.value.last_message = data.data;
        draft.value = ''; attachment.value = null; reply.value = null;
        if (fileInput.value) fileInput.value.value = '';
        scrollDown(true);
    } catch (error: any) {
        uploadError.value = error.response?.data?.errors?.attachment?.[0] ?? error.response?.data?.message ?? "Unable to send attachment.";
    } finally { busy.value = false; }
}
function edit(m: Message) {
    editTarget.value = m;
    editText.value = m.message ?? '';
    editError.value = '';
}
async function saveEdit() {
    const m = editTarget.value;
    if (!m || busy.value || !editText.value.trim()) return;
    busy.value = true; editError.value = '';
    try {
        const { data } = await axios.put(`/messenger/messages/${m.id}`, { message: editText.value });
        Object.assign(m, data.data);
        editTarget.value = null;
    } catch (error: any) {
        editError.value = error.response?.data?.message ?? 'Unable to edit this message.';
    } finally { busy.value = false; }
}
async function remove(m: Message) {
    if (window.confirm('Delete this message?')) {
        await axios.delete(`/messenger/messages/${m.id}`);
        m.deleted_at = new Date().toISOString();
        m.message = null;
    }
}
async function react(m: Message, emoji: string) {
    const { data } = await axios.post(`/messenger/messages/${m.id}/reactions`, { emoji });
    Object.assign(m, data.data);
}
async function submitReport() {
    if (!reportMessage.value || busy.value) return;
    busy.value = true; reportError.value = '';
    try { await axios.post(`/messenger/messages/${reportMessage.value.id}/reports`, { reason: reportReason.value, description: reportDescription.value }); reportMessage.value = null; reportDescription.value = ''; }
    catch (error: any) { reportError.value = error.response?.data?.message ?? 'Unable to submit this report.'; }
    finally { busy.value = false; }
}
const toggle = (user: User) => {
    selected.value = selected.value.some(x => x.id === user.id) ? selected.value.filter(x => x.id !== user.id) : [...selected.value, user];
};
async function createGroup() {
    if (!groupName.value.trim() || !selected.value.length) return;
    const { data } = await axios.post('/messenger/groups', { name: groupName.value, members: selected.value.map(x => x.id) });
    conversations.value.unshift(data.data);
    const created = data.data as Conversation;
    closeModal();
    await open(created);
}
function onEnter(e: KeyboardEvent) {
    if (e.isComposing) return;
    e.preventDefault();
    send();
}
const onKey = (e: KeyboardEvent) => {
    if (e.key !== 'Escape') return;
    if (reactFor.value !== null) reactFor.value = null;
    else if (emojiOpen.value) emojiOpen.value = false;
    else if (menuId.value) menuId.value = null;
    else if (editTarget.value) editTarget.value = null;
    else if (imageViewer.value) imageViewer.value = null;
    else if (showForward.value) { showForward.value = false; forwardMessage.value = null; }
    else if (showSearch.value || showGroup.value) closeModal();
};

onMounted(() => {
    void heartbeat();
    presenceTimer = window.setInterval(heartbeat, 30000);
    nowTimer = window.setInterval(() => now.value = Date.now(), 30000);
    window.addEventListener("pointerdown", unlockNotificationSound, { once: true });
    window.addEventListener("keydown", unlockNotificationSound, { once: true });
    window.addEventListener('keydown', onKey);
    document.addEventListener('visibilitychange', visibilityChanged);
    conversations.value.forEach(subscribe);
    if (conversations.value[0] && window.matchMedia('(min-width: 768px)').matches) open(conversations.value[0]);
});
onUnmounted(() => {
    window.removeEventListener("pointerdown", unlockNotificationSound);
    window.removeEventListener("keydown", unlockNotificationSound);
    window.removeEventListener('keydown', onKey);
    document.removeEventListener('visibilitychange', visibilityChanged);
    window.clearTimeout(searchTimer);
    window.clearTimeout(messageSearchTimer);
    window.clearTimeout(notificationTimer);
    window.clearTimeout(pressTimer);
    window.clearInterval(presenceTimer);
    window.clearInterval(nowTimer);
    cancelRecording();
    listeningIds.forEach(id => echo().leave('conversation.' + id));
});
</script>

<template>
    <div class="h-[100dvh] overflow-hidden bg-white">
        <!-- New message toast -->
        <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="-translate-y-4 opacity-0"
            enter-to-class="translate-y-0 opacity-100" leave-active-class="transition duration-200 ease-in"
            leave-from-class="translate-y-0 opacity-100" leave-to-class="-translate-y-2 opacity-0">
            <div v-if="notification" :key="notification.message.id" role="status"
                class="fixed inset-x-3 top-[max(0.75rem,env(safe-area-inset-top))] z-[60] sm:inset-x-auto sm:right-4 sm:top-4 sm:w-96">
                <div class="relative flex items-center gap-1 overflow-hidden rounded-2xl bg-white p-3 pr-2 shadow-2xl ring-1 ring-stone-900/10">
                    <button @click="open(notification.conversation); notification = null"
                        class="flex min-w-0 flex-1 items-center gap-3 text-left">
                        <span :class="tone(notification.message.sender.name)"
                            class="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-full text-sm font-bold">
                            <img v-if="notification.message.sender.avatar_url" :src="notification.message.sender.avatar_url"
                                :alt="notification.message.sender.name" class="h-full w-full object-cover">
                            <template v-else>{{ initials(notification.message.sender.name) }}</template>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline justify-between gap-2">
                                <b class="truncate text-sm text-stone-900">{{ notification.conversation.name }}</b>
                                <span class="shrink-0 text-[11px] text-[#0866ff]">now</span>
                            </span>
                            <span class="block truncate text-[13px] text-stone-600">
                                <template v-if="notification.conversation.type === 'group'">{{ notification.message.sender.name }}: </template>{{ notificationText(notification.message) }}
                            </span>
                        </span>
                    </button>
                    <button @click="notification = null" aria-label="Dismiss"
                        class="grid h-8 w-8 shrink-0 place-items-center rounded-full text-lg text-stone-400 hover:bg-stone-100">×</button>
                    <span class="pulse-toast-bar absolute inset-x-0 bottom-0 h-0.5 origin-left bg-[#0866ff]" />
                </div>
            </div>
        </Transition>


        <main class="relative flex h-full w-full overflow-hidden bg-white pl-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)]">

            <!-- Chats list -->
            <aside :class="active ? 'hidden md:flex' : 'flex'"
                class="w-full shrink-0 flex-col border-r border-stone-200 bg-white md:w-[22rem] xl:w-[24rem]">
                <div class="px-4 pb-1 pt-[max(1rem,env(safe-area-inset-top))] md:pt-4">
                    <div class="flex items-center justify-between gap-2">
                        <h1 class="text-[24px] font-bold tracking-tight text-stone-900">Chats</h1>
                        <div class="flex shrink-0 items-center gap-2">
                            <a href="/feed" title="Go to feed" aria-label="Go to feed"
                                class="grid h-9 w-9 place-items-center rounded-full bg-[#f0f2f5] text-stone-800 transition hover:bg-[#e4e6eb] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#0866ff]">
                                <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="icons.home" /></svg>
                            </a>
                            <button @click="showGroup = true" title="Create group" aria-label="Create group"
                                class="grid h-9 w-9 place-items-center rounded-full bg-[#f0f2f5] text-stone-800 transition hover:bg-[#e4e6eb] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#0866ff]">
                                <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="icons.group" /></svg>
                            </button>
                            <button @click="showSearch = true" title="New message" aria-label="New message"
                                class="grid h-9 w-9 place-items-center rounded-full bg-[#f0f2f5] text-stone-800 transition hover:bg-[#e4e6eb] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#0866ff]">
                                <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="icons.edit" /></svg>
                            </button>
                        </div>
                    </div>

                    <label class="mt-3 flex items-center gap-2 rounded-full bg-[#f0f2f5] px-3.5 transition focus-within:ring-2 focus-within:ring-[#0866ff]/30">
                        <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 text-stone-500" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="6" /><path stroke-linecap="round" d="m16 16 4 4" /></svg>
                        <input v-model="filter" type="search" placeholder="Search Messenger" aria-label="Search conversations"
                            class="h-10 min-w-0 flex-1 appearance-none border-0 bg-transparent p-0 text-base outline-none ring-0 placeholder:text-stone-500 focus:border-0 focus:outline-none focus:ring-0 sm:text-[15px]">
                    </label>

                    <div class="mt-3 flex gap-1.5" role="tablist" aria-label="Conversation filter">
                        <button role="tab" :aria-selected="tab === 'all'" @click="tab = 'all'"
                            :class="tab === 'all' ? 'bg-[#e7f3ff] text-[#0866ff]' : 'text-stone-600 hover:bg-[#f0f2f5]'"
                            class="rounded-full px-3.5 py-1.5 text-[14px] font-semibold transition">All</button>
                        <button role="tab" :aria-selected="tab === 'unread'" @click="tab = 'unread'"
                            :class="tab === 'unread' ? 'bg-[#e7f3ff] text-[#0866ff]' : 'text-stone-600 hover:bg-[#f0f2f5]'"
                            class="rounded-full px-3.5 py-1.5 text-[14px] font-semibold transition">Unread<span v-if="totalUnread" class="ml-1.5 rounded-full bg-[#0866ff] px-1.5 py-0.5 text-[10px] font-bold text-white">{{ totalUnread }}</span></button>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-2 pb-2 pt-1">
                    <button v-for="c in filtered" :key="c.id" @click="open(c)"
                        :aria-current="active?.id === c.id ? 'true' : undefined"
                        :class="active?.id === c.id ? 'bg-[#ebf5ff]' : 'hover:bg-[#f2f3f5] active:bg-[#e9ebee]'"
                        class="flex w-full items-center gap-3 rounded-xl p-2.5 text-left transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#0866ff]">
                        <div class="relative shrink-0">
                            <div :class="tone(c.name)" class="grid h-14 w-14 place-items-center overflow-hidden rounded-full text-base font-bold">
                                <img v-if="c.avatar_url" :src="c.avatar_url" :alt="c.name" class="h-full w-full object-cover">
                                <span v-else>{{ initials(c.name) }}</span>
                            </div>
                            <span v-if="peer(c)?.is_online" class="absolute bottom-0 right-0 h-4 w-4 rounded-full border-[2.5px] border-white bg-[#31a24c]" title="Active now" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p :class="c.unread_count ? 'font-bold' : 'font-medium'" class="truncate text-[15px] text-stone-900">{{ c.name }}</p>
                            <p :class="c.unread_count ? 'font-semibold text-stone-900' : 'text-stone-500'" class="mt-0.5 flex text-[13px]">
                                <span class="truncate">{{ listPreview(c) }}</span>
                                <span v-if="c.last_message" class="shrink-0 whitespace-pre"> · {{ listTime(c.last_message.created_at) }}</span>
                            </p>
                        </div>
                        <span v-if="c.unread_count" :aria-label="c.unread_count + ' unread'" class="h-3 w-3 shrink-0 rounded-full bg-[#0866ff]" />
                    </button>
                    <div v-if="!conversations.length" class="px-6 py-14 text-center">
                        <p class="text-3xl" aria-hidden="true">💬</p>
                        <p class="mt-2 text-sm font-semibold text-stone-800">No conversations yet</p>
                        <p class="mt-1 text-sm text-stone-500">Start one with the compose button above.</p>
                    </div>
                    <p v-else-if="!filtered.length" class="px-6 py-12 text-center text-sm text-stone-500">{{ filter ? `No conversation matches “${filter}”.` : 'You have no unread messages.' }}</p>
                </div>

                <a href="/profile" class="m-2 flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm font-medium text-stone-600 transition hover:bg-[#f2f3f5]">
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-[#f0f2f5] text-stone-700" aria-hidden="true">⚙</span>
                    Profile settings
                </a>
            </aside>

            <!-- Conversation -->
            <section :class="active ? 'flex' : 'hidden md:flex'" class="min-h-0 min-w-0 flex-1 flex-col bg-white">
                <template v-if="active">
                    <header class="flex shrink-0 select-none items-center gap-2.5 border-b border-stone-200 bg-white px-2 pb-2 pt-[max(0.5rem,env(safe-area-inset-top))] shadow-[0_1px_2px_rgba(0,0,0,0.04)] sm:px-4 md:py-2.5">
                        <button @click="active = null" aria-label="Back to conversations"
                            class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-[#0866ff] hover:bg-[#f0f2f5] md:hidden">
                            <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7" /></svg>
                        </button>
                        <button @click="openDetails" class="relative shrink-0 rounded-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#0866ff]" title="Conversation details" aria-label="Open conversation details">
                            <div :class="tone(active.name)" class="grid h-10 w-10 place-items-center overflow-hidden rounded-full text-sm font-bold">
                                <img v-if="active.avatar_url" :src="active.avatar_url" :alt="active.name" class="h-full w-full object-cover">
                                <span v-else>{{ initials(active.name) }}</span>
                            </div>
                            <span v-if="isOnline" class="absolute bottom-0 right-0 h-3 w-3 rounded-full border-2 border-white bg-[#31a24c]" />
                        </button>
                        <button @click="openDetails" class="min-w-0 flex-1 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#0866ff]">
                            <h2 class="truncate text-[15px] font-semibold leading-tight text-stone-900">{{ active.name }}</h2>
                            <p :class="isOnline ? 'text-[#31a24c]' : 'text-stone-500'" class="truncate text-xs">{{ status }}</p>
                        </button>
                        <button @click="detailsOpen ? (detailsOpen = false) : openDetails()" title="Conversation info" aria-label="Conversation info" :aria-pressed="detailsOpen"
                            :class="detailsOpen ? 'bg-[#e7f3ff]' : 'hover:bg-[#f0f2f5]'" class="grid h-10 w-10 place-items-center rounded-full text-[#0866ff] transition">
                            <svg viewBox="0 0 24 24" class="h-[22px] w-[22px]" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 15h-2v-6h2v6zm-1-8a1.25 1.25 0 1 1 0-2.5A1.25 1.25 0 0 1 12 9z" /></svg>
                        </button>
                    </header>

                    <div class="relative min-h-0 flex-1 bg-white">
                        <div ref="chat" @scroll.passive="onScroll" class="h-full overflow-y-auto overscroll-contain">
                            <div class="w-full px-3 pb-4 pt-3 sm:px-5 lg:px-8">
                                <div v-if="loading" class="space-y-3 pt-4" aria-label="Loading messages" role="status">
                                    <div class="h-9 w-44 animate-pulse rounded-[18px] bg-[#f0f0f0]" />
                                    <div class="ml-auto h-9 w-52 animate-pulse rounded-[18px] bg-blue-100" />
                                    <div class="h-12 w-60 animate-pulse rounded-[18px] bg-[#f0f0f0]" />
                                    <div class="ml-auto h-9 w-36 animate-pulse rounded-[18px] bg-blue-100" />
                                </div>
                                <div v-else-if="!messages.length" class="flex flex-col items-center pt-16 text-center">
                                    <div :class="tone(active.name)" class="grid h-20 w-20 place-items-center overflow-hidden rounded-full text-2xl font-bold">
                                        <img v-if="active.avatar_url" :src="active.avatar_url" :alt="active.name" class="h-full w-full object-cover">
                                        <span v-else>{{ initials(active.name) }}</span>
                                    </div>
                                    <p class="mt-3 text-lg font-bold text-stone-900">{{ active.name }}</p>
                                    <p class="mt-1 text-sm text-stone-500">No messages yet. Say hello 👋</p>
                                </div>

                                <template v-for="{ m, newDay, gap, first, last, mine } in rows" :key="m.id">
                                    <div v-if="newDay || gap" class="my-3 select-none text-center text-xs font-medium text-stone-500">{{ stamp(m.created_at) }}</div>
                                    <div v-if="isFirstUnread(m)" class="my-3 flex items-center gap-3 text-xs font-semibold text-[#0866ff]">
                                        <span class="h-px flex-1 bg-blue-200" /><span>{{ unreadCountInView }} unread message{{ unreadCountInView === 1 ? '' : 's' }}</span><span class="h-px flex-1 bg-blue-200" />
                                    </div>

                                    <div :id="`message-${m.id}`" :class="[mine ? 'justify-end' : 'justify-start', first ? 'mt-2' : 'mt-0.5']" class="group flex scroll-mt-6 items-end gap-2">
                                        <!-- avatar beside the last message of each run (private and group) -->
                                        <template v-if="!mine">
                                            <span v-if="last" :class="tone(m.sender.name)" class="mb-0.5 grid h-7 w-7 shrink-0 place-items-center overflow-hidden rounded-full text-[10px] font-bold">
                                                <img v-if="m.sender.avatar_url" :src="m.sender.avatar_url" :alt="m.sender.name" class="h-full w-full object-cover">
                                                <template v-else>{{ initials(m.sender.name) }}</template>
                                            </span>
                                            <span v-else class="w-7 shrink-0" />
                                        </template>

                                        <div :class="mine ? 'items-end' : 'items-start'" class="flex min-w-0 max-w-[80%] flex-col sm:max-w-[70%] lg:max-w-[65%]">
                                            <span v-if="!mine && active.type === 'group' && first" class="mb-0.5 ml-3 text-xs text-stone-500">{{ m.sender.name }}</span>

                                            <!-- Messenger-style reply: label plus a faded quote tucked behind the bubble -->
                                            <template v-if="m.reply_to && !m.deleted_at">
                                                <p class="mb-0.5 flex items-center gap-1 px-1 text-[12px] text-stone-500">
                                                    <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="icons.reply" /></svg>
                                                    {{ mine ? 'You' : m.sender.name }} replied to {{ m.reply_to.sender }}
                                                </p>
                                                <p class="-mb-3 line-clamp-2 max-w-full rounded-[18px] bg-[#e4e6eb] px-3 pb-5 pt-2 text-[13px] text-stone-600 [overflow-wrap:anywhere]">{{ m.reply_to.message || 'Attachment' }}</p>
                                            </template>

                                            <div :class="mine ? 'flex-row-reverse' : ''" class="relative z-[1] flex max-w-full items-center gap-1">
                                                <div :title="time(m.created_at)" :class="[
                                                    jumbo(m) ? '' : shape(mine, first, last),
                                                    m.deleted_at ? 'border border-stone-200 italic text-stone-500' : jumbo(m) ? '' : mine ? 'bg-[#0084ff] text-white' : 'bg-[#f0f0f0] text-stone-900',
                                                    menuId === m.id ? 'ring-2 ring-[#0866ff]/50' : '',
                                                    highlightId === m.id ? 'ring-4 ring-[#0866ff]/40 transition' : '',
                                                    jumbo(m) ? 'text-5xl leading-tight' : m.type === 'image' && !m.deleted_at ? 'p-0.5' : 'px-3 py-[7px]']"
                                                    class="min-w-0 text-[15px] leading-snug [-webkit-touch-callout:none] [@media(hover:none)]:select-none"
                                                    @pointerdown="pressStart($event, m)" @pointermove="pressMove"
                                                    @pointerup="pressCancel" @pointercancel="pressCancel" @pointerleave="pressCancel"
                                                    @contextmenu="touchPress && $event.preventDefault()">
                                                    <p v-if="m.deleted_at">You unsent a message</p>
                                                    <div v-else-if="m.type === 'image'" class="w-56 max-w-full sm:w-64 lg:w-80">
                                                        <button v-if="!imageLoadFailures.has(m.id)" type="button" @click="imageViewer = m"
                                                            class="block w-full overflow-hidden rounded-2xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0866ff]">
                                                            <img :src="m.file_url" :alt="m.file_name || 'Image'" loading="lazy" @error="imageFailed(m)" class="max-h-[50dvh] w-full object-cover">
                                                        </button>
                                                        <div v-else class="rounded-xl bg-white/80 px-3 py-4 text-center text-xs text-stone-500">
                                                            Image could not be loaded.
                                                            <button type="button" @click="retryImage(m)" class="ml-1 font-semibold underline">Retry</button>
                                                        </div>
                                                    </div>
                                                    <a v-else-if="m.type === 'file'" :href="m.file_url" target="_blank" rel="noopener"
                                                        :class="mine ? 'bg-black/15' : 'bg-black/5'" class="flex items-center gap-2.5 rounded-xl px-2.5 py-2">
                                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-white/90 text-lg text-stone-700" aria-hidden="true">📄</span>
                                                        <span class="min-w-0 break-all text-sm font-medium">{{ m.file_name }}<small v-if="m.file_size" class="block text-xs font-normal opacity-70">{{ fileSize(m.file_size) }}</small></span>
                                                    </a>
                                                    <div v-else-if="m.type === 'voice'" class="flex min-w-[210px] items-center gap-2">
                                                        <audio :src="m.file_url" controls preload="metadata" data-voice-message @play="pauseOtherAudio" class="h-9 max-w-[220px]" />
                                                        <span class="text-xs opacity-80">{{ duration(m.audio_duration) }}</span>
                                                    </div>
                                                    <p v-else class="whitespace-pre-wrap break-words [overflow-wrap:anywhere]"><template v-for="(p, i) in parts(m.message)" :key="i"><a v-if="p.url" :href="p.url" target="_blank" rel="noopener noreferrer" :class="mine ? 'underline' : 'text-[#0866ff] underline'" @click.stop>{{ p.text }}</a><template v-else>{{ p.text }}</template></template></p>
                                                </div>

                                                <!-- mouse: react, reply, more on hover. touch: long-press the message -->
                                                <div v-if="!m.deleted_at"
                                                    :class="reactFor === m.id ? '[@media(hover:hover)]:!opacity-100' : ''"
                                                    class="relative hidden shrink-0 items-center text-stone-500 transition-opacity focus-within:opacity-100 [@media(hover:hover)]:flex [@media(hover:hover)]:opacity-0 [@media(hover:hover)]:group-hover:opacity-100">
                                                    <button @click.stop="reactFor = reactFor === m.id ? null : m.id" title="React" aria-label="React to message" class="grid h-8 w-8 place-items-center rounded-full hover:bg-[#f0f2f5]"><svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9" /><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9.5h.01M15 9.5h.01" /></svg></button>
                                                    <button @click="startReply(m)" title="Reply" aria-label="Reply" class="grid h-8 w-8 place-items-center rounded-full hover:bg-[#f0f2f5]"><svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="icons.reply" /></svg></button>
                                                    <button @click="menuId = m.id" title="More" aria-label="More actions" class="grid h-8 w-8 place-items-center rounded-full hover:bg-[#f0f2f5]"><svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M5 12h.01M12 12h.01M19 12h.01" /></svg></button>
                                                    <div v-if="reactFor === m.id" :class="mine ? 'right-0' : 'left-0'" class="absolute bottom-full z-30 mb-1 flex gap-0.5 rounded-full bg-white p-1 shadow-lg ring-1 ring-stone-200">
                                                        <button v-for="emoji in ['👍', '❤️', '😂']" :key="emoji" @click="react(m, emoji); reactFor = null" :aria-label="'React ' + emoji" class="h-9 w-9 rounded-full text-xl transition hover:scale-125">{{ emoji }}</button>
                                                    </div>
                                                </div>
                                            </div>

                                            <div v-if="m.reactions.length" :class="mine ? 'mr-2' : 'ml-2'" class="relative z-[2] -mt-1.5 flex flex-wrap gap-1">
                                                <button v-for="r in grouped(m)" :key="r.emoji" @click="react(m, r.emoji)"
                                                    :class="r.mine ? 'ring-[#0866ff]/40' : 'ring-stone-200'"
                                                    class="rounded-full bg-white px-1.5 py-px text-xs shadow-sm ring-1 transition hover:scale-105">{{ r.emoji }}<span v-if="r.count > 1" class="ml-0.5 font-semibold text-stone-600">{{ r.count }}</span></button>
                                            </div>

                                            <span v-if="m.edited_at && !m.deleted_at" class="mx-2 mt-0.5 select-none text-[11px] text-stone-400">Edited</span>

                                            <!-- Messenger-style seen: tiny avatar of the reader under the last message -->
                                            <div v-if="mine && m.id === lastMineId" class="mt-0.5 flex select-none items-center justify-end gap-1 pr-1">
                                                <template v-if="isSeen(m) && active.type === 'private' && peer(active)">
                                                    <span :title="readStatus(m) ?? ''" :class="tone(peer(active)!.name)" class="grid h-4 w-4 place-items-center overflow-hidden rounded-full text-[7px] font-bold">
                                                        <img v-if="peer(active)!.avatar_url" :src="peer(active)!.avatar_url!" :alt="'Seen by ' + peer(active)!.name" class="h-full w-full object-cover">
                                                        <template v-else>{{ initials(peer(active)!.name) }}</template>
                                                    </span>
                                                </template>
                                                <span v-else class="text-[11px] text-stone-500">{{ readStatus(m) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- jump to latest -->
                        <Transition enter-active-class="transition duration-150 ease-out" enter-from-class="translate-y-2 opacity-0" leave-active-class="transition duration-100" leave-to-class="opacity-0">
                            <button v-if="!atBottom && messages.length" @click="jumpToBottom" :aria-label="newBelow ? `${newBelow} new messages, jump to latest` : 'Jump to latest message'"
                                class="absolute bottom-3 left-1/2 grid h-9 w-9 -translate-x-1/2 place-items-center rounded-full bg-white text-[#0866ff] shadow-lg ring-1 ring-stone-200 transition hover:bg-stone-50 active:scale-95 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#0866ff]">
                                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="icons.down" /></svg>
                                <span v-if="newBelow" class="absolute -right-1.5 -top-1.5 grid h-5 min-w-5 place-items-center rounded-full bg-[#0866ff] px-1 text-[10px] font-bold text-white ring-2 ring-white">{{ newBelow }}</span>
                            </button>
                        </Transition>
                    </div>

                    <!-- closes the reaction tray / emoji picker when clicking elsewhere -->
                    <div v-if="reactFor !== null || emojiOpen" class="fixed inset-0 z-10" @click="reactFor = null; emojiOpen = false" />

                    <footer class="shrink-0 bg-white px-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] pt-1.5 sm:px-4">
                        <div class="w-full">
                            <div v-if="reply" class="mb-2 flex items-center justify-between gap-3 rounded-xl border-l-4 border-[#0866ff] bg-[#f0f2f5] px-3 py-2 text-xs text-stone-700">
                                <span class="min-w-0 truncate">Replying to <b>{{ reply.sender.id === currentUser.id ? 'yourself' : reply.sender.name }}</b>: {{ reply.message || reply.file_name || notificationText(reply) }}</span>
                                <button @click="reply = null" aria-label="Cancel reply" class="shrink-0 text-base leading-none">×</button>
                            </div>
                            <div v-if="attachment" class="mb-2 flex items-center justify-between gap-3 rounded-xl bg-[#f0f2f5] px-3 py-2 text-xs text-stone-600">
                                <span class="min-w-0 truncate">📎 {{ attachment.name }} · {{ fileSize(attachment.size) }}</span>
                                <button @click="attachment = null; fileInput && (fileInput.value = '')" aria-label="Remove attachment" class="shrink-0 text-base leading-none">×</button>
                            </div>
                            <p v-if="uploadError" class="mb-2 text-xs font-medium text-rose-600">{{ uploadError }}</p>
                            <p v-if="recordingError" class="mb-2 text-xs font-medium text-rose-600">{{ recordingError }}</p>

                            <input ref="fileInput" type="file" class="hidden" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt" @change="selectAttachment">

                            <div v-if="recording" class="flex items-center justify-between gap-3 rounded-full bg-[#f0f2f5] py-1.5 pl-5 pr-1.5 text-sm text-stone-800">
                                <span class="flex items-center gap-2 font-medium"><span class="h-2.5 w-2.5 animate-pulse rounded-full bg-rose-600" /> Recording {{ duration(recordingSeconds) }}</span>
                                <span class="flex items-center gap-1">
                                    <button @click="cancelRecording" class="h-9 rounded-full px-4 font-semibold hover:bg-[#e4e6eb]">Cancel</button>
                                    <button @click="stopRecordingAndSend" :disabled="busy" class="h-9 rounded-full bg-[#0866ff] px-5 font-semibold text-white hover:bg-[#0756d6] disabled:opacity-50">Send</button>
                                </span>
                            </div>
                            <div v-else class="flex items-end gap-0.5">
                                <button @click="startRecording" :disabled="busy" title="Record voice message" aria-label="Record voice message"
                                    class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-[#0866ff] transition hover:bg-[#f0f2f5] disabled:opacity-40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#0866ff]">
                                    <svg viewBox="0 0 24 24" class="h-[22px] w-[22px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="icons.mic" /></svg>
                                </button>
                                <button @click="fileInput?.click()" title="Attach a file" aria-label="Attach a file"
                                    class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-[#0866ff] transition hover:bg-[#f0f2f5] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#0866ff]">
                                    <svg viewBox="0 0 24 24" class="h-[22px] w-[22px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="icons.clip" /></svg>
                                </button>
                                <div class="relative mx-1 flex min-h-10 flex-1 items-end rounded-[20px] bg-[#f0f2f5] pl-3.5 pr-1 transition focus-within:ring-2 focus-within:ring-[#0866ff]/25">
                                    <textarea ref="composer" v-model="draft" @keydown.enter.exact="onEnter" placeholder="Aa" aria-label="Write a message" rows="1"
                                        class="max-h-32 min-h-10 w-full resize-none border-0 bg-transparent p-0 py-2.5 text-base outline-none ring-0 placeholder:text-stone-500 focus:border-0 focus:outline-none focus:ring-0 sm:text-[15px]" />
                                    <button @click.stop="emojiOpen = !emojiOpen" title="Choose an emoji" aria-label="Choose an emoji" :aria-expanded="emojiOpen"
                                        class="mb-1 grid h-8 w-8 shrink-0 place-items-center rounded-full text-[#0866ff] transition hover:bg-[#e4e6eb]">
                                        <svg viewBox="0 0 24 24" class="h-[22px] w-[22px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9.5h.01M15 9.5h.01" /></svg>
                                    </button>
                                    <div v-if="emojiOpen" class="absolute bottom-full right-0 z-30 mb-2 grid w-72 grid-cols-8 gap-0.5 rounded-2xl bg-white p-2 shadow-xl ring-1 ring-stone-200">
                                        <button v-for="emoji in emojis" :key="emoji" @click="addEmoji(emoji)" class="grid h-8 w-8 place-items-center rounded-lg text-xl transition hover:scale-110 hover:bg-[#f0f2f5]">{{ emoji }}</button>
                                    </div>
                                </div>
                                <button v-if="canSend" @click="send" :disabled="busy" title="Press Enter to send" aria-label="Send message"
                                    class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-[#0866ff] transition hover:bg-[#f0f2f5] active:scale-95 disabled:opacity-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#0866ff]">
                                    <svg viewBox="0 0 24 24" class="h-[22px] w-[22px]" fill="currentColor" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="icons.send" /></svg>
                                </button>
                                <button v-else @click="sendLike" :disabled="busy" title="Send a like" aria-label="Send a like"
                                    class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-[22px] transition hover:bg-[#f0f2f5] active:scale-90 disabled:opacity-40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#0866ff]">👍</button>
                            </div>
                        </div>
                    </footer>
                </template>

                <div v-else class="m-auto max-w-xs px-6 text-center">
                    <div class="mx-auto grid h-20 w-20 place-items-center rounded-full bg-[#e7f3ff] text-4xl" aria-hidden="true">💬</div>
                    <h2 class="mt-4 text-xl font-bold text-stone-900">Select a chat</h2>
                    <p class="mt-1.5 text-sm text-stone-500">Choose a conversation from the list or start a new one.</p>
                    <button @click="showSearch = true" class="mt-5 rounded-full bg-[#0866ff] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#0756d6]">New message</button>
                </div>
            </section>

            <!-- Chat info panel -->
            <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="translate-x-full opacity-0" enter-to-class="translate-x-0 opacity-100" leave-active-class="transition duration-150 ease-in" leave-from-class="translate-x-0 opacity-100" leave-to-class="translate-x-full opacity-0">
                <aside v-if="active && detailsOpen" class="absolute inset-y-0 right-0 z-20 flex w-full flex-col border-l border-stone-200 bg-white shadow-2xl md:relative md:w-[22rem] md:shadow-none xl:w-[24rem]" aria-label="Conversation details">
                    <div class="flex items-center gap-2 px-2 py-2 md:justify-end">
                        <button @click="detailsOpen = false" aria-label="Close details" class="grid h-9 w-9 place-items-center rounded-full text-stone-600 hover:bg-[#f0f2f5] md:hidden"><svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7" /></svg></button>
                        <button @click="detailsOpen = false" aria-label="Close details" class="hidden h-9 w-9 place-items-center rounded-full text-xl text-stone-500 hover:bg-[#f0f2f5] md:grid">×</button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pb-6">
                        <div class="text-center">
                            <div :class="tone(active.name)" class="mx-auto grid h-24 w-24 place-items-center overflow-hidden rounded-full text-3xl font-bold"><img v-if="active.avatar_url" :src="active.avatar_url" :alt="active.name" class="h-full w-full object-cover"><span v-else>{{ initials(active.name) }}</span></div>
                            <p class="mt-3 text-xl font-bold text-stone-900">{{ active.name }}</p>
                            <p :class="isOnline ? 'text-[#31a24c]' : 'text-stone-500'" class="text-[13px]">{{ status }}</p>
                        </div>

                        <div class="mt-5 flex justify-center gap-6 text-center text-xs font-medium text-stone-700">
                            <a href="/profile" class="group flex w-16 flex-col items-center gap-1.5"><span class="grid h-10 w-10 place-items-center rounded-full bg-[#f0f2f5] text-stone-800 transition group-hover:bg-[#e4e6eb]"><svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4" /><path d="M4 21c1-4 4-6 8-6s7 2 8 6" /></svg></span>Profile</a>
                            <button class="flex w-16 cursor-not-allowed flex-col items-center gap-1.5 opacity-50" disabled title="Mute isn't available yet"><span class="grid h-10 w-10 place-items-center rounded-full bg-[#f0f2f5] text-stone-800"><svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8M10 20a2 2 0 0 0 4 0" /></svg></span>Mute</button>
                            <button @click="focusSearch" class="group flex w-16 flex-col items-center gap-1.5"><span class="grid h-10 w-10 place-items-center rounded-full bg-[#f0f2f5] text-stone-800 transition group-hover:bg-[#e4e6eb]"><svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="6" /><path d="m16 16 4 4" /></svg></span>Search</button>
                        </div>

                        <label class="mt-5 flex items-center gap-2 rounded-full bg-[#f0f2f5] px-3.5 focus-within:ring-2 focus-within:ring-[#0866ff]/30">
                            <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 text-stone-500" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="6" /><path stroke-linecap="round" d="m16 16 4 4" /></svg>
                            <input ref="searchBox" v-model="messageSearch" type="search" placeholder="Search in conversation" aria-label="Search in conversation" class="h-9 min-w-0 flex-1 appearance-none border-0 bg-transparent p-0 text-[15px] outline-none ring-0 placeholder:text-stone-500 focus:border-0 focus:ring-0">
                        </label>
                        <div v-if="messageSearch && searchResults.length" class="mt-2 max-h-52 overflow-y-auto">
                            <button v-for="result in searchResults" :key="result.id" @click="jumpToMessage(result)" class="block w-full rounded-xl px-3 py-2 text-left hover:bg-[#f2f3f5]">
                                <span class="block text-xs text-stone-500">{{ result.sender.name }} · {{ stamp(result.created_at) }}</span>
                                <span class="line-clamp-1 text-sm text-stone-900">{{ result.message }}</span>
                            </button>
                        </div>
                        <p v-else-if="messageSearch.trim()" class="mt-3 text-center text-sm text-stone-500">No messages found.</p>

                        <p v-if="detailsLoading" class="py-8 text-center text-sm text-stone-500">Loading shared items…</p>
                        <template v-else>
                            <section class="mt-4">
                                <button @click="detailsSections.media = !detailsSections.media" class="flex w-full items-center justify-between rounded-lg px-2 py-2.5 text-[15px] font-semibold text-stone-900 hover:bg-[#f2f3f5]">Media<svg viewBox="0 0 24 24" :class="detailsSections.media ? 'rotate-180' : ''" class="h-4 w-4 transition" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6" /></svg></button>
                                <div v-if="detailsSections.media" class="mt-1 grid grid-cols-3 gap-1"><button v-for="item in details.media" :key="item.id" @click="imageViewer = item" class="aspect-square overflow-hidden rounded-lg bg-stone-100"><img :src="item.file_url" :alt="item.file_name || 'Shared image'" loading="lazy" class="h-full w-full object-cover transition hover:scale-105"></button><p v-if="!details.media.length" class="col-span-3 px-2 text-sm text-stone-500">No shared media yet.</p></div>
                            </section>
                            <section class="mt-1">
                                <button @click="detailsSections.files = !detailsSections.files" class="flex w-full items-center justify-between rounded-lg px-2 py-2.5 text-[15px] font-semibold text-stone-900 hover:bg-[#f2f3f5]">Files<svg viewBox="0 0 24 24" :class="detailsSections.files ? 'rotate-180' : ''" class="h-4 w-4 transition" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6" /></svg></button>
                                <div v-if="detailsSections.files" class="mt-1 space-y-0.5"><a v-for="item in details.files" :key="item.id" :href="item.file_url" target="_blank" rel="noopener" class="flex items-center gap-2.5 rounded-lg p-2 text-sm hover:bg-[#f2f3f5]"><span class="grid h-9 w-9 place-items-center rounded-full bg-[#f0f2f5]" aria-hidden="true">📄</span><span class="min-w-0 flex-1 truncate">{{ item.file_name }}</span><small class="text-stone-500">{{ fileSize(item.file_size) }}</small></a><p v-if="!details.files.length" class="px-2 text-sm text-stone-500">No shared files yet.</p></div>
                            </section>
                            <section class="mt-1">
                                <button @click="detailsSections.links = !detailsSections.links" class="flex w-full items-center justify-between rounded-lg px-2 py-2.5 text-[15px] font-semibold text-stone-900 hover:bg-[#f2f3f5]">Links<svg viewBox="0 0 24 24" :class="detailsSections.links ? 'rotate-180' : ''" class="h-4 w-4 transition" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6" /></svg></button>
                                <div v-if="detailsSections.links" class="mt-1 space-y-0.5"><a v-for="item in details.links" :key="`${item.id}-${item.url}`" :href="item.url" target="_blank" rel="noopener noreferrer" class="block truncate rounded-lg p-2 text-sm text-[#0866ff] hover:bg-[#f2f3f5]">{{ item.url }}</a><p v-if="!details.links.length" class="px-2 text-sm text-stone-500">No shared links yet.</p></div>
                            </section>
                        </template>
                    </div>
                </aside>
            </Transition>
        </main>

        <!-- Long-press message actions (touch devices) -->
        <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0"
            enter-to-class="opacity-100" leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="menuMessage" @click.self="menuId = null" role="dialog" aria-modal="true"
                class="fixed inset-0 z-50 flex items-end justify-center bg-stone-950/40 sm:items-center sm:p-4">
                <div class="max-h-[90dvh] w-full max-w-lg overflow-y-auto rounded-t-3xl sm:max-w-sm sm:rounded-3xl bg-white p-4 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-2xl">
                    <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-stone-300" />
                    <p class="mb-3 line-clamp-2 rounded-xl bg-stone-100 px-3 py-2 text-sm text-stone-600">
                        {{ menuMessage.message || menuMessage.file_name || notificationText(menuMessage) }}
                    </p>
                    <div class="mb-2 flex justify-center gap-4">
                        <button v-for="emoji in ['👍', '❤️', '😂']" :key="emoji" :aria-label="'React ' + emoji"
                            @click="menuDo(m => react(m, emoji))"
                            class="grid h-12 w-12 place-items-center rounded-full bg-stone-100 text-2xl active:scale-95">{{ emoji }}</button>
                    </div>
                    <button @click="menuDo(startReply)"
                        class="flex h-12 w-full items-center gap-3 rounded-xl px-3 text-left text-[15px] font-medium text-stone-800 active:bg-stone-100"><svg viewBox="0 0 24 24" class="h-5 w-5 text-stone-500" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="icons.reply" /></svg>Reply</button>
                    <button @click="menuDo(openForward)"
                        class="flex h-12 w-full items-center gap-3 rounded-xl px-3 text-left text-[15px] font-medium text-stone-800 active:bg-stone-100"><svg viewBox="0 0 24 24" class="h-5 w-5 text-stone-500" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="icons.forward" /></svg>Forward</button>
                    <button v-if="menuMessage.type === 'text'" @click="menuDo(copyText)"
                        class="flex h-12 w-full items-center gap-3 rounded-xl px-3 text-left text-[15px] font-medium text-stone-800 active:bg-stone-100"><svg viewBox="0 0 24 24" class="h-5 w-5 text-stone-500" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg>Copy text</button>
                    <button v-if="menuMessage.sender_id === currentUser.id && menuMessage.type === 'text'" @click="menuDo(edit)"
                        class="flex h-12 w-full items-center gap-3 rounded-xl px-3 text-left text-[15px] font-medium text-stone-800 active:bg-stone-100"><svg viewBox="0 0 24 24" class="h-5 w-5 text-stone-500" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="icons.edit" /></svg>Edit</button>
                    <button v-if="menuMessage.sender_id === currentUser.id" @click="menuDo(remove)"
                        class="flex h-12 w-full items-center gap-3 rounded-xl px-3 text-left text-[15px] font-medium text-rose-600 active:bg-rose-50"><svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="icons.trash" /></svg>Delete</button>
                    <button v-else @click="menuDo(m => { reportMessage = m; reportError = '' })"
                        class="flex h-12 w-full items-center gap-3 rounded-xl px-3 text-left text-[15px] font-medium text-amber-700 active:bg-amber-50"><svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="icons.flag" /></svg>Report message</button>
                </div>
            </div>
        </Transition>

        <!-- Edit message -->
        <div v-if="editTarget" @click.self="editTarget = null" class="fixed inset-0 z-[80] flex items-end justify-center bg-stone-950/40 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-label="Edit message">
            <form class="w-full rounded-t-3xl bg-white p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-xl sm:max-w-md sm:rounded-3xl sm:pb-5" @submit.prevent="saveEdit">
                <div class="flex items-center justify-between"><h2 class="text-lg font-bold text-stone-900">Edit message</h2><button type="button" @click="editTarget = null" aria-label="Close" class="grid h-9 w-9 place-items-center rounded-full text-xl text-stone-500 hover:bg-stone-100">×</button></div>
                <textarea v-model="editText" rows="4" maxlength="5000" aria-label="Message text" class="mt-3 w-full resize-none rounded-2xl border border-stone-200 px-3.5 py-2.5 text-base outline-none focus:border-[#0866ff] focus:ring-2 focus:ring-[#0866ff]/20 sm:text-sm" />
                <p v-if="editError" class="mt-2 text-sm text-rose-600">{{ editError }}</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" @click="editTarget = null" class="rounded-xl px-4 py-2.5 text-sm font-medium text-stone-600 hover:bg-stone-100">Cancel</button>
                    <button :disabled="busy || !editText.trim()" class="rounded-xl bg-[#0866ff] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#0756d6] disabled:opacity-40">{{ busy ? 'Saving…' : 'Save changes' }}</button>
                </div>
            </form>
        </div>

        <div v-if="reportMessage" @click.self="reportMessage = null" class="fixed inset-0 z-[80] flex items-end justify-center bg-stone-950/40 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-label="Report message">
            <form class="w-full rounded-t-3xl bg-white p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-xl sm:max-w-md sm:rounded-3xl sm:pb-5" @submit.prevent="submitReport">
                <div class="flex items-center justify-between"><h2 class="text-lg font-bold text-stone-900">Report message</h2><button type="button" @click="reportMessage = null" aria-label="Close" class="grid h-9 w-9 place-items-center rounded-full text-xl text-stone-500 hover:bg-stone-100">×</button></div>
                <p class="mt-2 line-clamp-2 rounded-xl bg-stone-100 p-3 text-sm text-stone-600">{{ reportMessage.message || reportMessage.file_name || notificationText(reportMessage) }}</p>
                <label class="mt-4 block text-sm font-medium">Reason<select v-model="reportReason" class="mt-1 w-full rounded-xl border-stone-300"><option value="spam">Spam</option><option value="harassment">Harassment</option><option value="scam">Scam</option><option value="inappropriate">Inappropriate content</option><option value="other">Other</option></select></label>
                <label class="mt-3 block text-sm font-medium">Additional details<textarea v-model="reportDescription" maxlength="1000" class="mt-1 w-full rounded-xl border-stone-300" rows="3" /></label><p v-if="reportError" class="mt-2 text-sm text-rose-600">{{ reportError }}</p>
                <div class="mt-4 flex justify-end gap-2"><button type="button" @click="reportMessage = null" class="rounded-xl px-4 py-2 text-sm">Cancel</button><button :disabled="busy" class="rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Submit report</button></div>
            </form>
        </div>

        <!-- Image viewer -->
        <div v-if="imageViewer" @click.self="imageViewer = null" class="fixed inset-0 z-[70] flex items-center justify-center bg-stone-950/90 p-4" role="dialog" aria-modal="true" aria-label="Image viewer">
            <button @click="imageViewer = null" aria-label="Close image" class="absolute right-4 top-[max(1rem,env(safe-area-inset-top))] grid h-11 w-11 place-items-center rounded-full bg-white/15 text-2xl text-white hover:bg-white/25">×</button>
            <button v-if="viewerIndex >= 0 && details.media.length > 1" @click="viewerMove(-1)" aria-label="Previous image" class="absolute left-3 grid h-11 w-11 place-items-center rounded-full bg-white/15 text-2xl text-white hover:bg-white/25">‹</button>
            <img :src="imageViewer.file_url" :alt="imageViewer.file_name || 'Image'" class="max-h-[80dvh] max-w-full rounded-lg object-contain">
            <button v-if="viewerIndex >= 0 && details.media.length > 1" @click="viewerMove(1)" aria-label="Next image" class="absolute right-3 grid h-11 w-11 place-items-center rounded-full bg-white/15 text-2xl text-white hover:bg-white/25">›</button>
            <a :href="`${imageViewer.file_url}?download=1`" class="absolute bottom-[max(1rem,env(safe-area-inset-bottom))] rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-stone-900 hover:bg-stone-100">Download image</a>
        </div>

        <!-- Forward destination picker -->
        <div v-if="showForward && forwardMessage" @click.self="showForward = false; forwardMessage = null" class="fixed inset-0 z-[70] flex items-end justify-center bg-stone-950/40 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-label="Forward message">
            <div class="w-full rounded-t-3xl bg-white p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-xl sm:max-w-md sm:rounded-3xl sm:pb-5">
                <div class="mb-3 flex items-center justify-between gap-3"><h2 class="text-lg font-bold text-stone-900">Forward to</h2><button @click="showForward = false; forwardMessage = null" aria-label="Close" class="grid h-9 w-9 place-items-center rounded-full text-xl text-stone-500 hover:bg-stone-100">×</button></div>
                <p class="mb-3 line-clamp-2 rounded-xl bg-stone-100 px-3 py-2 text-sm text-stone-600">{{ forwardMessage.message || forwardMessage.file_name || notificationText(forwardMessage) }}</p>
                <div class="max-h-[55dvh] space-y-1 overflow-y-auto overscroll-contain">
                    <button v-for="conversation in conversations" :key="conversation.id" @click="forwardTo(conversation)" :disabled="busy" class="flex w-full items-center gap-3 rounded-xl p-2.5 text-left hover:bg-stone-50 disabled:opacity-50">
                        <span :class="tone(conversation.name)" class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full text-xs font-bold"><img v-if="conversation.avatar_url" :src="conversation.avatar_url" :alt="conversation.name" class="h-full w-full object-cover"><template v-else>{{ initials(conversation.name) }}</template></span>
                        <span class="truncate text-sm font-medium text-stone-900">{{ conversation.name }}</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- New chat / new group -->
        <div v-if="showSearch || showGroup" @click.self="closeModal"
            class="fixed inset-0 z-50 flex items-end justify-center bg-stone-950/40 sm:items-center sm:p-4" role="dialog" aria-modal="true">
            <div class="flex max-h-[90dvh] w-full flex-col rounded-t-3xl bg-white p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-xl sm:max-w-md sm:rounded-3xl sm:pb-5 lg:max-w-lg">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-bold text-stone-900">{{ showGroup ? 'Create group' : 'New chat' }}</h2>
                    <button @click="closeModal" aria-label="Close" class="grid h-9 w-9 place-items-center rounded-full text-xl text-stone-500 hover:bg-stone-100">×</button>
                </div>

                <input v-if="showGroup" v-model="groupName" placeholder="Group name" class="mb-3 w-full rounded-xl border border-stone-200 px-3 py-2.5 text-base outline-none focus:border-[#0866ff] focus:ring-2 focus:ring-[#0866ff]/20 sm:text-sm">
                <input v-model="query" :autofocus="!showGroup" placeholder="Search by name, username, or email" class="mb-3 w-full rounded-xl border border-stone-200 px-3 py-2.5 text-base outline-none focus:border-[#0866ff] focus:ring-2 focus:ring-[#0866ff]/20 sm:text-sm">

                <div v-if="showGroup && selected.length" class="mb-3 flex flex-wrap gap-1.5">
                    <button v-for="u in selected" :key="u.id" @click="toggle(u)" class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-[#0756d6]">{{ u.name }} ×</button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                    <button v-for="u in people" :key="u.id" @click="showGroup ? toggle(u) : start(u)"
                        class="flex w-full items-center gap-3 rounded-xl p-2.5 text-left hover:bg-stone-50">
                        <span :class="tone(u.name)" class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full text-xs font-bold">
                            <img v-if="u.avatar_url" :src="u.avatar_url" :alt="u.name" class="h-full w-full object-cover">
                            <template v-else>{{ initials(u.name) }}</template>
                        </span>
                        <span class="min-w-0 flex-1">
                            <b class="block truncate text-sm text-stone-900">{{ u.name }}</b>
                            <small class="block truncate text-stone-500">@{{ u.username }}</small>
                        </span>
                        <span v-if="showGroup && selected.some(x => x.id === u.id)" class="text-[#0866ff]">✓</span>
                    </button>
                    <p v-if="query && !people.length" class="py-8 text-center text-sm text-stone-500">No users found. Check the spelling or try a username.</p>
                    <p v-else-if="!query" class="py-8 text-center text-sm text-stone-400">Type a name, username, or email to find people.</p>
                </div>

                <button v-if="showGroup" @click="createGroup" :disabled="!groupName.trim() || !selected.length"
                    class="mt-3 w-full rounded-xl bg-[#0866ff] py-3 text-sm font-semibold text-white hover:bg-[#0756d6] disabled:opacity-40">
                    Create group{{ selected.length ? ` (${selected.length})` : '' }}
                </button>
            </div>
        </div>
    </div>
</template>

<style>
.pulse-toast-bar { animation: pulse-toast 5s linear forwards; }
@keyframes pulse-toast { from { transform: scaleX(1); } to { transform: scaleX(0); } }
@media (prefers-reduced-motion: reduce) { .pulse-toast-bar { animation: none; } }
</style>