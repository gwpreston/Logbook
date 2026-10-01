<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Logbook\Domain\Ai\AdapterType;
use Logbook\Domain\Ai\AiConnection;
use Logbook\Domain\Ai\AiModel;
use Logbook\Domain\Ai\AiTaskAssignment;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\ConnectionSettings;
use Logbook\Domain\Ai\Location;
use Logbook\Domain\User\User;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Repository\AiModelRepository;
use Logbook\Repository\AiSecretRepository;
use Logbook\Repository\AiTaskRepository;
use Logbook\Repository\SettingRepository;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Net\IpRange;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * What Settings → AI does (spec.md §7.25): connections with their secrets
 * and acknowledgement, models, task routing and this server's addresses.
 * Admins only; the routes check that.
 */
final readonly class AiAdmin
{
    public const int MAX_REQUEST_MB = 50;
    public const int MAX_OUTPUT_TOKENS = 32768;

    /** Headers Logbook sets itself. */
    private const array RESERVED_HEADERS = ['content-type', 'content-length', 'host', 'user-agent', 'accept'];

    public function __construct(
        private AiConnectionRepository $connections,
        private AiSecretRepository $secrets,
        private AiModelRepository $models,
        private AiTaskRepository $tasks,
        private SettingRepository $settings,
        private SecretBox $box,
        private ConnectionLocator $locator,
        private AppSettings $app,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The form's values for a saved connection, or a new one's defaults.
     * Never a secret.
     *
     * @return array<string, string>
     */
    public static function values(?AiConnection $connection): array
    {
        if ($connection === null) {
            return [
                'preset' => ConnectionPreset::Ollama->value,
                'adapter' => AdapterType::Ollama->value,
                'base_url' => ConnectionPreset::Ollama->url(),
                'verify_tls' => '1',
                'max_request_mb' => '8',
                'enabled' => '1',
            ];
        }

        return [
            'name' => $connection->name,
            'adapter' => $connection->adapter->value,
            'base_url' => $connection->baseUrl,
            'timeout_seconds' => (string) $connection->timeoutSeconds,
            'verify_tls' => $connection->verifyTls ? '1' : '',
            'ca_bundle' => $connection->caBundle ?? '',
            'max_request_mb' => (string) $connection->maxRequestMb,
            'monthly_token_cap' => $connection->monthlyTokenCap === null ? '' : (string) $connection->monthlyTokenCap,
            'enabled' => $connection->enabled ? '1' : '',
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public function parse(array $input, ?AiConnection $existing, string $locale): ConnectionForm|ValidationErrors
    {
        $v = new Validator($input, $locale);
        $name = $v->string('name', true, 100);
        $adapter = $v->enum('adapter', AdapterType::class, true);
        $url = $this->url($v);
        $timeout = $v->integer('timeout_seconds', false, 5, 600);
        $verifyTls = $v->checkbox('verify_tls') || !$this->app->ai->allowInsecureTls;
        $caBundle = $v->string('ca_bundle', false, 500);
        if ($caBundle !== null && !is_readable($caBundle)) {
            $v->addError('ca_bundle', 'ai.connection.error.ca_bundle');
        }
        $maxMb = $v->integer('max_request_mb', false, 1, self::MAX_REQUEST_MB) ?? 8;
        $cap = $v->integer('monthly_token_cap', false, 1, PHP_INT_MAX);

        $apiKey = self::secret($input['api_key'] ?? null);
        if ($apiKey !== null && !$this->box->canStore($apiKey)) {
            $v->addError('api_key', 'ai.connection.error.no_session_secret');
        }
        $headers = $this->headers($v, $input['headers'] ?? null);
        $remove = array_values(array_filter(
            is_array($input['remove_headers'] ?? null) ? $input['remove_headers'] : [],
            static fn (mixed $name): bool => is_string($name) && in_array($name, $existing->headerNames ?? [], true),
        ));

        if (!$v->errors()->isEmpty() || $name === null || !$adapter instanceof AdapterType || $url === null) {
            return $v->errors();
        }

        $location = $this->locator->locate($url)->location;
        $names = array_values(array_unique([
            ...array_diff($existing->headerNames ?? [], $remove),
            ...array_keys($headers),
        ]));

        return new ConnectionForm(
            settings: new ConnectionSettings(
                name: $name,
                adapter: $adapter,
                baseUrl: $url,
                location: $location,
                headerNames: $names,
                timeoutSeconds: $timeout ?? $location->defaultTimeout(),
                verifyTls: $verifyTls,
                caBundle: $caBundle,
                maxRequestMb: $maxMb,
                monthlyTokenCap: $cap,
                enabled: $v->checkbox('enabled'),
            ),
            apiKey: $apiKey,
            removeApiKey: $v->checkbox('remove_api_key'),
            headers: $headers,
            removeHeaders: $remove,
            acknowledge: $v->checkbox('acknowledge'),
        );
    }

    /**
     * Save a connection and its secrets; tick the acknowledgement when
     * given for an *Internet* one. Returns its id.
     */
    public function save(User $admin, ?AiConnection $existing, ConnectionForm $form): int
    {
        $now = $this->clock->now();
        if ($existing === null) {
            $id = $this->connections->insert($form->settings, $now);
        } else {
            $id = $existing->id;
            $this->connections->update($id, $form->settings, $now);
        }

        if ($form->removeApiKey && $form->apiKey === null) {
            $this->secrets->remove($id, AdapterFactory::API_KEY);
        }
        if ($form->apiKey !== null) {
            $this->secrets->put($id, AdapterFactory::API_KEY, $this->box->store($form->apiKey), $now);
        }
        foreach ($form->removeHeaders as $name) {
            if (!isset($form->headers[$name])) {
                $this->secrets->remove($id, AdapterFactory::HEADER . $name);
            }
        }
        foreach ($form->headers as $name => $value) {
            $this->secrets->put($id, AdapterFactory::HEADER . $name, $this->box->store($value), $now);
        }

        if ($form->acknowledge && $form->settings->location === Location::Internet) {
            $this->connections->acknowledge($id, $admin->id, $form->settings->baseUrl, $now);
        }

        return $id;
    }

    public function acknowledge(User $admin, AiConnection $connection): void
    {
        $this->connections->acknowledge($connection->id, $admin->id, $connection->baseUrl, $this->clock->now());
    }

    public function delete(AiConnection $connection): void
    {
        $this->connections->delete($connection->id);
    }

    /**
     * Which secrets a connection has, never their values: slot => `saved`,
     * `unreadable` (sealed with another SESSION_SECRET) or the `env:`
     * variable's name, with `unset` when it is not set.
     *
     * @return array<string, array{state: string, variable: ?string}>
     */
    public function secretStates(AiConnection $connection): array
    {
        $states = [];
        foreach ($this->secrets->forConnection($connection->id) as $slot => $stored) {
            $variable = SecretBox::variable($stored);
            try {
                $this->box->open($slot, $stored);
                $state = $variable === null ? 'saved' : 'env';
            } catch (SecretUnreadable) {
                $state = $variable === null ? 'unreadable' : 'unset';
            }
            $states[$slot] = ['state' => $state, 'variable' => $variable];
        }

        return $states;
    }

    /**
     * Add a model to the connection by name (typed, or picked from the list).
     */
    public function addModel(AiConnection $connection, string $name): ?int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 200 || preg_match('/[\x00-\x1f]/', $name) === 1) {
            return null;
        }

        return $this->models->add($connection->id, $name, $this->clock->now());
    }

    public function removeModel(AiModel $model): void
    {
        $this->tasks->unassignModel($model->id);
        $this->models->remove($model, $this->clock->now());
    }

    /**
     * Save a model's ticked capabilities. Tasks it no longer suits are
     * unassigned (their features switch off and say so).
     *
     * @param list<Capability> $capabilities
     */
    public function setCapabilities(AiModel $model, array $capabilities): void
    {
        $this->models->setCapabilities($model->id, $capabilities, $this->clock->now());
        $updated = $this->models->find($model->id);
        foreach ($this->tasks->all() as $assignment) {
            if ($assignment->modelId === $model->id && $updated !== null && !$assignment->task->accepts($updated)) {
                $this->tasks->unassign($assignment->task);
            }
        }
    }

    /**
     * Save the task routing: each task's model (or none) and settings.
     *
     * @param array<array-key, mixed> $input
     */
    public function saveTasks(array $input, string $locale): ?ValidationErrors
    {
        $v = new Validator($input, $locale);
        $plan = [];
        foreach (AiTaskName::cases() as $task) {
            $field = 'model_' . $task->value;
            $modelId = $v->integer($field, false, 1);
            $temperature = $v->decimal('temperature_' . $task->value, false, 2, '0', '2', 1);
            $maxTokens = $v->integer('max_tokens_' . $task->value, false, 1, self::MAX_OUTPUT_TOKENS);
            if ($modelId === null) {
                $plan[] = [$task, null];
                continue;
            }
            $model = $this->models->find($modelId);
            if ($model === null || !$model->added) {
                $v->addError($field, 'validation.choice');
                continue;
            }
            if (!$task->accepts($model)) {
                $v->addError($field, 'ai.task.error.capabilities');
                continue;
            }
            $plan[] = [$task, new AiTaskAssignment($task, $model->id, $temperature, $maxTokens)];
        }
        if (!$v->errors()->isEmpty()) {
            return $v->errors();
        }

        foreach ($plan as [$task, $assignment]) {
            if ($assignment === null) {
                $this->tasks->unassign($task);
            } else {
                $this->tasks->assign($assignment, $this->clock->now());
            }
        }

        return null;
    }

    /**
     * Save *This server's addresses*: one address, range or name per line
     * or comma.
     */
    public function saveThisHost(string $text): ?ValidationErrors
    {
        $errors = new ValidationErrors();
        $items = [];
        foreach (preg_split('/[\s,]+/', $text) ?: [] as $item) {
            $item = strtolower(trim($item));
            if ($item === '') {
                continue;
            }
            try {
                IpRange::parse($item);
            } catch (InvalidArgumentException) {
                if (preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $item) !== 1) {
                    $errors->add('this_host', 'ai.this_host.error', ['value' => mb_substr($item, 0, 60)]);
                    continue;
                }
            }
            $items[] = $item;
        }
        if (!$errors->isEmpty()) {
            return $errors;
        }
        $this->settings->save(ConnectionLocator::THIS_HOST, array_values(array_unique($items)));

        return null;
    }

    /**
     * A base URL: http(s), a host, no credentials, query or fragment;
     * stored without a trailing slash.
     */
    private function url(Validator $v): ?string
    {
        $url = $v->string('base_url', true, 500);
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);
        if (
            $parts === false
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        ) {
            $v->addError('base_url', 'ai.connection.error.url');

            return null;
        }

        return rtrim($url, '/');
    }

    /**
     * "Name: value" lines for new or replaced headers.
     *
     * @return array<string, string>
     */
    private function headers(Validator $v, mixed $text): array
    {
        $headers = [];
        foreach (preg_split('/\R/', is_string($text) ? $text : '') ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $line, 2) + [1 => '']);
            if (
                preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,99}$/', $name) !== 1
                || in_array(strtolower($name), self::RESERVED_HEADERS, true)
                || $value === ''
            ) {
                $v->addError('headers', 'ai.connection.error.header', ['line' => mb_substr($name, 0, 40)]);
                continue;
            }
            if (!$this->box->canStore($value)) {
                $v->addError('headers', 'ai.connection.error.no_session_secret');
                continue;
            }
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * A typed secret, as typed apart from surrounding space; null when blank
     * (keep the saved one).
     */
    private static function secret(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' || str_contains($value, "\0") ? null : $value;
    }
}
