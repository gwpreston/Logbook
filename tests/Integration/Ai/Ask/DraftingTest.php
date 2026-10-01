<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask;

use Logbook\Domain\Ai\Draft\DraftState;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\AiDraftRepository;
use Logbook\Service\Ai\Draft\DraftStore;
use Logbook\Service\Fuel\FuelService;
use Logbook\Tests\Support\AskTestCase;
use Logbook\Tests\Support\ScriptedProvider as Script;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Drafting entries (spec.md §7.26 *Drafting entries*): a sentence becomes a
 * validated card with Logbook's own figures, and nothing is written until
 * the card's *Add*.
 */
final class DraftingTest extends AskTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private User $owner;
    private Vehicle $bmw;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->app, $this->owner] = $this->askApp();
        $this->bmw = $this->vehicle($this->app, 'BMW', '320i');
    }

    /**
     * Ask a question whose model turn calls one tool, then answers.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> the tool's reply to the model
     */
    private function draft(string $tool, array $arguments, string $question = 'Log it'): array
    {
        $this->provider->queue(Script::tools([$tool, $arguments]), Script::answer('Check the card and press Add.'));
        $posted = $this->browserFor($this->app, 'owner')->post('/ask', ['question' => $question]);
        self::assertSame(303, $posted->getStatusCode());
        $replies = $this->toolReplies(count($this->provider->requests) - 1);
        $reply = json_decode(end($replies) ?: '{}', true);
        self::assertIsArray($reply);
        $out = [];
        foreach ($reply as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    public function testTheOwnersExampleDraftsAFillUpWithLogbooksOwnTotal(): void
    {
        $reply = $this->draft('draft_fill_up', [
            'vehicle' => $this->bmw->id,
            'volume' => '51',
            'volume_unit' => 'l',
            'price_per_unit' => '1.39',
            'fuel' => 'E10',
            'odometer' => '72,341',
        ], 'I filled the BMW with 51 litres of E10 at £1.39 a litre. The mileage is 72,341');

        self::assertSame('ok', $reply['status'], json_encode($reply) ?: '');
        self::assertSame('51.00 L E10 95 at £1.390/L = £70.89', $reply['summary']);
        self::assertSame(0, $this->rows($this->app, 'fuel_entries'), 'nothing is saved by drafting');
        self::assertSame(0, $this->rows($this->app, 'odometer_readings'));
        self::assertSame(1, $this->rows($this->app, 'ai_drafts'));
        self::assertFalse($this->connection($this->app)->isTransactionActive());

        $fields = array_column(is_array($reply['fields']) ? $reply['fields'] : [], 'value', 'label');
        self::assertSame('72,341 mi', $fields['Odometer']);
        self::assertSame('£70.89', $fields['Total']);
        self::assertIsInt($reply['draft_id']);

        $applied = $this->service($this->app, DraftStore::class)->apply($this->owner, $reply['draft_id']);
        self::assertFalse($applied->written->duplicate);
        self::assertSame(1, $this->rows($this->app, 'fuel_entries'));
        self::assertSame(1, $this->rows($this->app, 'odometer_readings'), 'the fill-up writes its reading');
        $entry = $this->service($this->app, FuelService::class)->get($this->bmw, $applied->written->entryId);
        self::assertSame(['51.000', '1.390000', '70.890', 'e10_95'], [
            $entry->data->volume,
            $entry->data->pricePerUnit,
            $entry->data->totalCost,
            $entry->data->grade?->value,
        ]);
        self::assertSame($this->owner->id, $entry->createdBy);
        self::assertSame(DraftState::Added, $applied->draft->state($this->clockNow()));
    }

    public function testADraftIsAddedOnceWhateverIsPressed(): void
    {
        $reply = $this->draft('draft_reading', ['vehicle' => $this->bmw->id, 'odometer' => '72341']);
        $store = $this->service($this->app, DraftStore::class);
        self::assertIsInt($reply['draft_id']);
        $store->apply($this->owner, $reply['draft_id']);
        try {
            $store->apply($this->owner, $reply['draft_id']);
            self::fail('A second Add must be refused.');
        } catch (\Logbook\Service\Ai\Draft\DraftRefused $refused) {
            self::assertSame('ask.draft.refused.closed', $refused->key);
        }
        self::assertSame(1, $this->rows($this->app, 'odometer_readings'));
        self::assertSame(1, $this->service($this->app, AiDraftRepository::class)->deleteExpired(
            $this->clockNow()->modify('+2 days'),
        ));
    }

    private function clockNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
