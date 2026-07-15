const { test, expect } = require('@playwright/test');
const WebSocket = require('ws');

const wsUrl = process.env.E2E_WS_URL;
const accounts = [
  [process.env.E2E_PLAYER1, process.env.E2E_PASSWORD1],
  [process.env.E2E_PLAYER2, process.env.E2E_PASSWORD2]
];

test('deux joueurs peuvent démarrer une partie et jouer un coup', async () => {
  test.skip(!wsUrl || accounts.some(([u, p]) => !u || !p), 'Identifiants E2E non configurés');
  const connect = ([username, password]) => new Promise((resolve, reject) => {
    const socket = new WebSocket(wsUrl, { origin: process.env.E2E_ORIGIN || 'http://localhost' });
    const timer = setTimeout(() => reject(new Error('Délai WebSocket dépassé')), 10000);
    socket.on('open', () => socket.send(JSON.stringify({ type: 'login', username, password })));
    socket.on('message', raw => {
      const data = JSON.parse(raw);
      if (data.type === 'login_success') { clearTimeout(timer); resolve({ socket, data }); }
      if (data.type === 'login_failed') { clearTimeout(timer); reject(new Error(data.message)); }
    });
    socket.on('error', reject);
  });
  const clients = await Promise.all(accounts.map(connect));
  expect(clients.map(c => c.data.playerId)).toHaveLength(2);
  const waitFor = (client, type) => new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error(`Message ${type} non reçu`)), 10000);
    const handler = raw => {
      const data = JSON.parse(raw);
      if (data.type === type) {
        clearTimeout(timer);
        client.socket.off('message', handler);
        resolve(data);
      }
    };
    client.socket.on('message', handler);
  });
  const invitation = waitFor(clients[1], 'invite');
  clients[0].socket.send(JSON.stringify({
    type: 'invite', invitee: clients[1].data.playerId, gridSize: '10x10', difficulty: 10
  }));
  const invite = await invitation;
  const starts = clients.map(client => waitFor(client, 'game_start'));
  clients[1].socket.send(JSON.stringify({ type: 'accept_invite', invitationId: invite.invitationId }));
  const games = await Promise.all(starts);
  expect(games[0].game_id).toBe(games[1].game_id);
  const current = games[0].currentPlayer === clients[0].data.username ? clients[0] : clients[1];
  const update = waitFor(clients[0], 'update_board');
  current.socket.send(JSON.stringify({ type: 'reveal_cell', game_id: games[0].game_id, x: 0, y: 0 }));
  expect(await update).toMatchObject({ type: 'update_board' });
  clients.forEach(c => c.socket.close());
});
