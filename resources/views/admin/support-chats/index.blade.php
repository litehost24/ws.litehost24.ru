<x-app-layout>
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-xl sm:rounded-lg p-4 md:p-6">
                <h1 class="text-2xl font-semibold text-gray-900 mb-4">Чаты поддержки</h1>

                <div class="support-chat-admin-grid" style="display:grid;grid-template-columns:340px minmax(0,1fr);gap:16px;">
                    <div class="border border-gray-200 rounded-lg overflow-hidden bg-white">
                        <div class="px-4 py-3 border-b border-gray-200 text-sm font-semibold text-gray-700">
                            Диалоги
                        </div>
                        <div id="admin-chat-list" class="max-h-[70vh] overflow-y-auto"></div>
                    </div>

                    <div class="border border-gray-200 rounded-lg bg-white flex flex-col min-h-[70vh]">
                        <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between gap-3">
                            <div id="admin-chat-header" class="text-sm text-gray-700">Выберите чат слева</div>
                            <button id="admin-chat-close-btn" type="button" class="h-9 px-3 rounded-md bg-gray-800 text-white text-sm font-semibold disabled:opacity-50" disabled>
                                Завершить чат
                            </button>
                        </div>

                        <div id="admin-chat-messages" class="flex-1 overflow-y-auto p-4 space-y-3 bg-slate-50"></div>

                        <form id="admin-chat-form" class="p-4 border-t border-gray-200 flex gap-2">
                            @csrf
                            <input
                                id="admin-chat-input"
                                type="text"
                                class="flex-1 h-11 rounded-md border border-gray-300 px-3"
                                placeholder="Введите ответ"
                                maxlength="2000"
                                disabled
                            >
                            <button
                                id="admin-chat-send-btn"
                                type="submit"
                                class="h-11 px-4 rounded-md bg-indigo-600 text-white font-semibold disabled:opacity-50"
                                disabled
                            >
                                Отправить
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

<style>
    .support-chat-admin-grid {
        align-items: stretch;
    }

    #admin-chat-messages {
        max-height: 70vh;
        overflow-y: auto;
        scrollbar-gutter: stable;
        background: #f8fafc;
    }

    #admin-chat-send-btn {
        background: var(--lh-brand-blue, #3088f0);
        border: 1px solid var(--lh-brand-blue-hover, #2a7be0);
    }

    .admin-chat-msg {
        max-width: 80%;
        border-radius: 14px;
        padding: 10px 12px;
    }

    .admin-chat-msg--admin {
        margin-left: auto;
        background: #e7f1ff;
        color: #0a3f8f;
        border: 1px solid #b6d4fe;
    }

    .admin-chat-msg--user {
        margin-right: auto;
        background: #ffffff;
        border: 1px solid #d1d5db;
        color: #111827;
    }

    @media (max-width: 900px) {
        .support-chat-admin-grid {
            grid-template-columns: 1fr !important;
            min-width: 0 !important;
        }

        #admin-chat-messages {
            max-height: 56vh;
        }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const selectedFromServer = @json($selectedChatId ?? 0);
        const listEl = document.getElementById('admin-chat-list');
        const headerEl = document.getElementById('admin-chat-header');
        const messagesEl = document.getElementById('admin-chat-messages');
        const formEl = document.getElementById('admin-chat-form');
        const inputEl = document.getElementById('admin-chat-input');
        const sendBtnEl = document.getElementById('admin-chat-send-btn');
        const closeBtnEl = document.getElementById('admin-chat-close-btn');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const collapsedStorageKey = 'support_admin_collapsed_chats';

        let chats = [];
        let activeChatId = selectedFromServer || 0;
        let activeChatStatus = '';
        let lastMessageId = 0;
        let pollListTimer = null;
        let pollMessagesTimer = null;
        let collapsedChatIds = new Set();

        try {
            const raw = JSON.parse(localStorage.getItem(collapsedStorageKey) || '[]');
            collapsedChatIds = new Set((Array.isArray(raw) ? raw : []).map(Number).filter(Boolean));
        } catch (e) {
            collapsedChatIds = new Set();
        }

        function persistCollapsed() {
            localStorage.setItem(collapsedStorageKey, JSON.stringify(Array.from(collapsedChatIds)));
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatTime(value) {
            if (!value) {
                return '';
            }
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) {
                return '';
            }
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        function applyChatStatusUi() {
            const isClosed = activeChatStatus === 'closed';
            const hasChat = !!activeChatId;

            inputEl.disabled = !hasChat || isClosed;
            sendBtnEl.disabled = !hasChat || isClosed;
            closeBtnEl.disabled = !hasChat || isClosed;

            if (hasChat && isClosed) {
                inputEl.placeholder = 'Чат завершён';
            } else {
                inputEl.placeholder = 'Введите ответ';
            }
        }

        function setInactiveView() {
            activeChatId = 0;
            activeChatStatus = '';
            lastMessageId = 0;
            messagesEl.innerHTML = '';
            headerEl.textContent = 'Выберите чат слева';
            applyChatStatusUi();
        }

        function renderChats() {
            if (!chats.length) {
                listEl.innerHTML = '<div class="p-4 text-sm text-gray-500">Чатов пока нет</div>';
                return;
            }

            listEl.innerHTML = chats.map(function (chat) {
                const id = Number(chat.id);
                const isActive = id === Number(activeChatId);
                const unread = Number(chat.unread_count || 0);
                const lastBody = chat.last_message?.body || 'Без сообщений';
                const name = chat.user?.name || ('Пользователь #' + chat.user?.id);
                const isClosed = chat.status === 'closed';
                const isCollapsed = collapsedChatIds.has(id);

                return `
                    <button type="button" data-chat-id="${id}" class="w-full text-left px-4 py-3 border-b border-gray-100 hover:bg-slate-50 ${isActive ? 'bg-indigo-50' : ''}">
                        <div class="flex items-center justify-between gap-2">
                            <div class="font-semibold text-sm text-gray-900 truncate">${escapeHtml(name)}</div>
                            <div class="flex items-center gap-1.5">
                                ${isCollapsed ? '<span class="text-[10px] bg-slate-200 text-slate-700 rounded-full px-2 py-0.5">свернут</span>' : ''}
                                ${isClosed ? '<span class="text-[10px] bg-gray-200 text-gray-700 rounded-full px-2 py-0.5">закрыт</span>' : ''}
                                ${unread > 0 ? `<span class="text-xs bg-red-500 text-white rounded-full px-2 py-0.5">${unread}</span>` : ''}
                            </div>
                        </div>
                        <div class="text-xs text-gray-500 truncate mt-1">${escapeHtml(lastBody)}</div>
                    </button>
                `;
            }).join('');

            listEl.querySelectorAll('button[data-chat-id]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const nextId = Number(btn.dataset.chatId || 0);
                    if (!nextId) {
                        return;
                    }

                    if (collapsedChatIds.has(nextId)) {
                        collapsedChatIds.delete(nextId);
                        persistCollapsed();

                        activeChatId = nextId;
                        lastMessageId = 0;
                        messagesEl.innerHTML = '';
                        renderChats();
                        loadMessages(true);
                        return;
                    }

                    collapsedChatIds.add(nextId);
                    persistCollapsed();

                    if (activeChatId === nextId) {
                        setInactiveView();
                    }

                    renderChats();
                });
            });
        }

        function renderMessages(messages) {
            if (!messages.length) {
                return;
            }

            const atBottom = messagesEl.scrollTop + messagesEl.clientHeight >= messagesEl.scrollHeight - 40;
            const fragment = document.createElement('div');

            messages.forEach(function (msg) {
                const mine = msg.sender_role === 'admin';
                const bubbleClass = mine
                    ? 'admin-chat-msg admin-chat-msg--admin'
                    : 'admin-chat-msg admin-chat-msg--user';

                const row = document.createElement('div');
                row.className = bubbleClass;
                row.innerHTML = `
                    <div class="text-xs opacity-80 mb-1">${escapeHtml(msg.sender_name || msg.sender_role)} · ${formatTime(msg.created_at)}</div>
                    <div class="text-sm leading-5 break-words">${escapeHtml(msg.body)}</div>
                `;
                fragment.appendChild(row);
                lastMessageId = Math.max(lastMessageId, Number(msg.id || 0));
            });

            messagesEl.appendChild(fragment);
            if (atBottom) {
                messagesEl.scrollTop = messagesEl.scrollHeight;
            }
        }

        async function fetchJson(url, options) {
            const response = await fetch(url, options || {});
            const payload = await response.json().catch(function () { return {}; });
            if (!response.ok) {
                throw new Error(payload.message || 'Ошибка запроса');
            }
            return payload;
        }

        async function loadChats() {
            try {
                const data = await fetchJson('{{ route('admin.support.chats.list', [], false) }}', {
                    headers: { 'Accept': 'application/json' },
                });
                chats = data.chats || [];

                if (!activeChatId && chats.length > 0) {
                    const firstExpanded = chats.find(function (chat) {
                        return !collapsedChatIds.has(Number(chat.id));
                    });
                    activeChatId = Number((firstExpanded || chats[0]).id);
                }

                const activeChat = chats.find(function (chat) {
                    return Number(chat.id) === Number(activeChatId);
                });

                if (!activeChat && chats.length > 0) {
                    const firstExpanded = chats.find(function (chat) {
                        return !collapsedChatIds.has(Number(chat.id));
                    });
                    activeChatId = Number((firstExpanded || chats[0]).id);
                }

                if (!activeChatId || collapsedChatIds.has(Number(activeChatId))) {
                    setInactiveView();
                } else {
                    const selected = chats.find(function (chat) {
                        return Number(chat.id) === Number(activeChatId);
                    });

                    activeChatStatus = selected?.status || 'open';
                    const title = selected?.user?.name || selected?.user?.email || ('Чат #' + activeChatId);
                    headerEl.textContent = 'Чат: ' + title + (activeChatStatus === 'closed' ? ' (завершён)' : '');
                    applyChatStatusUi();
                }

                renderChats();
            } catch (error) {
                console.error(error);
            }
        }

        async function loadMessages(resetUnread) {
            if (!activeChatId || collapsedChatIds.has(Number(activeChatId))) {
                return;
            }

            try {
                const data = await fetchJson('/admin/support/chats/' + encodeURIComponent(activeChatId) + '/messages?after_id=' + encodeURIComponent(lastMessageId), {
                    headers: { 'Accept': 'application/json' },
                });

                activeChatStatus = data.chat_status || activeChatStatus || 'open';
                const incoming = data.messages || [];
                renderMessages(incoming);

                if (resetUnread) {
                    await fetchJson('/admin/support/chats/' + encodeURIComponent(activeChatId) + '/read', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                    });
                    await loadChats();
                } else {
                    applyChatStatusUi();
                }
            } catch (error) {
                console.error(error);
            }
        }

        closeBtnEl.addEventListener('click', async function () {
            if (!activeChatId || activeChatStatus === 'closed') {
                return;
            }

            if (!window.confirm('Завершить этот чат?')) {
                return;
            }

            closeBtnEl.disabled = true;

            try {
                await fetchJson('/admin/support/chats/' + encodeURIComponent(activeChatId) + '/close', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                });

                const closedChatId = Number(activeChatId);
                activeChatStatus = 'closed';
                collapsedChatIds.add(closedChatId);
                persistCollapsed();
                setInactiveView();
                await loadChats();
            } catch (error) {
                alert(error.message || 'Не удалось завершить чат');
            }
        });

        formEl.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (!activeChatId || activeChatStatus === 'closed' || collapsedChatIds.has(Number(activeChatId))) {
                return;
            }

            const text = inputEl.value.trim();
            if (!text) {
                return;
            }

            inputEl.disabled = true;
            sendBtnEl.disabled = true;

            try {
                await fetchJson('/admin/support/chats/' + encodeURIComponent(activeChatId) + '/messages', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ body: text }),
                });

                inputEl.value = '';
                await loadMessages(true);
            } catch (error) {
                alert(error.message || 'Не удалось отправить сообщение');
            } finally {
                applyChatStatusUi();
                inputEl.focus();
            }
        });

        async function initialLoad() {
            await loadChats();
            await loadMessages(true);

            pollListTimer = window.setInterval(loadChats, 3000);
            pollMessagesTimer = window.setInterval(function () {
                loadMessages(true);
            }, 2500);
        }

        initialLoad();

        window.addEventListener('beforeunload', function () {
            if (pollListTimer) {
                clearInterval(pollListTimer);
            }
            if (pollMessagesTimer) {
                clearInterval(pollMessagesTimer);
            }
        });
    });
</script>
