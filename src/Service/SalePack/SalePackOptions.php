<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

/**
 * The sale pack's options (spec.md §7.19), from its plain GET form. As in
 * the print view, a hidden `options=1` marks the form as sent: until it is,
 * the defaults apply (due next and descriptions on, the timeline off, the
 * default paperwork kinds). Once sent, an absent box is unticked.
 *
 * Parsing is strict: a box is on only at `1`, and anything else falls back
 * to off (or, before the form is sent, to its default). `costs` is off
 * unless it is exactly `1`, sent or not. Paperwork kinds are read only from
 * the kinds offered, so a forged `kinds[]=registration` names nothing;
 * `exclude[]` keeps positive integer ids only, and only ever takes files
 * away (PaperworkSelector).
 */
final readonly class SalePackOptions
{
    /**
     * @param list<PaperworkKind> $kinds
     * @param list<int> $exclude attachment ids the seller unticked
     */
    public function __construct(
        public bool $dueNext = true,
        public bool $descriptions = true,
        public bool $timeline = false,
        public bool $costs = false,
        public array $kinds = [],
        public array $exclude = [],
    ) {
    }

    /**
     * @param array<array-key, mixed> $query
     * @param list<PaperworkKind> $offered the kinds whose module is on
     */
    public static function fromQuery(array $query, array $offered): self
    {
        $sent = ($query['options'] ?? '') === '1';
        $on = static fn (string $name, bool $default): bool => $sent ? ($query[$name] ?? '') === '1' : $default;

        $picked = is_array($query['kinds'] ?? null) ? $query['kinds'] : [];
        $kinds = array_values(array_filter(
            $offered,
            static fn (PaperworkKind $kind): bool => $sent ? in_array($kind->value, $picked, true) : $kind->isDefault(),
        ));

        $exclude = [];
        foreach (is_array($query['exclude'] ?? null) ? $query['exclude'] : [] as $id) {
            if (is_string($id) && preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1) {
                $exclude[(int) $id] = (int) $id;
            }
        }

        return new self(
            dueNext: $on('due', true),
            descriptions: $on('descriptions', true),
            timeline: $on('timeline', false),
            costs: ($query['costs'] ?? '') === '1',
            kinds: $kinds,
            exclude: array_values($exclude),
        );
    }

    public function includes(PaperworkKind $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }

    /**
     * The same choice with other files left out (the *Choose files* form).
     *
     * @param list<int> $exclude
     */
    public function excluding(array $exclude): self
    {
        return new self($this->dueNext, $this->descriptions, $this->timeline, $this->costs, $this->kinds, $exclude);
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
            'kinds' => array_map(static fn (PaperworkKind $kind): string => $kind->value, $this->kinds),
            'exclude' => array_map(strval(...), $this->exclude),
        ], static fn (string|array $value): bool => $value !== '' && $value !== []);
    }
}
