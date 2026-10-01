<?php

declare(strict_types=1);

namespace Logbook\Support\Config;

use InvalidArgumentException;
use Logbook\Domain\Auth\ProxyAuthMode;
use Logbook\Domain\Auth\ProxyLinkMode;
use Logbook\Support\Net\IpRange;

/**
 * Header sign-in settings (spec.md §7.9, §9 `AUTH_PROXY_*`). Off unless a
 * header is set. Anything half-set stops the app at start with a message
 * naming the variable, rather than trusting more than was meant: above
 * all a plain header without `AUTH_PROXY_TRUSTED`.
 */
final readonly class ProxyAuthConfig
{
    /** HS256 needs a 256-bit key (and firebase/php-jwt refuses a shorter one). */
    public const int MIN_SECRET_LENGTH = 32;

    /**
     * @param list<IpRange> $trusted
     * @param list<string> $allowedGroups
     * @param list<string> $adminGroups
     */
    public function __construct(
        public ProxyAuthMode $mode = ProxyAuthMode::Off,
        /** The plain username header, or the JWT header: as configured, e.g. "Remote-User". */
        public string $header = '',
        public array $trusted = [],
        public string $nameHeader = '',
        public string $emailHeader = '',
        public string $groupsHeader = '',
        public string $jwtSecret = '',
        /** Exactly as configured: compared byte for byte with `iss`. */
        public string $jwtIssuer = '',
        public string $jwtAudience = '',
        public ProxyLinkMode $link = ProxyLinkMode::Username,
        public bool $autoCreate = false,
        public array $allowedGroups = [],
        public array $adminGroups = [],
        public ?string $logoutUrl = null,
    ) {
    }

    public static function fromEnv(Env $env): self
    {
        $plain = $env->string('AUTH_PROXY_HEADER');
        $jwt = $env->string('AUTH_PROXY_JWT_HEADER');
        if ($plain === '' && $jwt === '') {
            return new self();
        }
        if ($plain !== '' && $jwt !== '') {
            throw new InvalidArgumentException(
                'AUTH_PROXY_HEADER and AUTH_PROXY_JWT_HEADER are both set: header sign-in uses one or the other.',
            );
        }
        $mode = $plain !== '' ? ProxyAuthMode::Header : ProxyAuthMode::Jwt;
        $header = self::headerName($plain !== '' ? 'AUTH_PROXY_HEADER' : 'AUTH_PROXY_JWT_HEADER', $plain !== '' ? $plain : $jwt);

        $trusted = [];
        foreach (self::list($env->string('AUTH_PROXY_TRUSTED'), '/\s*,\s*/') as $entry) {
            try {
                $trusted[] = IpRange::parse($entry);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException('AUTH_PROXY_TRUSTED: ' . $e->getMessage(), 0, $e);
            }
        }
        if ($mode === ProxyAuthMode::Header && $trusted === []) {
            throw new InvalidArgumentException(
                'AUTH_PROXY_HEADER is set but AUTH_PROXY_TRUSTED is empty: list the address or range your sign-in '
                . 'proxy connects from (e.g. 172.18.0.0/16), or anyone could send the header. See docs/sso.md.',
            );
        }

        if ($mode === ProxyAuthMode::Jwt) {
            foreach (['AUTH_PROXY_JWT_SECRET', 'AUTH_PROXY_JWT_ISSUER', 'AUTH_PROXY_JWT_AUDIENCE'] as $required) {
                if (!$env->has($required)) {
                    throw new InvalidArgumentException(sprintf('%s must be set when AUTH_PROXY_JWT_HEADER is set.', $required));
                }
            }
            if (strlen($env->string('AUTH_PROXY_JWT_SECRET')) < self::MIN_SECRET_LENGTH) {
                throw new InvalidArgumentException(sprintf(
                    'AUTH_PROXY_JWT_SECRET is shorter than %d characters: use the proxy provider\'s client secret.',
                    self::MIN_SECRET_LENGTH,
                ));
            }
        } else {
            foreach (['AUTH_PROXY_JWT_SECRET', 'AUTH_PROXY_JWT_ISSUER', 'AUTH_PROXY_JWT_AUDIENCE'] as $unused) {
                if ($env->has($unused)) {
                    throw new InvalidArgumentException(sprintf('%s is set but AUTH_PROXY_JWT_HEADER is not.', $unused));
                }
            }
        }

        $link = ProxyLinkMode::tryFrom(strtolower($env->string('AUTH_PROXY_LINK', ProxyLinkMode::Username->value)));
        if ($link === null) {
            throw new InvalidArgumentException(sprintf(
                'AUTH_PROXY_LINK "%s" is invalid (expected identity or username).',
                $env->string('AUTH_PROXY_LINK'),
            ));
        }
        $logoutUrl = $env->nullableString('AUTH_PROXY_LOGOUT_URL');
        if ($logoutUrl !== null && preg_match('~^https?://[^/\s?#]+([/?#]\S*)?$~i', $logoutUrl) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'AUTH_PROXY_LOGOUT_URL "%s" is not an http(s) URL (expected e.g. https://auth.example.com/logout).',
                $logoutUrl,
            ));
        }
        $optionalHeader = static fn (string $name): string => $env->has($name)
            ? self::headerName($name, $env->string($name))
            : '';

        return new self(
            mode: $mode,
            header: $header,
            trusted: $trusted,
            // The JWT carries these as claims; plain headers beside it are never read.
            nameHeader: $mode === ProxyAuthMode::Header ? $optionalHeader('AUTH_PROXY_NAME_HEADER') : '',
            emailHeader: $mode === ProxyAuthMode::Header ? $optionalHeader('AUTH_PROXY_EMAIL_HEADER') : '',
            groupsHeader: $mode === ProxyAuthMode::Header ? $optionalHeader('AUTH_PROXY_GROUPS_HEADER') : '',
            jwtSecret: $env->string('AUTH_PROXY_JWT_SECRET'),
            jwtIssuer: $env->string('AUTH_PROXY_JWT_ISSUER'),
            jwtAudience: $env->string('AUTH_PROXY_JWT_AUDIENCE'),
            link: $link,
            autoCreate: $env->bool('AUTH_PROXY_AUTO_CREATE', false),
            allowedGroups: self::list($env->string('AUTH_PROXY_ALLOWED_GROUPS'), '/\s*,\s*/'),
            adminGroups: self::list($env->string('AUTH_PROXY_ADMIN_GROUPS'), '/\s*,\s*/'),
            logoutUrl: $logoutUrl,
        );
    }

    public function isEnabled(): bool
    {
        return $this->mode !== ProxyAuthMode::Off;
    }

    /**
     * Whether the connecting address passes: in the trusted list, or (JWT
     * only) any address when there is no list.
     */
    public function trusts(string $address): bool
    {
        if ($this->trusted === []) {
            return $this->mode === ProxyAuthMode::Jwt;
        }

        return IpRange::anyContains($this->trusted, $address);
    }

    /**
     * Whether the address is a listed proxy (not merely allowed because
     * there is no list): only then can a missing header be its mistake.
     */
    public function isListedProxy(string $address): bool
    {
        return $this->trusted !== [] && IpRange::anyContains($this->trusted, $address);
    }

    /**
     * The identity issuer for a plain header: its name, lower-cased.
     */
    public function headerIssuer(): string
    {
        return strtolower($this->header);
    }

    /**
     * A header name of letters, digits and dashes only. An underscore is
     * refused: PHP reads `Remote_User` and `Remote-User` as the same header.
     */
    private static function headerName(string $variable, string $value): string
    {
        if (preg_match('/^[A-Za-z0-9]+(-[A-Za-z0-9]+)*$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '%s "%s" is not a header name (letters, digits and dashes, e.g. Remote-User).',
                $variable,
                $value,
            ));
        }

        return $value;
    }

    /**
     * @param non-empty-string $separator a regular expression
     * @return list<string>
     */
    private static function list(string $value, string $separator): array
    {
        $items = preg_split($separator, trim($value), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_map('trim', $items === false ? [] : $items)));
    }
}
