<?php

namespace App\Services\Marketing;

use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppConnectorLauncherService
{
    /**
     * @return array{started: bool, message: string, command?: string, working_directory?: string, stdout_log?: string, stderr_log?: string, reused?: bool}
     */
    public function launch(bool $headless = true): array
    {
        $workingDirectory = $this->workingDirectory();
        $entryScript = $workingDirectory.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'server.js';
        $stdoutLog = storage_path('logs/whatsapp-connector.out.log');
        $stderrLog = storage_path('logs/whatsapp-connector.err.log');
        $nodeBinary = $this->nodeBinary();

        if (! is_dir($workingDirectory)) {
            return [
                'started' => false,
                'message' => 'Cartella del connettore WhatsApp non trovata.',
                'working_directory' => $workingDirectory,
            ];
        }

        if (! is_file($entryScript)) {
            return [
                'started' => false,
                'message' => 'Script server.js del connettore WhatsApp non trovato.',
                'working_directory' => $workingDirectory,
            ];
        }

        $listeningPids = $this->findListeningPids($this->connectorPort());
        if ($listeningPids !== []) {
            return [
                'started' => true,
                'reused' => true,
                'message' => 'Connettore WhatsApp gia attivo sulla porta configurata.',
                'working_directory' => $workingDirectory,
            ];
        }

        if ($this->launchRecentlyRequested()) {
            return [
                'started' => true,
                'reused' => true,
                'message' => 'Avvio del connettore WhatsApp gia richiesto. Attendere qualche secondo.',
                'working_directory' => $workingDirectory,
            ];
        }

        $this->ensureLogFileExists($stdoutLog);
        $this->ensureLogFileExists($stderrLog);

        $command = $this->buildLaunchCommand(
            workingDirectory: $workingDirectory,
            nodeBinary: $nodeBinary,
            stdoutLog: $stdoutLog,
            stderrLog: $stderrLog,
            headless: $headless,
        );

        Log::info('Launching WhatsApp connector process.', [
            'working_directory' => $workingDirectory,
            'stdout_log' => $stdoutLog,
            'stderr_log' => $stderrLog,
            'headless' => $headless,
            'command' => $command,
        ]);

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $handle = popen($command, 'r');
                if ($handle === false) {
                    throw new RuntimeException('Impossibile avviare il processo cmd per il connettore WhatsApp.');
                }
                pclose($handle);
            } else {
                exec($command);
            }

            $this->markLaunchRequested();
        } catch (\Throwable $exception) {
            Log::error('WhatsApp connector launch failed.', [
                'message' => $exception->getMessage(),
                'working_directory' => $workingDirectory,
                'command' => $command,
            ]);

            return [
                'started' => false,
                'message' => 'Avvio del connettore WhatsApp non riuscito.',
                'command' => $command,
                'working_directory' => $workingDirectory,
                'stdout_log' => $stdoutLog,
                'stderr_log' => $stderrLog,
            ];
        }

        return [
            'started' => true,
            'message' => 'Processo connettore WhatsApp avviato.',
            'command' => $command,
            'working_directory' => $workingDirectory,
            'stdout_log' => $stdoutLog,
            'stderr_log' => $stderrLog,
        ];
    }

    /**
     * @return array{stopped: bool, pids: array<int, int>, message: string, port: int}
     */
    public function stopProcessOnPort(?int $port = null): array
    {
        $port = $port ?: $this->connectorPort();
        $pids = $this->findListeningPids($port);
        if ($pids === []) {
            return [
                'stopped' => true,
                'pids' => [],
                'message' => 'Nessun processo WhatsApp attivo sulla porta richiesta.',
                'port' => $port,
            ];
        }

        foreach ($pids as $pid) {
            $this->killPid($pid);
        }

        $this->clearLaunchMarker();

        Log::info('Stopped connector processes on port.', [
            'port' => $port,
            'pids' => $pids,
        ]);

        return [
            'stopped' => true,
            'pids' => $pids,
            'message' => 'Processo connettore WhatsApp precedente terminato.',
            'port' => $port,
        ];
    }

    private function workingDirectory(): string
    {
        return (string) config('services.whatsapp_puppeteer.connector_workdir', base_path('whatsapp-connector'));
    }

    private function nodeBinary(): string
    {
        return trim((string) config('services.whatsapp_puppeteer.node_binary', 'node')) ?: 'node';
    }

    private function connectorPort(): int
    {
        $configured = trim((string) config('services.whatsapp_puppeteer.base_url', ''));
        if ($configured !== '') {
            $parsed = parse_url($configured, PHP_URL_PORT);
            if (is_int($parsed) && $parsed > 0) {
                return $parsed;
            }

            if (is_string($parsed) && ctype_digit($parsed)) {
                return (int) $parsed;
            }
        }

        return 3101;
    }

    private function launchMarkerPath(): string
    {
        return storage_path('framework/cache/whatsapp-connector-launch.json');
    }

    private function launchCooldownSeconds(): int
    {
        return max(5, (int) config('services.whatsapp_puppeteer.launch_cooldown_seconds', 20));
    }

    private function ensureLogFileExists(string $path): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (! is_file($path)) {
            file_put_contents($path, '');
        }
    }

    private function markLaunchRequested(): void
    {
        $path = $this->launchMarkerPath();
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, json_encode([
            'requested_at' => now()->toIso8601String(),
            'pid' => getmypid(),
        ], JSON_THROW_ON_ERROR));
    }

    private function clearLaunchMarker(): void
    {
        $path = $this->launchMarkerPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function launchRecentlyRequested(): bool
    {
        $path = $this->launchMarkerPath();
        if (! is_file($path)) {
            return false;
        }

        $contents = @file_get_contents($path);
        if (! is_string($contents) || trim($contents) === '') {
            return false;
        }

        try {
            /** @var array{requested_at?: string|null} $payload */
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            $requestedAt = isset($payload['requested_at']) ? strtotime((string) $payload['requested_at']) : false;
            if ($requestedAt === false) {
                return false;
            }

            $isRecent = (time() - $requestedAt) < $this->launchCooldownSeconds();
            if (! $isRecent) {
                $this->clearLaunchMarker();
            }

            return $isRecent;
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildLaunchCommand(
        string $workingDirectory,
        string $nodeBinary,
        string $stdoutLog,
        string $stderrLog,
        bool $headless,
    ): string {
        $headlessValue = $headless ? 'true' : 'false';

        if (PHP_OS_FAMILY === 'Windows') {
            return 'cmd /c "cd /d '.escapeshellarg($workingDirectory)
                .' && set "WHATSAPP_PUPPETEER_HEADLESS='.$headlessValue.'"'
                .' && start "" /B '.escapeshellarg($nodeBinary)
                .' src\\server.js 1>>'.escapeshellarg($stdoutLog)
                .' 2>>'.escapeshellarg($stderrLog).'"';
        }

        return 'cd '.escapeshellarg($workingDirectory)
            .' && WHATSAPP_PUPPETEER_HEADLESS='.$headlessValue.' nohup '.escapeshellarg($nodeBinary)
            .' src/server.js >> '.escapeshellarg($stdoutLog)
            .' 2>> '.escapeshellarg($stderrLog).' < /dev/null &';
    }

    /**
     * @return array<int, int>
     */
    private function findListeningPids(int $port): array
    {
        $output = [];
        $command = PHP_OS_FAMILY === 'Windows'
            ? 'netstat -ano -p tcp | findstr ":'.$port.'"'
            : 'lsof -nP -iTCP:'.$port.' -sTCP:LISTEN -t';

        @exec($command, $output);

        $pids = [];
        foreach ($output as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '') {
                continue;
            }

            if (PHP_OS_FAMILY === 'Windows') {
                $columns = preg_split('/\s+/', $trimmed);
                if (! is_array($columns) || count($columns) < 5) {
                    continue;
                }

                $pid = (int) ($columns[count($columns) - 1] ?? 0);
                $state = strtoupper((string) ($columns[count($columns) - 2] ?? ''));
                if ($pid > 0 && in_array($state, ['LISTENING', 'ESTABLISHED'], true)) {
                    $pids[] = $pid;
                }

                continue;
            }

            $pid = (int) $trimmed;
            if ($pid > 0) {
                $pids[] = $pid;
            }
        }

        return array_values(array_unique($pids));
    }

    private function killPid(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        $command = PHP_OS_FAMILY === 'Windows'
            ? 'taskkill /F /PID '.(int) $pid
            : 'kill -9 '.(int) $pid;

        @exec($command);
    }
}
