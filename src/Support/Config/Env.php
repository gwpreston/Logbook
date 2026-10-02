<?php

declare(strict_types=1);

namespace Logbook\Support\Config;

use InvalidArgumentException;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Typed, read-only view over environment variables.
 *
 * Precedence (highest first): real process environment, then values loaded
 * from `.env`. A variable set to the empty string counts as "not set" so the
 * documented default applies.
 */
final readonly class Env
{
    /**
     * @param array<string, string> $vars
     */
    public function __construct(private array $vars)
    {
    }

    /**
     * Build from the process environment, optionally layering a `.env` file
     * underneath it.
     */
    public static function fromSystem(?string $dotenvFile = null): self
    {
        $sources = [];

        if ($dotenvFile !== null && is_file($dotenvFile) && is_readable($dotenvFile)) {
            $contents = file_get_contents($dotenvFile);
            if ($contents !== false) {
                $sources[] = (new Dotenv())->parse($contents, $dotenvFile);
            }
        }

        // Later sources win: the real environment overrides `.env`.
        array_push($sources, $_SERVER, $_ENV, getenv());

        $vars = [];
        foreach ($sources as $source) {
            foreach ($source as $name => $value) {
                // HTTP_* entries in $_SERVER are request headers, never config.
                if (is_string($name) && is_string($value) && !str_starts_with($name, 'HTTP_')) {
                    $vars[$name] = $value;
                }
            }
        }

        return new self($vars);
    }

    /**
     * @param array<string, string> $overrides
     */
    public function with(array $overrides): self
    {
        return new self(array_merge($this->vars, $overrides));
    }

    /**
     * Every variable, by name (the job output redactor reads their values).
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->vars;
    }

    public function has(string $name): bool
    {
        return isset($this->vars[$name]) && $this->vars[$name] !== '';
    }

    public function string(string $name, string $default = ''): string
    {
        return $this->has($name) ? trim($this->vars[$name]) : $default;
    }

    public function nullableString(string $name): ?string
    {
        return $this->has($name) ? trim($this->vars[$name]) : null;
    }

    public function int(string $name, int $default): int
    {
        if (!$this->has($name)) {
            return $default;
        }

        $value = filter_var(trim($this->vars[$name]), FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new InvalidArgumentException(sprintf('Environment variable %s must be an integer.', $name));
        }

        return $value;
    }

    public function bool(string $name, bool $default): bool
    {
        if (!$this->has($name)) {
            return $default;
        }

        $value = filter_var(trim($this->vars[$name]), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new InvalidArgumentException(sprintf('Environment variable %s must be a boolean.', $name));
        }

        return $value;
    }
}
