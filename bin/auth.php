<?php

declare(strict_types=1);

/*
 * Sign-in from the command line (spec.md §7.9 *Break-glass*): a one-time
 * link for when single sign-on is down or password sign-in is off.
 *
 *   php bin/auth.php login-link <username>
 *
 * Prints the link alone on stdout. It signs that user in once, within ten
 * minutes, whatever AUTH_LOCAL_LOGIN says; a new link replaces their
 * earlier one. Making and using it are logged. APP_URL must be the address
 * you open Logbook at.
 *
 * Docker: docker compose exec -u www-data app php bin/auth.php login-link pat
 *
 * Exit code: 0 ok, 1 failed, 2 usage.
 */

use Logbook\Domain\User\Username;
use Logbook\Kernel;
use Logbook\Repository\UserRepository;
use Logbook\Service\Auth\LoginLinks;

require dirname(__DIR__) . '/vendor/autoload.php';

$usage = "Usage: php bin/auth.php login-link <username>\n";
// The command line, as strings (the $argv global is not guaranteed to be set).
$args = array_values(array_filter((array) ($_SERVER['argv'] ?? []), 'is_string'));
$command = $args[1] ?? '';
$username = $args[2] ?? '';

if ($command !== 'login-link' || $username === '' || count($args) > 3) {
    fwrite(STDERR, $usage);
    exit(2);
}

// The whole app, so the link can be built from its route (and APP_BASE_PATH).
$container = Kernel::createApp(Kernel::settings())->getContainer();
$users = $container->get(UserRepository::class);
$links = $container->get(LoginLinks::class);
assert($users instanceof UserRepository);
assert($links instanceof LoginLinks);

$user = $users->findByUsername(Username::normalise($username));
if ($user === null) {
    fwrite(STDERR, sprintf("No user named \"%s\".\n", $username));
    exit(1);
}
$created = $links->create($user);
if ($created === null) {
    fwrite(STDERR, sprintf("%s is disabled: enable them in Settings → Users first.\n", $user->username));
    exit(1);
}

fwrite(STDERR, sprintf("One-time sign-in link for %s, valid for 10 minutes:\n", $user->username));
fwrite(STDOUT, $created->url . "\n");
exit(0);
