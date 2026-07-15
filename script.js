// script.js

let socket;

function setElementDisplay(element, display) {
    if (!element) return;
    element.classList.remove('display-none', 'display-block', 'display-flex');
    element.classList.add(`display-${display}`);
}

function setGameActive(active) {
    document.body.classList.toggle('game-active', Boolean(active));
}
let username;
let currentGameId;
let refreshInterval;
let connected = false;
let currentPlayerId;
let currentInvitationId = null;
let isMyTurn = false;
let errorDiv = null;
let retryInterval = 5000;
let keepAliveInterval; 
let reconnectTimeout;
let isReconnecting = false;
let logoutInProgress = false;
let logoutFallbackTimeout;
let touchActionMode = 'reveal';
const mobileGameQuery = window.matchMedia('(max-width: 768px), (pointer: coarse)');

let isMuted = false;


// gestion des sons
function createSound(source) {
    const audio = new Audio();
    audio.preload = 'none';
    audio.src = source;
    return audio;
}

const soundClick = createSound('sounds/click.mp3');
const soundMine = createSound('sounds/mine.mp3');
const soundFlag = createSound('sounds/flag.mp3');
const soundWin = createSound('sounds/win.mp3');
const soundLose = createSound('sounds/lose.mp3');
const soundTie = createSound('sounds/tie.mp3');
const muteButton = document.getElementById('muteButton');

const loginModal = document.getElementById('loginModal');
const registerModal = document.getElementById('registerModal');
const showRegisterModalLink = document.getElementById('showRegisterModal');
const showLoginModalLink = document.getElementById('showLoginModal');

const loginBtn = document.getElementById('loginBtn');
const registerBtn = document.getElementById('registerBtn');

const loginError = document.getElementById('loginError');
const registerError = document.getElementById('registerError');

// Fonction pour afficher le modal de connexion
function showLoginModal() {
    registerModal.classList.add('hidden');
    loginModal.classList.remove('hidden');
}

// Fonction pour afficher le modal de création de compte
function showRegisterModal() {
    loginModal.classList.add('hidden');
    registerModal.classList.remove('hidden');
}

// La connexion WebSocket décide d'afficher ce formulaire uniquement lorsqu'il
// n'existe aucune session à reprendre.

// Écouteurs pour les liens
showRegisterModalLink.addEventListener('click', (e) => {
    e.preventDefault();
    showRegisterModal();
});

showLoginModalLink.addEventListener('click', (e) => {
    e.preventDefault();
    showLoginModal();
});

muteButton.addEventListener('click', () => {
    isMuted = !isMuted;
    const newIcon = isMuted ? '🔇' : '🔊';
    muteButton.textContent = newIcon;

    // Mute ou unmute tous les sons
    [soundClick, soundMine, soundFlag, soundWin, soundLose,soundTie].forEach(sound => {
        sound.muted = isMuted;
    });
});

// gestion de l'aide en jeu
// Fonction pour afficher l'overlay d'aide
function showHelpOverlay() {
    const overlay = document.getElementById('helpOverlay');
    setElementDisplay(overlay, 'flex');
    // Force un reflow pour que la transition fonctionne
    overlay.offsetHeight; // Déclenche un reflow
    overlay.classList.add('show'); // Ajoute la classe pour démarrer la transition
}

// Fonction pour cacher l'overlay d'aide
function hideHelpOverlay() {
    const overlay = document.getElementById('helpOverlay');
    overlay.classList.remove('show'); // Retire la classe pour démarrer la transition de disparition
    // Une fois la transition terminée, cacher l'overlay
    overlay.addEventListener('transitionend', function handler() {
        setElementDisplay(overlay, 'none');
        overlay.removeEventListener('transitionend', handler); // Retire l'écouteur pour éviter les appels multiples
    });
}

// Vérifier la préférence de l'utilisateur
function checkHelpPreference() {
    const dontShowHelp = localStorage.getItem('dontShowHelpAgain');
    if (dontShowHelp !== 'true') {
        showHelpOverlay();
    }
}

// Écouteur pour le bouton 'Fermer' de l'aide
document.getElementById('closeHelpBtn').addEventListener('click', () => {
    const dontShowAgain = document.getElementById('dontShowHelpAgain').checked;
    if (dontShowAgain) {
        localStorage.setItem('dontShowHelpAgain', 'true');
    }
    hideHelpOverlay();
});

// Écouteur pour l'icône du point d'interrogation
document.getElementById('helpIcon').addEventListener('click', () => {
    showHelpOverlay();
});
function hideHelpIcon() {
    const helpIcon = document.getElementById('helpIcon');
    helpIcon.classList.add('hidden');
}
function showHelpIcon() {
    const helpIcon = document.getElementById('helpIcon');
    helpIcon.classList.remove('hidden');
}

// Fonction pour afficher les messages WebSocket dans la div messages
function logMessage(message) {
    if (window.DEBUG_MINESWEEPER) console.debug(message);
}

// Connexion WebSocket
function connectWebSocket() {

    // Récupérer le nom d'hôte (domaine ou IP)
    const hostname = window.location.hostname;

    // Utiliser le protocole réellement visible par le navigateur. Le reverse
    // proxy peut parler HTTP à Apache même si la page publique est en HTTPS.
    const wsProtocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';

    // Si l'hôte est un domaine (fozzy.fr par exemple), utiliser wss (WebSocket sécurisé) et le port 9443
    console.log("Tentative de connexion au server : " + hostname)
    
    // Construire l'URL du WebSocket
    const configuredUrl = document.querySelector('meta[name="ws-url"]')?.content?.trim();
    let wsUrl;
    if (!configuredUrl || configuredUrl.startsWith('/')) {
        wsUrl = `${wsProtocol}//${window.location.host}${configuredUrl || '/ws'}`;
    } else {
        const parsedUrl = new URL(configuredUrl, window.location.href);
        // Empêcher le mixed content même si WS_PUBLIC_URL contient encore ws://.
        if (window.location.protocol === 'https:' && parsedUrl.protocol === 'ws:') {
            parsedUrl.protocol = 'wss:';
        }
        wsUrl = parsedUrl.toString();
    }
    console.log("URL du WebSocket construite : " + wsUrl);

    try {
        socket = new WebSocket(wsUrl);
        console.log("Objet WebSocket créé avec l'URL : " + wsUrl);
    } catch (error) {
        console.error("Erreur lors de la création du WebSocket : ", error);
        showConnectionError();
        attemptReconnect();
        return;
    }

    socket.onopen = function() {
        setConnectionStatus('Connecté', true);
        console.log("WebSocket connecté : " + wsUrl);
        connected = true;
        logoutInProgress = false;
        isReconnecting = false;
        clearTimeout(reconnectTimeout);
        clearInterval(keepAliveInterval);
        hideConnectionError(); // Afficher le formulaire de connexion
        const sessionToken = sessionStorage.getItem('minesweeperSessionToken');
        if (sessionToken) {
            socket.send(JSON.stringify({ type: 'resume_session', sessionToken }));
        } else {
            showLoginModal();
        }

        keepAliveInterval = setInterval(function() {
            if (socket.readyState === WebSocket.OPEN) {
                socket.send(JSON.stringify({ type: 'ping' }));
                console.log('Ping envoyé au serveur');
            }
        }, 15000);
    };

    socket.onmessage = function(event) {
        const data = JSON.parse(event.data);
        logMessage('Message reçu du serveur: ' + JSON.stringify(data));

        switch (data.type) {
            case 'pong':
                console.log("Pong reçu du serveur");
                break;

            case 'login_failed':
                loginError.textContent = data.message || 'Nom d\'utilisateur ou mot de passe incorrect.';
                document.getElementById('loginPassword').value = '';
                break;

            case 'login_success':
                setGameActive(false);
                currentPlayerId = data.playerId; 
                username = data.username;
                if (data.sessionToken) {
                    sessionStorage.setItem('minesweeperSessionToken', data.sessionToken);
                }
                // Masquer la section de connexion et afficher la liste des joueurs
                hideModal(loginModal); 
                setElementDisplay(document.getElementById('game'), 'block');
                document.getElementById('navbarUserDisplay').textContent = data.username;
                setElementDisplay(document.getElementById('navbar'), 'block');
                setElementDisplay(document.getElementById('welcomeMessage'), 'block');
                setElementDisplay(document.getElementById('logoutLink'), 'block');
                setElementDisplay(document.getElementById('availableUser'), 'block');
                refreshPlayersList(data.players);
                break;

            case 'resume_failed':
                sessionStorage.removeItem('minesweeperSessionToken');
                showLoginModal();
                break;

            case 'register_success':
                registerError.textContent = '';
                document.getElementById('creationOk').textContent = data.message || 'Compte créé. Consultez votre e-mail pour le valider.';
                showLoginModal();
                break;

            case 'register_failed':
                registerError.textContent = 'Erreur lors de la création du compte : ' + data.message;
                break;

            case 'connected_players':
                // Rafraîchir la liste des joueurs connectés
                refreshPlayersList(data.players);
                if (!currentGameId && username) {
                    setElementDisplay(document.getElementById('game'), 'block');
                    setElementDisplay(document.getElementById('availableUser'), 'block');
                }
                break;

            case 'game_start':

                // Stocker le game_id pour les futures actions
                setElementDisplay(document.getElementById('availableUser'), 'none');
                setElementDisplay(document.getElementById('gameContainer'), 'flex');
                currentGameId = data.game_id;
                setGameActive(true);
                displayGameBoard(data.board);
                updateGameStatus(data.board, data.mineCount, data.currentPlayer);

                // Afficher le joueur qui commence
                currentPlayerDisplay = document.getElementById('currentTurnDisplay');
                currentPlayerDisplay.textContent = 'C\'est à ' + data.currentPlayer + ' de commencer.'; // Afficher qui commence


                logMessage('Tour actuel: ' + data.turn);

                showHelpIcon();
                checkHelpPreference();

                break;

            case 'game_resumed':
                currentGameId = data.game_id;
                setGameActive(true);
                setElementDisplay(document.getElementById('availableUser'), 'none');
                setElementDisplay(document.getElementById('gameContainer'), 'flex');
                displayGameBoard(data.board);
                updateGameStatus(data.board, data.mineCount, data.currentPlayer);
                currentPlayerDisplay = document.getElementById('currentTurnDisplay');
                currentPlayerDisplay.textContent = 'Partie reprise — tour actuel : ' + data.currentPlayer;
                showHelpIcon();
                break;

            case 'update_board':
                updateGameBoard(data.board);
                updateGameStatus(data.board, data.mineCount, data.currentPlayer);
                // Mettre à jour le nom du joueur dont c'est le tour
                currentPlayerDisplay = document.getElementById('currentTurnDisplay');
                currentPlayerDisplay.textContent = 'Tour actuel: ' + data.currentPlayer; // Affiche le joueur actuel
                break;

            case 'invite':
                document.getElementById('inviter').textContent = data.inviter;
                setElementDisplay(document.getElementById('invitation'), 'block');
                currentInvitationId = data.invitationId;
                break;

            case 'invite_declined':
                logMessage('Invitation refusée par le joueur.');
                break;

            case 'game_over':

                if (data.winner.includes('Vous avez gagné')) {
                    soundWin.play();
                } else if (data.winner.includes('La partie se termine par une égalité!')) {
                    soundTie.play();
                } else {
                    soundLose.play();
                }
                // Fin de partie et affichage du gagnant
                displayGameBoard(data.board, data.losingCell);
                showWinnerModal(data.winner, data.game_id);
            
                hideHelpIcon();
                break;
                // Ajout de la gestion de la déconnexion d'un joueur
            case 'player_disconnected':
                logMessage('Votre adversaire s\'est déconnecté. La partie est annulée.');
                showWinnerModal('Votre adversaire s\'est déconnecté. La partie est annulée.', currentGameId);
                break;
            case 'player_reconnecting':
                currentPlayerDisplay = document.getElementById('currentTurnDisplay');
                currentPlayerDisplay.textContent = data.message;
                break;
            case 'player_reconnected':
                currentPlayerDisplay = document.getElementById('currentTurnDisplay');
                currentPlayerDisplay.textContent = data.message;
                break;
            case 'logout_success':
                clearTimeout(logoutFallbackTimeout);
                logoutInProgress = false;
                handleLogoutSuccess();
                break;

            case 'session_transferred':
                logoutInProgress = false;
                sessionStorage.removeItem('minesweeperSessionToken');
                handleLogoutSuccess();
                loginError.textContent = data.message || 'Votre session a été transférée vers un autre terminal.';
                break;

            case 'error':
                clearPendingCells();
            if (data.message === "Ce n'est pas votre tour de jouer.") {
                    showNotYourTurnPopup();
                }
                logMessage('Erreur: ' + data.message);
                break;

           
        }
    };

    // Gestion de la fermeture ou de l'erreur de connexion WebSocket
    socket.onerror = function() {
        setConnectionStatus('Connexion interrompue', false);
        logMessage('Impossible de se connecter au serveur WebSocket.');
        showConnectionError();
    };

    socket.onclose = function() {
        if (logoutInProgress) {
            logoutInProgress = false;
            handleLogoutSuccess();
            return;
        }
        setConnectionStatus('Reconnexion…', false);
        logMessage('Connexion WebSocket fermée.');
        connected = false; // Indiquer que le client est déconnecté
        showConnectionError();
        clearInterval(keepAliveInterval);
        attemptReconnect(); // Essayer de se reconnecter
    };

}

function setConnectionStatus(label, connected) {
    const status = document.getElementById('connectionStatus');
    if (!status) return;
    const accessibleLabel = status.querySelector('.connection-status-label');
    if (accessibleLabel) accessibleLabel.textContent = label;
    status.title = label;
    status.classList.toggle('connected', connected);
}


// Masquer le formulaire de login si le serveur est inaccessible
function hideLoginForm() {
    document.getElementById('loginModal').classList.add('hidden');
}

// Afficher le formulaire de login une fois que le serveur est disponible
function showLoginForm() {
    document.getElementById('loginModal').classList.remove('hidden');
}


// Fonction pour essayer de se reconnecter régulièrement si déconnecté
function attemptReconnect() {
    if (!logoutInProgress && !connected && !isReconnecting) { // Vérifie si déjà en cours de reconnexion
        isReconnecting = true; // Empêche les nouvelles tentatives pendant la reconnexion
        clearTimeout(reconnectTimeout);
        reconnectTimeout = setTimeout(function() {
            logMessage('Tentative de reconnexion au serveur...');
            isReconnecting = false;
            connectWebSocket(); // Tente une reconnexion
        }, retryInterval);
    }
}

window.addEventListener('beforeunload', () => {
    clearInterval(keepAliveInterval);
    clearTimeout(reconnectTimeout);
});


// Fonction pour afficher un message d'erreur et masquer le formulaire de login
function showConnectionError() {
    if (!errorDiv) {

        hideLoginForm();

        errorDiv = document.createElement('div');
        errorDiv.classList.add('connection-error', 'text-center'); // Classe CSS pour styliser le message
        errorDiv.innerHTML = `
            <h2>Oups !</h2>
            <p>On dirait que notre serveur est en pause café ☕. Pas de panique, il sera de retour très bientôt !</p>
            <p>Nous tentons de le reconnecter…</p>
        `;
        const container = document.createElement('div');
        container.id = 'errorContainer'; // ID pour faciliter la suppression
        container.classList.add('container', 'd-flex', 'justify-content-center', 'align-items-center', 'vh-100');
        container.appendChild(errorDiv);
        document.body.appendChild(container); // Afficher le message d'erreur
    }
}

// Fonction pour masquer le message d'erreur de connexion
function hideConnectionError() {
    if (errorDiv) {
        const container = document.getElementById('errorContainer');
        if (container) {
            container.remove(); // Supprimer le conteneur
        }
        errorDiv = null; // Réinitialiser la référence de l'erreur
    }
    showLoginForm();
}



// Rafraîchir la liste des joueurs connectés
function refreshPlayersList(players) {
    const playersList = document.getElementById('players');
    playersList.innerHTML = '';
    const filteredPlayers = (Array.isArray(players) ? players : []).filter(
        player => Number(player.id) !== Number(currentPlayerId)
    );
    
    if (filteredPlayers.length === 0) {
        // Si aucun autre joueur en ligne, afficher le message
        const li = document.createElement('li');
        li.textContent = 'Aucun joueur en ligne';
        playersList.appendChild(li);
    } else {
        filteredPlayers.forEach(player => {
            const li = document.createElement('li');
            li.textContent = player.username;
            li.dataset.playerId = player.id;
            li.tabIndex = 0;
            li.setAttribute('role', 'button');
            li.setAttribute('aria-label', `Inviter ${player.username}`);
            li.addEventListener('click', () => invitePlayer(player.id));
            li.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    invitePlayer(player.id);
                }
            });
            playersList.appendChild(li);
        });
    }
} 

// Gestion des invitations
function invitePlayer(playerId) {
    // Afficher la popin
    const inviteModal = document.getElementById('inviteSettingsModal');
    setElementDisplay(inviteModal, 'block');

    // Gestion de la fermeture de la popin
    const closeBtn = document.getElementById('closeInviteSettings');
    closeBtn.onclick = function() {
        setElementDisplay(inviteModal, 'none');
    };

    // Gestion de l'envoi de l'invitation après sélection de la grille et difficulté
    const inviteForm = document.getElementById('inviteSettingsForm');
    inviteForm.onsubmit = function(e) {
        e.preventDefault(); // Empêche le rechargement de la page

        // Récupérer les choix de l'utilisateur
        const gridSize = document.getElementById('gridSize').value;
        const difficulty = document.getElementById('difficulty').value;

        // Envoyer l'invitation avec les paramètres choisis
        socket.send(JSON.stringify({
            type: 'invite',
            invitee: playerId,
            gridSize: gridSize,
            difficulty: difficulty
        }));

        // Masquer la popin après l'envoi
        setElementDisplay(inviteModal, 'none');

        console.log('Invitation envoyée à ' + playerId + ' avec une grille de ' + gridSize + ' et une difficulté de ' + difficulty + '%');
    };
}

function acceptInvite() {
    const inviter = document.getElementById('inviter').textContent;

    // Envoyer la réponse d'acceptation avec l'invitationId
    socket.send(JSON.stringify({
        type: 'accept_invite',
        inviter: inviter,
        invitationId: currentInvitationId // Utiliser la variable stockée
    }));

    // Masquer la popin d'invitation
    setElementDisplay(document.getElementById('invitation'), 'none');
}

function declineInvite() {
    const inviter = document.getElementById('inviter').textContent;

    // Envoyer la réponse de refus avec l'invitationId
    socket.send(JSON.stringify({
        type: 'decline_invite',
        inviter: inviter,
        invitationId: currentInvitationId // Utiliser la variable stockée
    }));

    // Masquer la popin d'invitation
    setElementDisplay(document.getElementById('invitation'), 'none');
}

// Afficher le plateau de jeu
function displayGameBoard(board, losingCell = null) {
    const gameBoardDiv = document.getElementById('gameBoard');
    gameBoardDiv.innerHTML = ''; // Réinitialiser le plateau de jeu

    const table = document.createElement('table');
    const columnCount = board[0]?.length || 0;
    table.classList.toggle('board-small', columnCount <= 10);
    table.style.setProperty('--board-columns', Math.max(columnCount, 1));
    board.forEach((row, x) => {
        const tr = document.createElement('tr');
        row.forEach((cell, y) => {
            const td = document.createElement('td');
            td.classList.add('cell');
            td.dataset.x = x;
            td.dataset.y = y;

            const cellInner = document.createElement('div');
            cellInner.classList.add('cell-inner');

            const cellFront = document.createElement('div');
            cellFront.classList.add('cell-front'); // Côté non révélé (mer)

            const cellBack = document.createElement('div');
            cellBack.classList.add('cell-back'); // Côté révélé (fond marin)

            // Si la cellule est révélée
            if (cell.revealed) {
                td.classList.add('revealed');
                if (cell.mine) {
                    soundMine.play();
                    cellBack.textContent = '💣'; // Afficher la mine

                    // Vérifier si c'est la mine qui a provoqué la fin de la partie
                    if (losingCell && x == losingCell.x && y == losingCell.y) {
                        cellBack.classList.add('mine-triggered');
                        cellBack.setAttribute('aria-label', 'Case ayant déclenché la défaite');
                        cellBack.title = 'Clic ayant déclenché la défaite';
                    }
                } else if (cell.adjacentMines > 0) {
                    cellBack.textContent = cell.adjacentMines; // Afficher le nombre de mines adjacentes
                    // Ajouter une classe pour la couleur du nombre de mines (et le cercle)
                    cellBack.classList.add(`mine-number-${cell.adjacentMines}`);
                }
            } else {
                // Si la cellule est marquée par un drapeau
                if (cell.flagged) {
                    td.classList.add('cell-flagged'); // Ajouter la classe pour les drapeaux
                    cellFront.textContent = '🚩'; // Afficher le drapeau
                }
            }
            td.dataset.state = `${Number(Boolean(cell.revealed))}:${Number(Boolean(cell.flagged))}:${cell.adjacentMines ?? ''}`;

            cellInner.appendChild(cellFront);
            cellInner.appendChild(cellBack);
            td.appendChild(cellInner);

            // Gestion des clics (révélation des cases)
            td.addEventListener('click', () => {
                if (mobileGameQuery.matches && touchActionMode === 'flag') {
                    placeFlag(x, y);
                } else {
                    revealCell(x, y, td);
                }
            });
            td.tabIndex = 0;
            td.setAttribute('role', 'button');
            td.setAttribute('aria-label', `Case ligne ${x + 1}, colonne ${y + 1}`);
            td.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    revealCell(x, y, td);
                } else if (event.key.toLowerCase() === 'f') {
                    event.preventDefault();
                    placeFlag(x, y);
                }
            });
            // Clic droit pour placer ou retirer un drapeau
            td.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                placeFlag(x, y);
            });

            tr.appendChild(td);
        });
        table.appendChild(tr);
    });
    gameBoardDiv.appendChild(table);
}

function setTouchActionMode(mode) {
    touchActionMode = mode === 'flag' ? 'flag' : 'reveal';
    const revealButton = document.getElementById('revealModeBtn');
    const flagButton = document.getElementById('flagModeBtn');
    const revealSelected = touchActionMode === 'reveal';
    revealButton.classList.toggle('active', revealSelected);
    flagButton.classList.toggle('active', !revealSelected);
    revealButton.setAttribute('aria-pressed', String(revealSelected));
    flagButton.setAttribute('aria-pressed', String(!revealSelected));
    document.getElementById('gameBoard').dataset.touchAction = touchActionMode;
}

document.getElementById('revealModeBtn').addEventListener('click', () => setTouchActionMode('reveal'));
document.getElementById('flagModeBtn').addEventListener('click', () => setTouchActionMode('flag'));
setTouchActionMode('reveal');

// Mettre à jour la grille en place évite de recréer des centaines de cellules
// et d'écouteurs à chaque coup.
function updateGameBoard(board) {
    const table = document.querySelector('#gameBoard table');
    if (!table || table.rows.length !== board.length ||
        (board[0] && table.rows[0]?.cells.length !== board[0].length)) {
        displayGameBoard(board);
        return;
    }

    board.forEach((row, x) => {
        row.forEach((cell, y) => {
            const td = table.rows[x].cells[y];
            const state = `${Number(Boolean(cell.revealed))}:${Number(Boolean(cell.flagged))}:${cell.adjacentMines ?? ''}`;
            if (td.dataset.state === state) {
                td.classList.remove('pending-reveal');
                return;
            }
            const front = td.querySelector('.cell-front');
            const back = td.querySelector('.cell-back');

            td.classList.remove('pending-reveal');
            td.classList.toggle('revealed', Boolean(cell.revealed));
            td.classList.toggle('cell-flagged', !cell.revealed && Boolean(cell.flagged));
            front.textContent = !cell.revealed && cell.flagged ? '🚩' : '';
            back.textContent = '';
            for (let number = 1; number <= 8; number++) {
                back.classList.remove(`mine-number-${number}`);
            }
            if (cell.revealed && cell.adjacentMines > 0) {
                back.textContent = cell.adjacentMines;
                back.classList.add(`mine-number-${cell.adjacentMines}`);
            }
            td.dataset.state = state;
            const description = cell.revealed
                ? (cell.adjacentMines > 0 ? `${cell.adjacentMines} mine(s) à proximité` : 'Case vide révélée')
                : (cell.flagged ? 'Case marquée par un drapeau' : 'Case masquée');
            td.setAttribute('aria-label', `Case ligne ${x + 1}, colonne ${y + 1} : ${description}`);
        });
    });
}

function clearPendingCells() {
    document.querySelectorAll('#gameBoard .pending-reveal').forEach(cell => {
        cell.classList.remove('pending-reveal');
    });
}

function updateGameStatus(board, mineCount, currentPlayer) {
    isMyTurn = currentPlayer === username;
    const flags = board.reduce((total, row) => total + row.filter(cell => cell.flagged).length, 0);
    document.getElementById('mineCounter').textContent = `💣 ${Number(mineCount) || 0}`;
    document.getElementById('flagCounter').textContent = `🚩 ${flags}`;
    const gameBoard = document.getElementById('gameBoard');
    gameBoard.classList.toggle('waiting-turn', !isMyTurn);
    gameBoard.setAttribute('aria-disabled', isMyTurn ? 'false' : 'true');
}


// Fonction pour vider le plateau de jeu
function clearGameBoard() {
    const gameBoardDiv = document.getElementById('gameBoard');
    if (gameBoardDiv) gameBoardDiv.innerHTML = '';  // Supprimer tout le contenu du plateau de jeu
    logMessage('Le plateau de jeu a été vidé.');
}

function showNotYourTurnPopup() {
    const popup = document.getElementById('notYourTurnPopup');
    
    // Ajouter la classe 'show' pour afficher la popin
    popup.classList.add('show');
    
    // Retirer la classe 'show' après 2 secondes pour la faire disparaître
    setTimeout(() => {
        popup.classList.remove('show');
    }, 2000); // Affiche la popin pendant 2 secondes
}

function handleLogoutSuccess() {
    sessionStorage.removeItem('minesweeperSessionToken');
    clearTimeout(logoutFallbackTimeout);
    clearGameBoard();
    username = undefined;
    currentPlayerId = undefined;
    currentGameId = undefined;
    setGameActive(false);
    setElementDisplay(document.getElementById('game'), 'none');
    setElementDisplay(document.getElementById('navbar'), 'none');
    showLoginModal();
    logMessage('Vous avez été déconnecté.');
}

// Gestion des cellules
function revealCell(x, y, cellElement = null) {
    if (!isMyTurn) {
        showNotYourTurnPopup();
        return;
    }
    if (currentGameId && socket.readyState === WebSocket.OPEN) {
        if (cellElement?.classList.contains('pending-reveal') ||
            cellElement?.classList.contains('revealed') ||
            cellElement?.classList.contains('cell-flagged')) {
            return;
        }
        cellElement?.classList.add('pending-reveal');
        socket.send(JSON.stringify({
            type: 'reveal_cell',
            game_id: currentGameId,  // Utilisez le game_id stocké
            x: x,
            y: y
        }));
        soundClick.play();
        logMessage('Cellule révélée: (' + x + ', ' + y + ')');
    } else {
        console.error('game_id manquant lors de la révélation de la cellule');
    }
}

function placeFlag(x, y) {
    if (!isMyTurn) {
        showNotYourTurnPopup();
        return;
    }
    if (currentGameId) {  // Vérifiez que le game_id est bien défini
        socket.send(JSON.stringify({
            type: 'place_flag',
            game_id: currentGameId,  // Utilisez le game_id stocké
            x: x,
            y: y
        }));
        soundFlag.play();
        logMessage('Drapeau posé: (' + x + ', ' + y + ')');
    } else {
        console.error('game_id manquant lors du placement du drapeau');
    }
}

// Afficher le modal du gagnant
function showWinnerModal(winnerMessage, gameId) {
    currentGameId = gameId;
    const modal = document.getElementById('winnerModal');
    const message = document.getElementById('winnerMessage');
    message.textContent = winnerMessage;
    setElementDisplay(modal, 'flex');
    
}

async function sendLogin(username, password) {
    socket.send(JSON.stringify({
        type: 'login',
        username: username,
        password: password
    }));
}

async function sendRegister(username, email, password) {
    socket.send(JSON.stringify({
        type: 'register',
        username: username,
        email: email,
        password: password // Transporté uniquement via le WebSocket TLS en production
    }));
}

// Fermer la modale du gagnant
document.getElementById('closeModalBtn').addEventListener('click', () => {
    setElementDisplay(document.getElementById('winnerModal'), 'none');
    setElementDisplay(document.getElementById('availableUser'), 'block');
    setElementDisplay(document.getElementById('gameContainer'), 'none');
    clearGameBoard();
    socket.send(JSON.stringify({
        type: 'refresh_players',
        game_id: currentGameId
    }));
    currentGameId = undefined;
    setGameActive(false);
});

loginBtn.addEventListener('click', async () => {
    const username = document.getElementById('loginUsername').value;
    const password = document.getElementById('loginPassword').value;
    await sendLogin(username, password); // Fonction existante pour envoyer les infos de login
    console.log('Tentative de connexion pour ' + username);
});

// Gestion de la création de compte
registerBtn.addEventListener('click', async () => {
    const username = document.getElementById('registerUsername').value;
    const email = document.getElementById('registerEmail').value;
    const password = document.getElementById('registerPassword').value;
    const confirmation = document.getElementById('registerPasswordConfirmation').value;
    if (password !== confirmation) {
        registerError.textContent = 'Les mots de passe ne correspondent pas.';
        return;
    }
    await sendRegister(username, email, password);
    console.log('Tentative de création de compte pour ' + username);
});

// Fonction pour cacher le modal avec animation de rebond
function hideModal(modal) {
    modal.classList.add('bounceOut'); // Ajouter la classe pour l'animation
    modal.addEventListener('animationend', () => {
        modal.classList.add('hidden'); // Cacher le modal après l'animation
        modal.classList.remove('bounceOut'); // Réinitialiser la classe
    }, { once: true });
}

document.getElementById('logoutLink').addEventListener('click', (event) => {
    event.preventDefault();
    if (logoutInProgress) return;
    logoutInProgress = true;
    const sessionToken = sessionStorage.getItem('minesweeperSessionToken');
    logMessage('Déconnexion de ' + username);
    if (socket?.readyState === WebSocket.OPEN) {
        try {
            socket.send(JSON.stringify({ type: 'logout', sessionToken }));
            logoutFallbackTimeout = setTimeout(() => {
                logoutInProgress = false;
                showLoginModal();
            }, 2000);
        } catch (error) {
            console.error('Envoi de la déconnexion impossible :', error);
            logoutInProgress = false;
        }
    } else {
        logoutInProgress = false;
    }
    handleLogoutSuccess();
});

document.getElementById('acceptInviteBtn').addEventListener('click', acceptInvite);
document.getElementById('declineInviteBtn').addEventListener('click', declineInvite);



// Sélectionner les éléments du menu burger et des liens
const navbarToggler = document.querySelector('.navbar-toggler');
const navbarCollapse = document.querySelector('.navbar-collapse');
const navLinks = document.querySelectorAll('.nav-link');

// Fonction pour masquer le menu burger
function hideMenu() {
    if (navbarCollapse.classList.contains('show')) {
        navbarToggler.click();  // Simule un clic sur le bouton du burger pour fermer le menu
    }
}

// Fermer le menu après l'action du lien. Un gestionnaire sur `blur` fermerait
// le panneau avant que le clic mobile sur le lien ait le temps d'être émis.
navLinks.forEach((navLink) => {
    navLink.addEventListener('click', () => setTimeout(hideMenu, 0));
});

// Un clic réellement extérieur replie également le menu.
document.addEventListener('click', (event) => {
    if (!navbarCollapse.contains(event.target) && !navbarToggler.contains(event.target)) {
        hideMenu();
    }
});


// Démarrer la connexion WebSocket lors du chargement de la page
window.onload = function() {
    connectWebSocket();
};
