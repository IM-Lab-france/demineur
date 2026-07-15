<?php
declare(strict_types=1);

function ai_config_defaults(): array {
    return [
        'level' => 'medium',
        'pause' => 700,
        'jitter' => 350,
        'gridSize' => '20x20',
        'difficulty' => 15,
        'inviteTarget' => 'none',
        'autoAccept' => true,
        'rematch' => true,
        'useFlags' => true,
        'risk' => 25,
    ];
}

function ai_config_path(string $iaName): string {
    if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $iaName)) {
        throw new InvalidArgumentException('Nom d’IA invalide.');
    }
    $directory = getenv('APP_CONFIG_DIR') ?: '/var/www/secure';
    return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ai-' . $iaName . '.env';
}

function ai_config_from_values(array $values): array {
    $defaults = ai_config_defaults();
    $level = (string) ($values['level'] ?? $defaults['level']);
    $gridSize = (string) ($values['gridSize'] ?? $defaults['gridSize']);
    $inviteTarget = (string) ($values['inviteTarget'] ?? $defaults['inviteTarget']);
    $difficulty = (int) ($values['difficulty'] ?? $defaults['difficulty']);
    return [
        'level' => in_array($level, ['easy', 'medium', 'hard', 'expert', 'master'], true) ? $level : $defaults['level'],
        'pause' => max(100, min(10000, (int) ($values['pause'] ?? $defaults['pause']))),
        'jitter' => max(0, min(5000, (int) ($values['jitter'] ?? $defaults['jitter']))),
        'gridSize' => in_array($gridSize, ['10x10', '20x20', '30x30'], true) ? $gridSize : $defaults['gridSize'],
        'difficulty' => in_array($difficulty, [10, 15, 22], true) ? $difficulty : $defaults['difficulty'],
        'inviteTarget' => in_array($inviteTarget, ['none', 'ai', 'human', 'all'], true) ? $inviteTarget : 'none',
        'autoAccept' => filter_var($values['autoAccept'] ?? $defaults['autoAccept'], FILTER_VALIDATE_BOOLEAN),
        'rematch' => filter_var($values['rematch'] ?? $defaults['rematch'], FILTER_VALIDATE_BOOLEAN),
        'useFlags' => filter_var($values['useFlags'] ?? $defaults['useFlags'], FILTER_VALIDATE_BOOLEAN),
        'risk' => max(0, min(100, (int) ($values['risk'] ?? $defaults['risk']))),
    ];
}

function read_ai_config(string $iaName): array {
    $values = [];
    $path = ai_config_path($iaName);
    if (is_readable($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (!str_contains($line, '=') || str_starts_with(ltrim($line), '#')) continue;
            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value);
        }
    }
    return ai_config_from_values([
        'level' => $values['IA_LEVEL'] ?? null,
        'pause' => $values['IA_PAUSE_MS'] ?? null,
        'jitter' => $values['IA_PAUSE_JITTER_MS'] ?? null,
        'gridSize' => $values['IA_GRID_SIZE'] ?? null,
        'difficulty' => $values['IA_DIFFICULTY'] ?? null,
        'inviteTarget' => $values['IA_INVITE_TARGET'] ?? (($values['IA_INVITE'] ?? '0') === '1' ? 'ai' : null),
        'autoAccept' => $values['IA_AUTO_ACCEPT'] ?? null,
        'rematch' => $values['IA_REMATCH'] ?? null,
        'useFlags' => $values['IA_USE_FLAGS'] ?? null,
        'risk' => $values['IA_RISK_PERCENT'] ?? null,
    ]);
}

function write_ai_config(string $iaName, array $values): array {
    $config = ai_config_from_values($values);
    $path = ai_config_path($iaName);
    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('Répertoire sécurisé de configuration indisponible.');
    }
    $lines = [
        'IA_LEVEL=' . $config['level'],
        'IA_PAUSE_MS=' . $config['pause'],
        'IA_PAUSE_JITTER_MS=' . $config['jitter'],
        'IA_GRID_SIZE=' . $config['gridSize'],
        'IA_DIFFICULTY=' . $config['difficulty'],
        'IA_INVITE_TARGET=' . $config['inviteTarget'],
        'IA_INVITE=' . ($config['inviteTarget'] === 'none' ? '0' : '1'),
        'IA_AUTO_ACCEPT=' . ($config['autoAccept'] ? '1' : '0'),
        'IA_REMATCH=' . ($config['rematch'] ? '1' : '0'),
        'IA_USE_FLAGS=' . ($config['useFlags'] ? '1' : '0'),
        'IA_RISK_PERCENT=' . $config['risk'],
    ];
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, implode("\n", $lines) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Écriture de la configuration impossible.');
    }
    @chmod($temporary, 0640);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Installation de la configuration impossible.');
    }
    return $config;
}
