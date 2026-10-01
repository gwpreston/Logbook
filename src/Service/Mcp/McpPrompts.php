<?php

declare(strict_types=1);

namespace Logbook\Service\Mcp;

use Logbook\Service\Vehicle\VehicleNotFound;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Domain\User\User;
use Logbook\Support\Http\AbsoluteUrl;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The MCP prompts (spec.md §7.28 *Prompts*): each is one user message, in
 * the key user's language, naming the tools to call. `before_service` and
 * `sale_checklist` take a vehicle id; one the user can't see is refused
 * like one that doesn't exist.
 */
final readonly class McpPrompts
{
    /** Prompt name → whether it takes `vehicle`. */
    private const array PROMPTS = ['monthly_summary' => false, 'before_service' => true, 'sale_checklist' => true];

    public function __construct(
        private VehicleService $vehicles,
        private AbsoluteUrl $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $prompts = [];
        foreach (self::PROMPTS as $name => $takesVehicle) {
            $prompts[] = [
                'name' => $name,
                'title' => $this->translator->trans('mcp.prompt.' . $name . '.title'),
                'description' => $this->translator->trans('mcp.prompt.' . $name . '.description'),
                'arguments' => $takesVehicle ? [[
                    'name' => 'vehicle',
                    'description' => $this->translator->trans('mcp.prompt.vehicle_argument'),
                    'required' => true,
                ]] : [],
            ];
        }

        return $prompts;
    }

    /**
     * A `GetPromptResult`.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     * @throws McpError
     */
    public function get(User $user, string $name, array $arguments): array
    {
        if (!array_key_exists($name, self::PROMPTS)) {
            throw McpError::invalidParams(sprintf('Unknown prompt: %s', $name));
        }
        $parameters = [];
        if (self::PROMPTS[$name]) {
            $id = $arguments['vehicle'] ?? null;
            if (!is_string($id) || !ctype_digit($id) || strlen($id) > 10) {
                throw McpError::invalidParams('"vehicle" must be a vehicle id, as find_vehicles or logbook://vehicles gives it.');
            }
            try {
                $vehicle = $this->vehicles->get($user, (int) $id);
            } catch (VehicleNotFound) {
                throw McpError::invalidParams(sprintf('There is no vehicle %s.', $id));
            }
            $parameters = [
                'vehicle' => $vehicle->name(),
                'id' => (string) $vehicle->id,
                'link' => $this->urls->route('sale_pack.show', ['id' => (string) $vehicle->id]),
            ];
        }

        return [
            'description' => $this->translator->trans('mcp.prompt.' . $name . '.description'),
            'messages' => [[
                'role' => 'user',
                'content' => ['type' => 'text', 'text' => $this->translator->trans('mcp.prompt.' . $name . '.text', $parameters)],
            ]],
        ];
    }
}
