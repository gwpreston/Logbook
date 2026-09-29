<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Maintenance\DueStatus;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Display\UserDisplayScope;
use Logbook\Support\I18n\ListMessage;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The tyre reminder's title (spec.md §7.6), in the owner's language and
 * units whoever asks (a page view or the scheduled task): what is due, wear
 * first. "Tyres: front left and front right worn", "Tyres: rear due in
 * about 800 mi", "Tyres: Winter wheels over 6 years old". Fitted tyres are
 * named by position; a whole set due for age by the set's name.
 */
final readonly class TyreReminderTitle
{
    public function __construct(
        private TyreService $tyres,
        private UserDisplayScope $scope,
        private TranslatorInterface $translator,
        private DisplayFormatter $formatter,
    ) {
    }

    public function title(User $user, Vehicle $vehicle, TyreVerdict $verdict): string
    {
        return $this->scope->run($user, function () use ($vehicle, $verdict): string {
            $message = $verdict->reason === TyreStanding::AGE
                ? $this->age($vehicle, $verdict)
                : $this->wear($verdict);

            return $message->trans($this->translator);
        });
    }

    private function wear(TyreVerdict $verdict): TranslatableMessage
    {
        $where = new ListMessage(array_values(array_filter(array_map(
            static fn (TyreStanding $s): ?TranslatableMessage => $s->view->tyre->position === null
                ? null
                : new TranslatableMessage('tyre.where.' . $s->view->tyre->position->value),
            $verdict->named,
        ))));

        return $verdict->isWorn()
            ? new TranslatableMessage('tyre.reminder.worn', ['where' => $where])
            : new TranslatableMessage('tyre.reminder.wear_due', [
                'where' => $where,
                'distance' => $this->formatter->aboutDistance($verdict->kmLeft()),
            ]);
    }

    private function age(Vehicle $vehicle, TyreVerdict $verdict): TranslatableMessage
    {
        $params = [
            'what' => $this->aged($vehicle, $verdict->named),
            'years' => $this->yearsOf($verdict),
        ];

        return $verdict->status === DueStatus::Overdue
            ? new TranslatableMessage('tyre.reminder.old', $params)
            : new TranslatableMessage('tyre.reminder.age_due', $params + ['date' => $this->formatter->date($verdict->dueOn)]);
    }

    /**
     * Whole sets by name, the other fitted tyres by position, stored ones by name.
     *
     * @param list<TyreStanding> $named
     */
    private function aged(Vehicle $vehicle, array $named): ListMessage
    {
        $namedIds = array_map(static fn (TyreStanding $s): int => $s->view->tyre->id, $named);
        $inUse = [];
        foreach ($this->tyres->tyres($vehicle) as $tyre) {
            if ($tyre->setId !== null && !$tyre->isRetired()) {
                $inUse[$tyre->setId][] = $tyre->id;
            }
        }
        $sets = TyreService::setsById($this->tyres->sets($vehicle));

        /** @var list<TranslatableInterface|string> $parts */
        $parts = [];
        $done = [];
        foreach ($named as $standing) {
            $tyre = $standing->view->tyre;
            $setId = $tyre->setId;
            if ($setId !== null && isset($sets[$setId]) && array_diff($inUse[$setId] ?? [], $namedIds) === []) {
                if (!isset($done[$setId])) {
                    $parts[] = $sets[$setId]->data->name;
                    $done[$setId] = true;
                }
                continue;
            }
            $parts[] = $tyre->isFitted() && $tyre->position !== null
                ? new TranslatableMessage('tyre.where.' . $tyre->position->value)
                : TyreSync::label($tyre);
        }

        return new ListMessage($parts);
    }

    private function yearsOf(TyreVerdict $verdict): int
    {
        $first = $verdict->named[0] ?? null;
        $made = $first?->view->tyre->data->dot?->manufacturedOn;
        $limit = $first?->view->ageLimitOn;

        return $made === null || $limit === null ? 0 : (int) $made->diff($limit)->y;
    }
}
