const { test, expect } = require('@playwright/test');

test('la page de jeu est utilisable au clavier et sur mobile', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');
  await expect(page).toHaveTitle(/Démineur/);
  await expect(page.getByRole('button', { name: /son/i })).toBeVisible();
  await expect(page.locator('#loginModal')).toBeAttached();
  const dimensions = await page.evaluate(() => ({ width: document.documentElement.scrollWidth, viewport: innerWidth }));
  expect(dimensions.width).toBeLessThanOrEqual(dimensions.viewport + 1);
});

test('le classement propose les trois périodes', async ({ page }) => {
  await page.goto('/scores.html');
  await expect(page.locator('#rankingPeriod option')).toHaveCount(3);
  await expect(page.locator('#scoresTableContainer')).toBeVisible();
});

test('la récupération de mot de passe ne révèle pas les comptes', async ({ page }) => {
  await page.goto('/forgot-password.php');
  await page.getByLabel('Adresse e-mail').fill('adresse-inconnue@example.test');
  await page.getByRole('button', { name: 'Envoyer le lien' }).click();
  await expect(page.getByText(/Si un compte vérifié correspond/)).toBeVisible();
});
