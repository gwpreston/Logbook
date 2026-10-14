<?php

declare(strict_types=1);

namespace Logbook\Support;

/**
 * How a headline figure was worked out, in plain words (spec.md §8 *Show the
 * working*): the dated inputs, the sum with its real numbers, and what
 * Logbook did not assume. Built by the service that works the figure out,
 * from the values it used, so every surface (page, print, API, Ask, MCP)
 * says the same thing.
 */
final readonly class Working
{
    /**
     * @param list<WorkingInput> $inputs each with its date and the row it came from
     * @param list<string> $steps the sum in words, one sentence per step
     * @param list<string> $notes what Logbook did not assume
     * @param string $result the figure as shown ("£560")
     * @param ?string $resultValue the figure as a canonical decimal, for checks and the API; null when it isn't a number
     */
    public function __construct(
        public array $inputs,
        public array $steps,
        public array $notes = [],
        public string $result = '',
        public ?string $resultValue = null,
    ) {
    }

    /**
     * For the API, Ask and MCP. Links are left out: they are for pages.
     *
     * @return array{
     *     inputs: list<array{
     *         label: string,
     *         value: string,
     *         as_of: ?array{date: string, kind: string, basis: ?string, stale: bool},
     *     }>,
     *     steps: list<string>,
     *     notes: list<string>,
     *     result: string,
     *     result_value: ?string,
     * }
     */
    public function toArray(): array
    {
        return [
            'inputs' => array_map(
                static fn (WorkingInput $input): array => [
                    'label' => $input->label,
                    'value' => $input->value,
                    'as_of' => $input->asOf?->toArray(),
                ],
                $this->inputs,
            ),
            'steps' => $this->steps,
            'notes' => $this->notes,
            'result' => $this->result,
            'result_value' => $this->resultValue,
        ];
    }
}
