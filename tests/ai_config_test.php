<?php
declare(strict_types=1);

require_once __DIR__ . '/../ia/deminium/ai_config.php';

function ai_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$defaults = ai_config_defaults();
ai_assert($defaults['level'] === 'medium', 'Le niveau par défaut doit être normal.');
ai_assert($defaults['gridSize'] === '20x20', 'La grille par défaut doit être 20x20.');

$validated = ai_config_from_values([
    'level' => 'expert', 'pause' => 50, 'jitter' => 9000,
    'gridSize' => '40x40', 'difficulty' => 99, 'inviteTarget' => 'all',
    'autoAccept' => '0', 'rematch' => '1', 'useFlags' => '0', 'risk' => 120,
]);
ai_assert($validated['level'] === 'expert', 'Le niveau expert doit être accepté.');
ai_assert(ai_config_from_values(['level' => 'master'])['level'] === 'master', 'Le niveau maître doit être accepté.');
ai_assert($validated['pause'] === 100 && $validated['jitter'] === 5000, 'Les délais doivent être bornés.');
ai_assert($validated['gridSize'] === '20x20' && $validated['difficulty'] === 15, 'Les paramètres de partie invalides doivent revenir aux valeurs sûres.');
ai_assert($validated['autoAccept'] === false && $validated['rematch'] === true && $validated['useFlags'] === false, 'Les options booléennes doivent être normalisées.');
ai_assert($validated['risk'] === 100, 'Le risque doit être borné.');

$temporaryDirectory = sys_get_temp_dir() . '/demineur-ai-config-' . bin2hex(random_bytes(4));
mkdir($temporaryDirectory, 0700);
putenv('APP_CONFIG_DIR=' . $temporaryDirectory);
$written = write_ai_config('TestBot', $validated);
$read = read_ai_config('TestBot');
ai_assert($read === $written, 'La configuration doit pouvoir être relue sans perte.');
ai_assert((fileperms(ai_config_path('TestBot')) & 0777) === 0640, 'La configuration doit rester privée.');
unlink(ai_config_path('TestBot'));
rmdir($temporaryDirectory);
putenv('APP_CONFIG_DIR');

echo "Tests de configuration IA réussis.\n";
