let socket;
let logoutRequested = false;
let logoutTimeout;

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
            fetchPlayerScores(); // Récupérer les scores des joueurs une fois connecté
        }
    };

    socket.onmessage = function(event) {
        const data = JSON.parse(event.data);
        console.log('Message reçu du serveur: ' + JSON.stringify(data));

        // Traiter le message envoyé par le serveur
        switch (data.type) {
            case 'resume_failed':
            case 'logout_success':
                if (logoutRequested) finishLogout();
                break;

            case 'scores':
                refreshScores(data.players); // Rafraîchir l'affichage des scores
                break;

            case 'error':
                console.error('Erreur: ' + data.message);
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

// Fonction pour afficher les scores des joueurs
function refreshScores(players) {
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
        row.insertCell().textContent = player.ranking_points || 0;
        const percentageCell = row.insertCell();
        const percentage = Math.max(0, Math.min(100, winPercentage));
        const progress = document.createElement('progress');
        progress.className = 'score-progress';
        progress.max = 100;
        progress.value = percentage;
        progress.setAttribute('aria-label', `${percentage.toFixed(2)} % de victoires`);
        percentageCell.appendChild(progress);
        percentageCell.append(document.createTextNode(` ${percentage.toFixed(2)}%`));
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
