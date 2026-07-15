const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 30000,
  use: { baseURL: 'http://127.0.0.1:8099', trace: 'retain-on-failure' },
  webServer: {
    command: 'php -S 127.0.0.1:8099 -t .',
    url: 'http://127.0.0.1:8099/',
    reuseExistingServer: true
  }
});
