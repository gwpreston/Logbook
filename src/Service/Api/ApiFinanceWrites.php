<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Finance\PaymentEvent;
use Logbook\Domain\Finance\SettlementQuote;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Finance\AgreementAlreadyActive;
use Logbook\Service\Finance\FinanceAgreementForm;
use Logbook\Service\Finance\FinanceAgreementNotFound;
use Logbook\Service\Finance\FinanceEvents;
use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Api\ValidationProblem;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Validation\ValidationErrors;

/**
 * Finance writes over the API (Phase 39.2, spec.md §7.20, §7.32, #287):
 * agreements through the agreement form and its *one active agreement*
 * rule; payment events, settlement quotes and *End* through the
 * agreement page's rules (FinanceEvents). §7.32's access throughout (the
 * module on, `Manage` and `ViewCosts`, else 404), and the agreement
 * number is accepted but never returned.
 */
final readonly class ApiFinanceWrites
{
    /** The API reads numbers with "." whatever the user's locale. */
    private const string LOCALE = 'en';

    public function __construct(
        private FinanceService $finance,
        private FinanceEvents $events,
        private ApiEditor $editor,
        private ValidationProblem $validation,
    ) {
    }

    /**
     * An agreement as `GET …/finance/agreements` lists it, with its events and quotes.
     *
     * @return array<string, mixed>
     */
    public function read(User $user, Vehicle $vehicle, FinanceAgreement $agreement): array
    {
        $view = $this->finance->view($user, $vehicle, $agreement);

        return Serializer::financeAgreement($view) + [
            'events' => array_map(static fn (PaymentEvent $event): array => [
                'id' => $event->id,
                'kind' => $event->kind->value,
                'due_on' => Serializer::date($event->dueOn),
                'amount' => Serializer::dec($event->amount, Serializer::QUANTITY_SCALE),
                'paid_on' => Serializer::date($event->paidOn),
                'notes' => $event->notes,
            ], $view->events),
            'quotes' => array_map(static fn (SettlementQuote $quote): array => [
                'id' => $quote->id,
                'quoted_on' => Serializer::date($quote->quotedOn),
                'amount' => Serializer::dec($quote->amount, Serializer::QUANTITY_SCALE),
                'valid_until' => Serializer::date($quote->validUntil),
                'notes' => $quote->notes,
            ], $view->quotes),
        ];
    }

    /**
     * `POST …/finance/agreements`: one active agreement at a time (409 `finance_active_exists`).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws ApiProblem 404, 409, 422
     */
    public function create(User $user, Vehicle $vehicle, array $body): array
    {
        $this->guard($user, $vehicle);
        if ($this->finance->active($vehicle) !== null) {
            throw self::activeExists();
        }
        $mapped = JsonInput::agreement($body, $user->preferences);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $input = FinanceAgreementForm::parse($mapped['input'], $mapped['preferences']);
        if ($input instanceof ValidationErrors) {
            throw $this->validation->of($input);
        }
        try {
            $id = $this->finance->create($user, $vehicle, $input);
        } catch (AgreementAlreadyActive) {
            throw self::activeExists();
        }

        return $this->read($user, $vehicle, $this->agreement($user, $vehicle, $id));
    }

    /**
     * `PATCH …/agreements/{agreement}`: the edit form; the type is the agreement's own.
     *
     * @param array<string, mixed> $body
     * @return array{body: array<string, mixed>, tag: string}
     * @throws ApiProblem 404, 409, 412, 422
     */
    public function update(User $user, Vehicle $vehicle, int $id, array $body, ?string $ifMatch): array
    {
        $agreement = $this->agreement($user, $vehicle, $id);
        $this->guard($user, $vehicle);
        $this->editor->precondition($ifMatch, $agreement);
        if (array_key_exists('type', $body)) {
            $errors = new ValidationErrors();
            $errors->add('type', 'api.validation.unknown_field');
            throw $this->validation->of($errors);
        }
        $owner = $user->preferences;
        $units = new DisplayPreferences(
            $owner->locale,
            $owner->timezone,
            array_key_exists('start_odometer', $body) || array_key_exists('distance_unit', $body)
                ? $owner->distanceUnit
                : DistanceUnit::Kilometre,
            $owner->volumeUnit,
            $owner->consumptionUnit,
            $owner->currency,
        );
        $mapped = JsonInput::agreement($body, $units);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $stored = FinanceAgreementForm::values($agreement, $mapped['preferences'])
            + ['set_purchase_price' => '', 'clear_purchase_price' => ''];
        $input = FinanceAgreementForm::parse(
            JsonInput::overlay($stored, $body, $mapped['input'], JsonInput::AGREEMENT_FIELDS),
            $mapped['preferences'],
        );
        if ($input instanceof ValidationErrors) {
            throw $this->validation->of($input);
        }
        $this->finance->update($user, $vehicle, $agreement, $input);
        $updated = $this->agreement($user, $vehicle, $id);

        return ['body' => $this->read($user, $vehicle, $updated), 'tag' => $this->editor->tag($updated)];
    }

    /**
     * `POST …/agreements/{agreement}/payments`: missed, paid late or extra (#299).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed> the agreement
     * @throws ApiProblem 404, 409, 422
     */
    public function payment(User $user, Vehicle $vehicle, int $id, array $body): array
    {
        $agreement = $this->open($user, $vehicle, $id);
        $input = JsonInput::form($body, JsonInput::PAYMENT_FIELDS);
        if ($input instanceof ValidationErrors) {
            throw $this->validation->of($input);
        }
        $done = $this->events->payment($user, $vehicle, $agreement, $input, self::LOCALE);
        if ($done instanceof ValidationErrors) {
            throw $this->validation->of($done);
        }

        return $this->read($user, $vehicle, $agreement);
    }

    /**
     * `DELETE …/payments/{event}`: a missed mark goes with its paid-late mark, as on the page.
     *
     * @throws ApiProblem 404, 409
     */
    public function deletePayment(User $user, Vehicle $vehicle, int $id, int $eventId, ?string $ifMatch = null): void
    {
        $agreement = $this->open($user, $vehicle, $id);
        $events = $this->finance->view($user, $vehicle, $agreement)->events;
        $event = array_values(array_filter($events, static fn (PaymentEvent $e): bool => $e->id === $eventId))[0]
            ?? throw ApiProblem::notFound('The agreement has no such payment event.');
        $this->editor->precondition($ifMatch, $event);
        try {
            $this->finance->deleteEvent($user, $vehicle, $agreement, $eventId);
        } catch (FinanceAgreementNotFound) {
            throw ApiProblem::notFound('The agreement has no such payment event.');
        }
    }

    /**
     * `POST …/agreements/{agreement}/quotes`: HP, PCP and loans only (404 otherwise, as the page).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed> the agreement
     * @throws ApiProblem 404, 409, 422
     */
    public function quote(User $user, Vehicle $vehicle, int $id, array $body): array
    {
        $agreement = $this->credit($user, $vehicle, $id);
        $input = JsonInput::form($body, JsonInput::QUOTE_FIELDS);
        if ($input instanceof ValidationErrors) {
            throw $this->validation->of($input);
        }
        $refused = $this->events->quote($user, $vehicle, $agreement, $input, self::LOCALE);
        if ($refused !== null) {
            throw $this->validation->of(JsonInput::renamed($refused, JsonInput::QUOTE_FIELDS));
        }

        return $this->read($user, $vehicle, $agreement);
    }

    /**
     * `DELETE …/quotes/{quote}`.
     *
     * @throws ApiProblem 404, 409
     */
    public function deleteQuote(User $user, Vehicle $vehicle, int $id, int $quoteId, ?string $ifMatch = null): void
    {
        $agreement = $this->credit($user, $vehicle, $id);
        $quotes = $this->finance->view($user, $vehicle, $agreement)->quotes;
        $quote = array_values(array_filter($quotes, static fn (SettlementQuote $q): bool => $q->id === $quoteId))[0]
            ?? throw ApiProblem::notFound('The agreement has no such quote.');
        $this->editor->precondition($ifMatch, $quote);
        $this->finance->deleteQuote($user, $vehicle, $agreement, $quoteId);
    }

    /**
     * `POST …/agreements/{agreement}/end`: *End* while the vehicle stays
     * (settled early, completed, handed back, lease ended); leaving with the
     * vehicle goes through `POST /vehicles/{id}/archive` (§7.32 *Ending*).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed> the agreement
     * @throws ApiProblem 404, 409, 422
     */
    public function end(User $user, Vehicle $vehicle, int $id, array $body): array
    {
        $agreement = $this->open($user, $vehicle, $id);
        $input = JsonInput::form($body, JsonInput::END_FIELDS);
        if ($input instanceof ValidationErrors) {
            throw $this->validation->of($input);
        }
        $view = $this->finance->view($user, $vehicle, $agreement);
        $done = $this->events->end($user, $vehicle, $agreement, $view, $input, self::LOCALE);
        if ($done instanceof ValidationErrors) {
            throw $this->validation->of($done);
        }

        return $this->read($user, $vehicle, $this->agreement($user, $vehicle, $id));
    }

    /**
     * §7.32's access (else 404, as the reads), on an active vehicle.
     *
     * @throws ApiProblem 404, 409
     */
    private function guard(User $user, Vehicle $vehicle): void
    {
        if (!$this->finance->canSee($user, $vehicle)) {
            throw ApiProblem::notFound('There is no finance for this vehicle that the key\'s user may see.');
        }
        ApiWriter::assertActive($vehicle);
    }

    private function agreement(User $user, Vehicle $vehicle, int $id): FinanceAgreement
    {
        try {
            return $this->finance->get($user, $vehicle, $id);
        } catch (FinanceAgreementNotFound) {
            throw ApiProblem::notFound('The vehicle has no such agreement.');
        }
    }

    /**
     * An agreement still running (payments, events and *End* need one).
     *
     * @throws ApiProblem 404, 409 `finance_ended`
     */
    private function open(User $user, Vehicle $vehicle, int $id): FinanceAgreement
    {
        $agreement = $this->agreement($user, $vehicle, $id);
        $this->guard($user, $vehicle);
        if (!FinanceService::isOpen($agreement)) {
            throw new ApiProblem(409, 'finance_ended', 'This agreement has ended; its payments can no longer change.');
        }

        return $agreement;
    }

    private function credit(User $user, Vehicle $vehicle, int $id): FinanceAgreement
    {
        $agreement = $this->agreement($user, $vehicle, $id);
        $this->guard($user, $vehicle);
        if (!$agreement->type()->isCredit()) {
            throw ApiProblem::notFound('A lease has no settlement quotes.');
        }

        return $agreement;
    }

    private static function activeExists(): ApiProblem
    {
        return new ApiProblem(
            409,
            'finance_active_exists',
            'This vehicle already has an active agreement; end it before adding another.',
        );
    }
}
