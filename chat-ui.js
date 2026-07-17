(() => {
    let conversations = [], active = null, messages = [], typingTimer = null, drag = null;
    const $ = id => document.getElementById(id);
    const send = payload => { if (typeof socket !== 'undefined' && socket?.readyState === WebSocket.OPEN) socket.send(JSON.stringify(payload)); };
    const ownId = () => Number(typeof currentPlayerId !== 'undefined' ? currentPlayerId : (typeof authenticatedUserId !== 'undefined' ? authenticatedUserId : 0));
    const visibleConversations = () => {
        const gameId = typeof currentGameId !== 'undefined' ? currentGameId : null;
        return gameId ? conversations.filter(c => c.kind === 'game' && c.game_id === gameId) : conversations;
    };
    function positionChat() {
        if (innerWidth <= 768) { $('chatWindow').style.removeProperty('top'); $('chatWindow').style.removeProperty('height'); return; }
        const top=0;
        document.documentElement.style.setProperty('--app-header-bottom',`${top}px`);
        $('chatWindow').style.top=`${top}px`;
        $('chatWindow').style.height=`calc(100dvh - ${top}px)`;
    }
    const escapeLink = text => {
        const fragment=document.createDocumentFragment(); const parts=String(text).split(/(https:\/\/[^\s]+)/g);
        parts.forEach(part=>{if(/^https:\/\//.test(part)){const a=document.createElement('a');a.href=part;a.target='_blank';a.rel='noopener noreferrer';a.textContent=part;a.onclick=async e=>{e.preventDefault();if(await window.appConfirm(t('chat.externalMessage',{},'Ouvrir ce lien dans un nouvel onglet ?'),t('chat.externalTitle',{},'Lien externe'),t('chat.open',{},'Ouvrir')))window.open(part,'_blank','noopener,noreferrer');};fragment.appendChild(a);}else fragment.appendChild(document.createTextNode(part));});return fragment;
    };
    function renderConversations() {
        const root=$('chatConversations'); root.textContent=''; let unread=0;
        $('chatWindow').classList.toggle('single-conversation', Boolean(typeof currentGameId !== 'undefined' && currentGameId));
        const visible=visibleConversations();
        visible.forEach(c=>{unread+=Number(c.unread||0);const b=document.createElement('button');b.type='button';b.className='chat-conversation'+(Number(c.id)===active?' active':'');b.textContent=`${c.kind==='game'?'🎮':'👤'} ${c.title}${Number(c.unread)?` (${c.unread})`:''}`;b.onclick=()=>openConversation(Number(c.id),c.title);root.appendChild(b);});
        if(!visible.length){const empty=document.createElement('p');empty.className='small text-muted';empty.textContent=typeof currentGameId!=='undefined'&&currentGameId?t('chat.gameUnavailable',{},'Chat de la partie indisponible.'):t('chat.none',{},'Aucune conversation.');root.appendChild(empty);}
        $('chatBadge').textContent=unread;$('chatBadge').classList.toggle('hidden',!unread);
    }
    function renderMessages() {
        const root=$('chatMessages');root.textContent='';
        messages.forEach(m=>{const item=document.createElement('article');item.className='chat-message '+(Number(m.sender_id)===ownId()?'mine':'theirs');item.dataset.messageId=m.id;const meta=document.createElement('small');meta.textContent=`${m.username||t('common.system',{},'Système')} · ${new Date(String(m.created_at).replace(' ','T')).toLocaleTimeString(i18n.language,{hour:'2-digit',minute:'2-digit'})}`;const body=document.createElement('div');body.className='chat-message-body';body.appendChild(m.deleted_at?document.createTextNode(t('chat.deleted',{},'Message supprimé')):escapeLink(m.body||''));item.append(meta,body);
            if(m.reactions){const counts={};String(m.reactions).split(',').forEach(value=>{const reaction=value.split(':')[0];counts[reaction]=(counts[reaction]||0)+1;});const summary=document.createElement('span');summary.className='chat-reactions';summary.textContent=Object.entries(counts).map(([r,n])=>`${r} ${n}`).join('  ');item.appendChild(summary);}if(!m.deleted_at){const actions=document.createElement('div');actions.className='chat-message-actions';['👍','👎','😂','😮','🎉'].forEach(r=>{const b=document.createElement('button');b.type='button';b.textContent=r;b.onclick=()=>send({type:'react_chat_message',messageId:Number(m.id),reaction:r});actions.appendChild(b);});if(Number(m.sender_id)===ownId()){const del=document.createElement('button');del.type='button';del.textContent='🗑️';del.onclick=()=>send({type:'delete_chat_message',messageId:Number(m.id)});actions.appendChild(del);}item.appendChild(actions);}root.appendChild(item);});
        root.scrollTop=root.scrollHeight;const last=messages.at(-1);if(last&&active)send({type:'mark_chat_read',conversationId:active,messageId:Number(last.id)});
    }
    function openConversation(id,title='Conversation'){active=id;$('chatTitle').textContent=title;renderConversations();send({type:'get_chat_messages',conversationId:id});setOpen(true);}
    function setOpen(open){if(typeof currentGameId!=='undefined'&&currentGameId)open=true;positionChat();$('chatWindow').classList.remove('minimized');$('chatWindow').classList.add('docked');$('chatWindow').classList.toggle('open',open);$('chatWindow').setAttribute('aria-hidden',String(!open));document.body.classList.toggle('chat-open',open);}
    window.openDirectChat=userId=>{send({type:'open_direct_chat',userId:Number(userId)});setOpen(true);};
    window.requestChatState=()=>send({type:'get_chat_state'});
    window.handleChatEvent=data=>{
        if(data.type==='chat_state'){conversations=data.conversations||[];const p=data.preferences||{};if(typeof window.applySoundPreference==='function'&&p.sound_enabled!==undefined)window.applySoundPreference(Boolean(Number(p.sound_enabled)));$('chatWindow').classList.add('docked');const visible=visibleConversations();if(typeof currentGameId!=='undefined'&&currentGameId&&visible.length&&(!active||!visible.some(c=>Number(c.id)===active)))openConversation(Number(visible[0].id),visible[0].title);else renderConversations();}
        if(data.type==='chat_opened')openConversation(Number(data.conversationId));
        if(data.type==='chat_messages'&&Number(data.conversationId)===active){messages=data.messages||[];renderMessages();}
        if(data.type==='chat_message'){if(Number(data.message.conversation_id)===active){messages.push(data.message);renderMessages();}else requestChatState();}
        if(data.type==='chat_message_deleted'){const m=messages.find(x=>Number(x.id)===Number(data.messageId));if(m){m.deleted_at=true;m.body=null;renderMessages();}}
        if(data.type==='chat_reaction'&&Number(data.conversationId)===active)send({type:'get_chat_messages',conversationId:active});
        if(data.type==='chat_typing'&&Number(data.conversationId)===active){$('chatTyping').textContent=data.typing?t('chat.typing',{username:data.username},`${data.username} écrit…`):'';}
    };
    $('chatToggle')?.addEventListener('click',()=>{setOpen(!$('chatWindow').classList.contains('open'));requestChatState();});$('chatClose')?.addEventListener('click',()=>setOpen(false));$('chatMinimize')?.addEventListener('click',()=>{if(typeof currentGameId!=='undefined'&&currentGameId)return;const minimized=$('chatWindow').classList.toggle('minimized');document.body.classList.toggle('chat-open',!minimized);});
    $('chatMute')?.addEventListener('click',()=>{if(!active)return;const conversation=conversations.find(c=>Number(c.id)===active);const muted=!Number(conversation?.muted);send({type:'set_chat_muted',conversationId:active,muted});$('chatMute').textContent=muted?'🔕':'🔔';});
    $('chatHide')?.addEventListener('click',async()=>{if(active&&await window.appConfirm(t('chat.hideMessage',{},'Supprimer cette conversation de votre liste ?'),t('chat.hideTitle',{},'Masquer la conversation'),t('chat.hideConfirm',{},'Supprimer'),true)){send({type:'hide_chat_conversation',conversationId:active});active=null;messages=[];renderMessages();$('chatTitle').textContent=t('chat.choose',{},'Choisissez une conversation');}});

    window.addEventListener('languagechange',()=>{renderConversations();renderMessages();});
    $('chatForm')?.addEventListener('submit',e=>{e.preventDefault();const input=$('chatInput');if(!active||!input.value.trim())return;send({type:'send_chat_message',conversationId:active,message:input.value});input.value='';send({type:'chat_typing',conversationId:active,typing:false});});
    $('chatInput')?.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();$('chatForm').requestSubmit();}});$('chatInput')?.addEventListener('input',()=>{if(!active)return;send({type:'chat_typing',conversationId:active,typing:true});clearTimeout(typingTimer);typingTimer=setTimeout(()=>send({type:'chat_typing',conversationId:active,typing:false}),1200);});
    addEventListener('resize',positionChat);
})();
