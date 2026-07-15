const { test, expect } = require('@playwright/test');
const crypto = require('crypto');

function decodeBase32(value) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const character of value) bits += alphabet.indexOf(character).toString(2).padStart(5, '0');
  return Buffer.from((bits.match(/.{8}/g) || []).map(byte => parseInt(byte, 2)));
}

function totp(secret, timestamp = Date.now()) {
  const counter = BigInt(Math.floor(timestamp / 30000));
  const buffer = Buffer.alloc(8);
  buffer.writeBigUInt64BE(counter);
  const digest = crypto.createHmac('sha1', decodeBase32(secret)).update(buffer).digest();
  const offset = digest[19] & 15;
  const number = (digest.readUInt32BE(offset) & 0x7fffffff) % 1000000;
  return String(number).padStart(6, '0');
}

async function login(page, secondFactor = '') {
  await page.goto('/admin/login.php');
  await page.getByLabel('Utilisateur').fill('e2e_admin');
  await page.getByLabel('Mot de passe').fill('E2e-Admin-Password!2026');
  if (secondFactor) await page.getByLabel(/Code Authenticator/).fill(secondFactor);
  await page.getByRole('button', { name: 'Connexion' }).click();
}

test('activation QR, code de récupération et consommation unique', async ({ page }) => {
  await login(page);
  await expect(page).toHaveURL(/\/admin\/?$/);
  await page.goto('/admin/security.php');
  await page.getByRole('button', { name: /Afficher le QR Code/ }).click();
  const secret = (await page.locator('.totp-secret').textContent()).trim();
  await expect(page.locator('.totp-qr')).toBeVisible();
  await page.getByLabel(/Code à six chiffres/).fill(totp(secret));
  await page.getByRole('button', { name: /Vérifier et activer/ }).click();
  const recoveryCode = (await page.locator('.recovery-codes code').first().textContent()).trim();
  await page.goto('/admin/');
  await page.getByRole('button', { name: 'Déconnexion' }).click();
  await login(page, recoveryCode);
  await expect(page).toHaveURL(/\/admin\/?$/);
  await page.getByRole('button', { name: 'Déconnexion' }).click();
  await login(page, recoveryCode);
  await expect(page.getByText('Identifiants incorrects.')).toBeVisible();
});
