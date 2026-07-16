<?php

declare(strict_types=1);

final class AppVersion
{
    public static function current(string $projectDirectory): string
    {
        $configuredVersion = trim((string) getenv('APP_VERSION'));
        if ($configuredVersion !== '') {
            return $configuredVersion;
        }

        $versionFile = rtrim($projectDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.version';
        $generatedVersion = is_readable($versionFile) ? trim((string) file_get_contents($versionFile)) : '';

        return $generatedVersion !== '' ? $generatedVersion : 'développement';
    }
}
