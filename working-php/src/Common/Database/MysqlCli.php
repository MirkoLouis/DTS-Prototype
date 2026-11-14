<?php

namespace App\Common\Database;

/**
 * Runs the MySQL client tools (mysqldump, mysql) for backups and restores.
 *
 * The command is an argument array handed straight to the operating system: no shell parses it, so
 * no value from configuration or input can be interpreted as shell syntax. The password is passed in
 * the child process's environment (MYSQL_PWD), never on the command line, where other users on the
 * machine could read it from the process list.
 */
class MysqlCli
{
    /**
     * Build the argument array for a tool: connection options, the caller's arguments, then the database.
     *
     * @param string   $tool 'mysqldump' or 'mysql' (found on PATH)
     * @param string[] $args extra arguments placed after the connection options
     * @return string[]
     */
    public static function command(string $tool, array $args): array
    {
        $db = self::database();

        return [
            $tool,
            '--host=' . $db['host'],
            '--port=' . $db['port'],
            '--user=' . $db['user'],
            ...array_values($args),
            $db['dbname'],
        ];
    }

    /**
     * Run a tool and wait for it to finish.
     *
     * @param string|null $stdinFile  file fed to the tool's standard input (a dump to restore)
     * @param string|null $stdoutFile file the tool's standard output is written to (the dump)
     * @throws \RuntimeException when the tool cannot start or exits with an error; the message carries its error text
     */
    public static function run(string $tool, array $args, ?string $stdinFile = null, ?string $stdoutFile = null): void
    {
        // Error text goes to a pipe, so it never ends up inside the dump file
        $descriptors = [
            0 => ['file', $stdinFile ?? '/dev/null', 'r'],
            1 => ['file', $stdoutFile ?? '/dev/null', 'w'],
            2 => ['pipe', 'w'],
        ];

        // The child inherits this process's environment, plus the password when one is set
        $environment = getenv();
        $password = self::database()['password'];
        if ($password !== '') {
            $environment['MYSQL_PWD'] = $password;
        }

        $process = proc_open(self::command($tool, $args), $descriptors, $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new \RuntimeException("Could not start {$tool}. Is the MySQL client installed and on PATH?");
        }

        // Read the error text until the tool closes it, then collect the exit code
        $errorText = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \RuntimeException("{$tool} failed (exit {$exitCode}): " . trim((string) $errorText));
        }
    }

    /**
     * Connection settings from the app configuration.
     */
    private static function database(): array
    {
        return (require BASE_PATH . '/src/Common/Config/app.config.php')['database'];
    }
}
