<?php

declare(strict_types=1);

namespace AIHub\WordPress\Tests\Sync;

use AIHub\WordPress\Http\ApiClient;
use AIHub\WordPress\Settings;
use AIHub\WordPress\Sync\SyncService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SyncServiceTest extends TestCase
{
    /** @return Settings&MockObject */
    private function createSettings(array $values): Settings
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('getBaseUrl')->willReturn($values['baseUrl'] ?? null);
        $settings->method('getSiteId')->willReturn($values['siteId'] ?? null);
        $settings->method('getAutomationToken')->willReturn($values['token'] ?? null);
        $settings->method('getTenantApiKey')->willReturn($values['tenantKey'] ?? null);
        return $settings;
    }

    public function testFetchDashboardManifestDelegatesToApiClient(): void
    {
        $settings = $this->createSettings([
            'baseUrl' => 'https://hub.example.com/api',
            'siteId' => 'site-id',
            'token' => 'secret',
            'tenantKey' => 'tenant-api-key',
        ]);

        $client = $this->createMock(ApiClient::class);
        $client->expects($this->once())
            ->method('fetchDashboardsManifest')
            ->with('https://hub.example.com/api', 'site-id', 'secret', 'tenant-api-key')
            ->willReturn([
                ['slug' => 'alpha'],
            ]);

        $service = new SyncService($settings, null, $client);
        $dashboards = $service->fetchDashboardManifest();

        $this->assertCount(1, $dashboards);
        $this->assertSame('alpha', $dashboards[0]['slug']);
    }

    public function testFetchDashboardDetailDelegatesToApiClient(): void
    {
        $settings = $this->createSettings([
            'baseUrl' => 'https://hub.example.com/api',
            'siteId' => 'site-id',
            'token' => 'secret',
            'tenantKey' => 'tenant-api-key',
        ]);

        $client = $this->createMock(ApiClient::class);
        $client->expects($this->once())
            ->method('fetchDashboardDetail')
            ->with('https://hub.example.com/api', 'site-id', 'secret', 'alpha', 'tenant-api-key')
            ->willReturn([
                'slug' => 'alpha',
                'label' => 'Alpha',
            ]);

        $service = new SyncService($settings, null, $client);
        $detail = $service->fetchDashboardDetail('alpha');

        $this->assertSame('alpha', $detail['slug']);
    }

    public function testFetchDashboardManifestThrowsWhenSettingsIncomplete(): void
    {
        $settings = $this->createSettings([
            'baseUrl' => null,
            'siteId' => 'site-id',
            'token' => 'secret',
        ]);

        $service = new SyncService($settings, null, $this->createMock(ApiClient::class));

        $this->expectException(\RuntimeException::class);
        $service->fetchDashboardManifest();
    }

    public function testRunReturnsZeroCountsWhenNoUpdates(): void
    {
        $settings = $this->createSettings([
            'baseUrl' => 'https://hub.example.com/api',
            'siteId' => 'site-id',
            'token' => 'secret',
            'tenantKey' => 'tenant-api-key',
        ]);

        $client = $this->createMock(ApiClient::class);
        $client->expects($this->once())
            ->method('fetchSeoUpdates')
            ->with(
                'https://hub.example.com/api',
                'site-id',
                'secret',
                [
                    'statuses' => ['pending'],
                    'limit' => 25,
                ],
                'tenant-api-key'
            )
            ->willReturn([]);

        $client->expects($this->atLeastOnce())
            ->method('sendTelemetryEvents');

        $client->expects($this->never())->method('applySeoUpdates');

        $service = new SyncService($settings, null, $client);
        $result = $service->run();

        $this->assertSame(0, $result['applied']);
        $this->assertSame(0, $result['dismissed']);
    }

    public function testRunAcknowledgesAppliedUpdates(): void
    {
        $settings = $this->createSettings([
            'baseUrl' => 'https://hub.example.com/api',
            'siteId' => 'site-id',
            'token' => 'secret',
            'tenantKey' => 'tenant-api-key',
        ]);

        $client = $this->createMock(ApiClient::class);
        $client->expects($this->once())
            ->method('fetchSeoUpdates')
            ->with(
                'https://hub.example.com/api',
                'site-id',
                'secret',
                [
                    'statuses' => ['pending'],
                    'limit' => 25,
                ],
                'tenant-api-key'
            )
            ->willReturn([
                [
                    'id' => 'draft-1',
                    'status' => 'pending',
                    'body_html' => '<p>Test</p>',
                ],
            ]);

        $client->expects($this->atLeastOnce())
            ->method('sendTelemetryEvents');

        $client->expects($this->once())
            ->method('applySeoUpdates')
            ->with(
                'https://hub.example.com/api',
                'site-id',
                'secret',
                [
                    [
                        'id' => 'draft-1',
                        'status' => 'applied',
                        'note' => '',
                    ],
                ],
                'tenant-api-key'
            )
            ->willReturn([]);

        $service = new SyncService($settings, null, $client);
        $result = $service->run();

        $this->assertSame(1, $result['applied']);
        $this->assertSame(0, $result['dismissed']);
    }
}
