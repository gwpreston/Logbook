<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

/**
 * The sale pack's options (spec.md §7.19), from its plain GET form. As in
 * the print view, a hidden `options=1` marks the form as sent: until it is,
 * the defaults apply (due next and descriptions on, the timeline off, the
 * default paperwork kinds). Once sent, an absent box is unticked.
 *
 * Parsing is strict: a box is on only at `1`. Before the form is sent, a
 * link's `timeline=1` still turns that box on, and anything else (absent or
 * unknown) falls back to the default; once sent, anything else is off. `costs` and
 * `photo` (the cover page, Phase 21.1) are off unless exactly `1`, sent or not. Paperwork kinds are read only from
 * the kinds offered, so a forged `kinds[]=registration` names nothing;
 * `exclude[]` keeps positive integer ids only, and only ever takes files
 * away (PaperworkSelector). The *Choose files* form sends the files still
 * ticked instead (`choose=1` and `keep[]`), which works without JS: every
 * other file of the chosen kinds is then left out.
 */
final readonly class SalePackOptions
{
    /**
     * @param list<PaperworkKind> $kinds
     * @param list<int> $exclude attachment ids the seller unticked
     * @param list<int>|null $keep from the *Choose files* form: the ids still ticked
     */
    public function __construct(
        public bool $dueNext = true,
        public bool $descriptions = true,
        public bool $timeline = false,
        public bool $costs = false,
        public array $kinds = [],
        public array $exclude = [],
        public ?array $keep = null,
        public bool $photo = false,
    ) {
    }

    /**
     * @param array<array-key, mixed> $query
     * @param list<PaperworkKind> $offered the kinds whose module is on
     */
    public static function fromQuery(array $query, array $offered): self
    {
        $sent = ($query['options'] ?? '') === '1';
        $on = static fn (string $name, bool $default): bool => ($query[$name] ?? '') === '1' || (!$sent && $default);

        $picked = is_array($query['kinds'] ?? null) ? $query['kinds'] : [];
        $kinds = array_values(array_filter(
            $offered,
            static fn (PaperworkKind $kind): bool => $sent ? in_array($kind->value, $picked, true) : $kind->isDefault(),
        ));

        return new self(
            dueNext: $on('due', true),
            descriptions: $on('descriptions', true),
            timeline: $on('timeline', false),
            costs: ($query['costs'] ?? '') === '1',
            kinds: $kinds,
            exclude: self::ids($query['exclude'] ?? null),
            keep: ($query['choose'] ?? '') === '1' ? self::ids($query['keep'] ?? null) : null,
            photo: ($query['photo'] ?? '') === '1',
        );
    }

    /**
     * Whether the file goes in the ZIP: still ticked on the *Choose files*
     * form, else not unticked before.
     */
    public function keeps(int $attachmentId): bool
    {
        return $this->keep !== null
            ? in_array($attachmentId, $this->keep, true)
            : !in_array($attachmentId, $this->exclude, true);
    }

    public function includes(PaperworkKind $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }

    /**
     * The same choice with these files left out (and no keep list), for
     * the links once the selection is known.
     *
     * @param list<int> $exclude
     */
    public function excluding(array $exclude): self
    {
        return new self(
            $this->dueNext,
            $this->descriptions,
            $this->timeline,
            $this->costs,
            $this->kinds,
            $exclude,
            photo: $this->photo,
        );
    }

    /**
     * Positive integer ids from a list parameter; anything else is dropped.
     *
     * @return list<int>
     */
    private static function ids(mixed $values): array
    {
        $ids = [];
        foreach (is_array($values) ? $values : [] as $id) {
            if (is_string($id) && preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }

    /**
     * The query that reproduces these options, for the pack's and the ZIP's
     * links (always sent, so nothing falls back to a default).
     *
     * @return array<string, string|list<string>>
     */
    public function query(): array
    {
        return array_filter([
            'options' => '1',
            'due' => $this->dueNext ? '1' : '',
            'descriptions' => $this->descriptions ? '1' : '',
            'timeline' => $this->timeline ? '1' : '',
            'costs' => $this->costs ? '1' : '',
            'photo' => $this->photo ? '1' : '',
            'kinds' => array_map(static fn (PaperworkKind $kind): string => $kind->value, $this->kinds),
            'exclude' => array_map(strval(...), $this->exclude),
        ], static fn (string|array $value): bool => $value !== '' && $value !== []);
    }
}
