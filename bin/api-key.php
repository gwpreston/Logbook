<?php

declare(strict_types=1);

/*
 * API keys from the command line (spec.md §7.20), for headless installs and
 * scripts: the same keys as Settings → API keys.
 *
 *   php bin/api-key.php create --user <username> --name <name> --scope read|read_write
 *   php bin/api-key.php list [--user <username>]
 *   php bin/api-key.php revoke <id>
 *
 * `create` prints the token alone on stdout (it is shown only this once),
 * so a script can capture it:
 *
 *   TOKEN=$(php bin/api-key.php create --user pat --name "OBD dongle" --scope read_write)
 *
 * Docker: docker compose exec -u www-data app php bin/api-key.php list
 *
 * Exit code: 0 ok, 1 failed, 2 usage.
 */

use Logbook\Domain\Api\ApiScope;
use Logbook\Domain\User\Username;
use Logbook\Kernel;
use Logbook\Repository\ApiKeyRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Api\ApiKeyService;

require dirname(__DIR__) . '/vendor/autoload.php';

$usage = "Usage: php bin/api-key.php create --user <username> --name <name> --scope read|read_write\n"
    . "       php bin/api-key.php list [--user <username>]\n"
    . "       php bin/api-key.php revoke <id>\n";
// The command line, as strings (the $argv global is not guaranteed to be set).
$args = array_values(array_filter((array) ($_SERVER['argv'] ?? []), 'is_string'));
$command = $args[1] ?? '';

// "--name value" or "--name=value".
$options = [];
for ($i = 2; $i < count($args); $i++) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/s', $args[$i], $m) === 1) {
        $options[$m[1]] = $m[2] ?? ($args[++$i] ?? '');
    }
}

$container = Kernel::createContainer(Kernel::settings());
$keys = $container->get(ApiKeyService::class);
$stored = $container->get(ApiKeyRepository::class);
$users = $container->get(UserRepository::class);
assert($keys instanceof ApiKeyService);
assert($stored instanceof ApiKeyRepository);
assert($users instanceof UserRepository);

$userNamed = static function (?string $username) use ($users) {
    $user = $username === null ? null : $users->findByUsername(Username::normalise($username));
    if ($user === null) {
        fwrite(STDERR, sprintf("No user named \"%s\".\n", $username ?? ''));
        exit(1);
    }

    return $user;
};

switch ($command) {
    case 'create':
        $name = trim($options['name'] ?? '');
        $scope = ApiScope::tryFrom($options['scope'] ?? '');
        if ($name === '' || mb_strlen($name) > ApiKeyService::NAME_MAX_LENGTH || $scope === null) {
            fwrite(STDERR, sprintf(
                "A key needs --name (1 to %d characters) and --scope read or read_write.\n",
                ApiKeyService::NAME_MAX_LENGTH,
            ));
            fwrite(STDERR, $usage);
            exit(2);
        }
        $created = $keys->create($userNamed($options['user'] ?? null), $name, $scope);
        fwrite(STDERR, sprintf(
            "Created API key %d \"%s\" (%s). It is shown only now:\n",
            $created->key->id,
            $name,
            $scope->value,
        ));
        fwrite(STDOUT, $created->token . "\n");
        exit(0);

    case 'list':
        $username = $options['user'] ?? null;
        $list = $username === null ? $stored->listAll() : $keys->keysOf($userNamed($username));
        foreach ($list as $key) {
            fwrite(STDOUT, sprintf(
                "%d\t%s\t%s\t%s\tcreated %s\t%s\n",
                $key->id,
                $users->find($key->userId)->username ?? '?',
                $key->name,
                $key->scope->value,
                $key->createdAt->format('Y-m-d H:i') . ' UTC',
                match (true) {
                    $key->isRevoked() => 'revoked ' . $key->revokedAt?->format('Y-m-d H:i') . ' UTC',
                    $key->lastUsedAt !== null => 'last used ' . $key->lastUsedAt->format('Y-m-d H:i') . ' UTC',
                    default => 'never used',
                },
            ));
        }
        if ($list === []) {
            fwrite(STDERR, "No API keys.\n");
        }
        exit(0);

    case 'revoke':
        $id = $args[2] ?? '';
        $key = ctype_digit($id) ? $stored->find((int) $id) : null;
        $owner = $key === null ? null : $users->find($key->userId);
        if ($key === null || $owner === null) {
            fwrite(STDERR, sprintf("No API key %s.\n", $id));
            exit(1);
        }
        if (!$keys->revoke($owner, $key->id)) {
            fwrite(STDERR, sprintf("API key %d \"%s\" was already revoked.\n", $key->id, $key->name));
            exit(1);
        }
        fwrite(STDOUT, sprintf("Revoked API key %d \"%s\".\n", $key->id, $key->name));
        exit(0);

    default:
        fwrite(STDERR, $usage);
        exit(2);
}
