let socket;
let logoutRequested = false;
let logoutTimeout;
let authenticatedUserId = null;
let lastScorePlayers = [];
let scoreSocialState = { friends: [], blocked: [], incoming: [], outgoing: [] };

window.onload = function() {
    connectWebSocket(); // Connexion WebSocket
};

// Connexion WebSocket
function connectWebSocket() {
    const configuredUrl = document.querySelector('meta[name="ws-url"]')?.content?.trim();
    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    let wsUrl;

    if (!configuredUrl || configuredUrl.startsWith('/')) {
        wsUrl = `${protocol}//${window.location.host}${configuredUrl || '/ws'}`;
    } else {
        const parsedUrl = new URL(configuredUrl, window.location.href);
        if (window.location.protocol === 'https:' && parsedUrl.protocol === 'ws:') {
            parsedUrl.protocol = 'wss:';
        }
        wsUrl = parsedUrl.toString();
    }

    socket = new WebSocket(wsUrl);

    socket.onopen = function() {
        console.log('WebSocket ouvert');
        if (logoutRequested) {
            const token = sessionStorage.getItem('minesweeperSessionToken');
            if (token) socket.send(JSON.stringify({ type: 'logout', sessionToken: token }));
            else finishLogout();
        } else {
            const token = sessionStorage.getItem('minesweeperSessionToken');
            if (token) socket.send(JSON.stringify({ type: 'resume_session', sessionToken: token }));
            fetchPlayerScores();
        }
    };

    socket.onmessage = function(event) {
        const data = JSON.parse(event.data);
        console.log('Message reçu du serveur: ' + JSON.stringify(data));

        // Traiter le message envoyé par le serveur
        switch (data.type) {
            case 'resume_failed':
                if (!logoutRequested) sessionStorage.removeItem('minesweeperSessionToken');
                if (!logoutRequested) document.getElementById('socialPanelToggle')?.classList.add('hidden');
                break;
            case 'login_success':
                authenticatedUserId = Number(data.playerId);
                document.getElementById('navbarUserDisplay').textContent = data.username;
                requestSocialState();
                fetchPlayerScores();
                break;
            case 'logout_success':
                if (logoutRequested) finishLogout();
                break;

            case 'scores':
                refreshScores(data.players); // Rafraîchir l'affichage des scores
                break;

            case 'social_state':
                renderSocialState(data);
                break;
            case 'social_action_success':
                showSocialNotice(data.message || 'Action effectuée.');
                requestSocialState();
                break;

            case 'error':
                console.error('Erreur: ' + data.message);
                showSocialNotice(data.message || 'Action impossible.', true);
                break;

            // Ajoute d'autres types de messages si nécessaire...
        }
    };

    socket.onclose = function() {
        console.log('WebSocket fermé');
        showScoresMessage('Connexion au serveur de scores impossible.');
    };

    socket.onerror = function() {
        showScoresMessage('Erreur de connexion au serveur de scores.');
    };
}

function finishLogout() {
    clearTimeout(logoutTimeout);
    sessionStorage.removeItem('minesweeperSessionToken');
    window.location.replace('/');
}

document.getElementById('logoutLink')?.addEventListener('click', (event) => {
    event.preventDefault();
    if (logoutRequested) return;
    logoutRequested = true;
    logoutTimeout = setTimeout(finishLogout, 3000);
    if (socket?.readyState === WebSocket.OPEN) {
        const token = sessionStorage.getItem('minesweeperSessionToken');
        if (token) socket.send(JSON.stringify({ type: 'logout', sessionToken: token }));
        else finishLogout();
    } else if (!socket || socket.readyState === WebSocket.CLOSED) {
        connectWebSocket();
    }
});

// Fonction pour récupérer les scores des joueurs
function fetchPlayerScores() {
    const period = document.getElementById('rankingPeriod')?.value || 'all';
    socket.send(JSON.stringify({ type: 'get_scores', period }));
}

document.getElementById('rankingPeriod')?.addEventListener('change', () => {
    if (socket?.readyState === WebSocket.OPEN) fetchPlayerScores();
});

window.addEventListener('pageshow', () => {
    if (socket?.readyState === WebSocket.OPEN) fetchPlayerScores();
});

document.addEventListener('visibilitychange', () => {
    if (!document.hidden && socket?.readyState === WebSocket.OPEN) fetchPlayerScores();
});

// Fonction pour afficher les scores des joueurs
function refreshScores(players) {
    lastScorePlayers = Array.isArray(players) ? players : [];
    const scoresTable = document.getElementById('scoresTable');
    scoresTable.innerHTML = ''; // Vider le tableau avant de l'actualiser

    if (!Array.isArray(players) || players.length === 0) {
        showScoresMessage('Aucun score disponible.');
        return;
    }

    players.forEach(player => {
        const row = document.createElement('tr');
        const gamesPlayed = player.games_played || 0; // S'assurer que le nombre de parties jouées est défini
        let winPercentage = player.win_percentage || 0; // S'assurer que win_percentage est défini

        // Convertir winPercentage en float si c'est une chaîne de caractères
        if (typeof winPercentage === 'string') {
            winPercentage = parseFloat(winPercentage);
        }

        // Utiliser toFixed pour afficher deux décimales
        const usernameCell = row.insertCell();
        usernameCell.textContent = `${player.username}${Number(player.is_ai) === 1 ? ' 🤖' : ''}`;
        row.insertCell().textContent = gamesPlayed;
        row.insertCell().textContent = player.games_won || 0;
        row.insertCell().textContent = player.games_lost || 0;
        row.insertCell().textContent = player.games_draw || 0;
        row.insertCell().textContent = player.elo_rating || 1200;
        const percentageCell = row.insertCell();
        const percentage = Math.max(0, Math.min(100, winPercentage));
        const progress = document.createElement('progress');
        progress.className = 'score-progress';
        progress.max = 100;
        progress.value = percentage;
        progress.setAttribute('aria-label', `${percentage.toFixed(2)} % de victoires`);
        percentageCell.appendChild(progress);
        percentageCell.append(document.createTextNode(` ${percentage.toFixed(2)}%`));
        const actionCell = row.insertCell();
        if (authenticatedUserId && Number(player.id) !== authenticatedUserId) {
            const isFriend = scoreSocialState.friends.some(person => Number(person.id) === Number(player.id));
            const isBlocked = scoreSocialState.blocked.some(person => Number(person.id) === Number(player.id));
            if (isFriend) usernameCell.prepend(document.createTextNode('★ '));
            const primary = document.createElement('button'); primary.type = 'button'; primary.className = 'btn btn-sm btn-outline-primary mr-1';
            if (isBlocked) { primary.textContent = '🔓'; primary.title = primary.ariaLabel = 'Débloquer'; primary.onclick = () => socialAction('unblock_user', player.id); }
            else if (isFriend) { primary.textContent = '❌'; primary.title = primary.ariaLabel = 'Retirer des amis'; primary.onclick = () => socialAction('remove_friend', player.id, `Supprimer ${player.username} de vos amis ?`); }
            else { primary.textContent = '🤝'; primary.title = primary.ariaLabel = 'Ajouter en ami'; primary.onclick = () => sendFriendRequest(player); }
            actionCell.appendChild(primary);
            if (!isBlocked) { const block = document.createElement('button'); block.type = 'button'; block.className = 'btn btn-sm btn-outline-danger'; block.textContent = '🚫'; block.title = 'Bloquer'; block.setAttribute('aria-label', 'Bloquer'); block.onclick = () => socialAction('block_user', player.id, `Bloquer ${player.username} ?`); actionCell.appendChild(block); }
        }
        scoresTable.appendChild(row);
    });
}

function showScoresMessage(message) {
    const scoresTable = document.getElementById('scoresTable');
    scoresTable.innerHTML = '';
    const row = document.createElement('tr');
    const cell = row.insertCell();
    cell.colSpan = 8;
    cell.className = 'text-center text-muted';
    cell.textContent = message;
    scoresTable.appendChild(row);
}

function sendMessage(payload) { if (socket?.readyState === WebSocket.OPEN) socket.send(JSON.stringify(payload)); }
function requestSocialState() { if (authenticatedUserId) sendMessage({ type: 'get_social_state' }); }
function socialAction(type, userId, confirmation = '') { if (!confirmation || confirm(confirmation)) sendMessage({ type, userId: Number(userId) }); }
function sendFriendRequest(player) { const message = prompt(`Message pour ${player.username} (facultatif)`, ''); if (message !== null) sendMessage({ type: 'send_friend_request', userId: Number(player.id), message: message.slice(0, 300) }); }
function showSocialNotice(message, error = false) { const el = document.getElementById('socialNotice'); if (!el) return; el.className = `small ${error ? 'text-danger' : 'text-success'}`; el.textContent = message; }
function socialRow(person, buttons, detail = '') {
    const row = document.createElement('div'); row.className = 'social-entry';
    const main = document.createElement('div'); main.className = 'social-entry-main';
    const strong = document.createElement('strong'); strong.textContent = `${person.username}${Number(person.is_ai) ? ' 🤖' : ''}`; main.appendChild(strong);
    if (detail) { const small = document.createElement('span'); small.className = 'social-message'; small.textContent = detail; main.appendChild(small); }
    const actions = document.createElement('div'); actions.className = 'social-entry-actions';
    buttons.forEach(([label, type, css, confirmText]) => { const button = document.createElement('button'); button.type = 'button'; button.className = `btn btn-sm ${css}`; button.textContent = label; const descriptions = {send_friend_request:'Ajouter en ami',accept_friend_request:'Accepter',decline_friend_request:'Refuser',remove_friend:'Retirer des amis',block_user:'Bloquer',unblock_user:'Débloquer'}; if (descriptions[type]) { button.title = descriptions[type]; button.setAttribute('aria-label', descriptions[type]); } button.onclick = () => socialAction(type, person.id, confirmText || ''); actions.appendChild(button); });
    row.append(main, actions); return row;
}
function fillSocial(id, rows, empty) { const el = document.getElementById(id); el.textContent = ''; if (!rows.length) { const p = document.createElement('p'); p.className = 'small text-muted'; p.textContent = empty; el.appendChild(p); } else rows.forEach(row => el.appendChild(row)); }
function renderSocialState(state) {
    scoreSocialState = state;
    const friends = state.friends || [];
    const friendRow = p => socialRow(p, [['❌','remove_friend','btn-outline-secondary',`Supprimer ${p.username} de vos amis ?`],['🚫','block_user','btn-outline-danger',`Bloquer ${p.username} ?`]], p.online ? 'En ligne' : `Hors ligne · ${p.last_active || 'activité inconnue'}`);
    fillSocial('onlineFriends', friends.filter(p => p.online).map(friendRow), 'Aucun ami connecté.'); fillSocial('offlineFriends', friends.filter(p => !p.online).map(friendRow), 'Aucun ami hors ligne.');
    fillSocial('incomingFriendRequests', (state.incoming || []).map(p => socialRow(p, [['👍','accept_friend_request','btn-success'],['👎','decline_friend_request','btn-outline-secondary'],['🚫','block_user','btn-outline-danger']], p.message || 'Demande d’amitié')), 'Aucune demande reçue.');
    fillSocial('outgoingFriendRequests', (state.outgoing || []).map(p => socialRow(p, [], p.message || 'En attente')), 'Aucune demande envoyée.');
    fillSocial('blockedUsers', (state.blocked || []).map(p => socialRow(p, [['🔓','unblock_user','btn-outline-primary']], 'Bloqué')), 'Aucun joueur bloqué.');
    const notifications = state.notifications || []; fillSocial('socialNotifications', notifications.map(n => socialRow({username:n.actor || 'Compte supprimé'}, [], n.type === 'friend_request' ? 'Nouvelle demande' : n.type === 'friend_accepted' ? 'Demande acceptée' : 'A supprimé votre amitié')), 'Aucune notification.');
    const unread = notifications.filter(n => !n.read_at).length + (state.incoming || []).length; const badge = document.getElementById('socialBadge'); badge.textContent = unread; badge.classList.toggle('hidden', !unread); document.getElementById('friendRequestsEnabled').checked = Boolean(state.friendRequestsEnabled);
    if (lastScorePlayers.length) refreshScores(lastScorePlayers);
}
function setSocialPanel(open) { const panel = document.getElementById('socialPanel'); const wasOpen = panel.classList.contains('open'); panel.classList.toggle('open', open); panel.setAttribute('aria-hidden', String(!open)); document.getElementById('socialPanelBackdrop').hidden = !open; if (open) requestSocialState(); else if (wasOpen) sendMessage({type:'mark_social_notifications_read'}); }
document.getElementById('socialPanelToggle')?.addEventListener('click', () => setSocialPanel(true)); document.getElementById('socialPanelClose')?.addEventListener('click', () => setSocialPanel(false)); document.getElementById('socialPanelBackdrop')?.addEventListener('click', () => setSocialPanel(false)); document.getElementById('friendRequestsEnabled')?.addEventListener('change', e => sendMessage({type:'set_social_preferences',friendRequestsEnabled:e.target.checked}));
