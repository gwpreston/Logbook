<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Tests\Support\JsonDoc;

/**
 * `documents` (spec.md §7.26): compliance documents with expiry and status.
 */
final class DocumentsTest extends ToolsBTestCase
{
    public function testCurrentAndPastDocumentsWithStatusAndExpiry(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->vehicle($app);
        $this->document($app, $golf, ComplianceType::Insurance, '2024-11-01', '2025-10-31', '290.00', 'Direct Line');
        $this->document($app, $golf, ComplianceType::Insurance, '2025-11-01', '2026-10-31', '312.40', 'Admiral');
        $this->document($app, $golf, ComplianceType::Inspection, '2026-03-01', '2027-02-28', '54.85');
        $this->assertSchemaAccepts($app, $owner, 'documents', ['vehicle' => $golf->id, 'type' => 'insurance']);

        $result = $this->toolResult($app, $owner, 'documents', ['vehicle' => $golf->id, 'type' => 'insurance']);
        $data = new JsonDoc($result->data);

        self::assertSame(2, $data->int('total_count'));
        $current = $data->doc('documents', 0);
        self::assertSame('expiring', $current->get('status'), '16 days left, inside the 30-day lead time');
        self::assertTrue($current->get('current'));
        self::assertSame('2026-10-31', $current->get('expiry_on'));
        self::assertSame('31 Oct 2026', $current->get('expiry_display'));
        self::assertSame(16, $current->get('days_left'));
        self::assertSame('Admiral', $current->get('provider'));
        self::assertSame('£312.40', $current->get('cost', 'display'));
        self::assertSame('replaced', $data->get('documents', 1, 'status'));
        self::assertSame('/vehicles/' . $golf->id . '/documents', $result->link);
        self::assertSame(['Insurance · 31 Oct 2026'], $result->figures);
        self::assertSame('Documents · Volkswagen Golf · Insurance', $result->source);
    }

    public function testWithoutAVehicleEveryActiveVehicleIsCovered(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->vehicle($app);
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $this->document($app, $golf, ComplianceType::Insurance, '2025-11-01', '2026-10-31', '312.40');
        $this->document($app, $polo, ComplianceType::Inspection, '2026-03-01', '2027-02-28', '54.85');

        $result = $this->toolResult($app, $owner, 'documents');

        self::assertSame(2, $result->data['total_count']);
        self::assertSame('/garage', $result->link);
        self::assertEqualsCanonicalizing([$golf->id, $polo->id], $result->vehicleIds);
    }

    public function testAShareWithoutCostsLeavesTheOwnersCostOut(): void
    {
        [$app] = $this->askApp();
        $access = $this->policy($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, ComplianceType::Insurance, '2025-11-01', '2026-10-31', '312.40');
        $partner = $this->createMember($app);
        // A View share: the owner's document, and no costs.
        $access->set($golf, VehicleAbility::View);

        $data = $this->data($app, $partner, 'documents', ['vehicle' => $golf->id]);

        self::assertFalse($data->has('documents', 0, 'cost'));
        self::assertStringNotContainsString('312', self::json($data));
    }

    public function testAnotherUsersVehicleIsNotFoundAndTheModuleOffHidesTheTool(): void
    {
        [$app] = $this->askApp();
        $golf = $this->vehicle($app);
        $partner = $this->createMember($app);
        self::assertSame(ToolKit::NOT_FOUND, $this->call($app, $partner, 'documents', ['vehicle' => $golf->id])->error);

        [$off, $owner] = $this->askApp(['FEATURES_COMPLIANCE' => 'false']);
        self::assertFalse($this->isOffered($off, $owner, 'documents'));
        self::assertStringContainsString('There is no tool', (string) $this->call($off, $owner, 'documents')->error);
    }
}
