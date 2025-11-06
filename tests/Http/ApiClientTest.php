<?php

declare(strict_types=1);

namespace AIHub\WordPress\Tests\Http;

use AIHub\WordPress\Http\ApiClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase
{
    private function createClient(array $responses): ApiClient
    {
        $mockClient = $this->createMock(ClientInterface::class);
        $invocation = 0;
        $mockClient->method('request')->willReturnCallback(function () use (&$responses, &$invocation) {
            $response = $responses[$invocation] ?? new Response(200, [], '{}');
            $invocation++;
            return $response;
        });

        return new ApiClient($mockClient);
    }

    public function testFetchDashboardsManifestReturnsArray(): void
    {
        $manifest = [
            'dashboards' => [
                ['slug' => 'alpha'],
                ['slug' => 'beta'],
            ],
        ];

        $client = $this->createClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($manifest, JSON_THROW_ON_ERROR)),
        ]);

        $result = $client->fetchDashboardsManifest('https://hub.example.com/api', 'site-id', 'token');
        $this->assertCount(2, $result);
        $this->assertSame('alpha', $result[0]['slug']);
    }

    public function testFetchDashboardDetailReturnsPayload(): void
    {
        $detail = [
            'dashboard' => [
                'slug' => 'alpha',
                'label' => 'Alpha',
            ],
        ];

        $client = $this->createClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($detail, JSON_THROW_ON_ERROR)),
        ]);

        $result = $client->fetchDashboardDetail('https://hub.example.com/api', 'site-id', 'token', 'alpha');
        $this->assertSame('alpha', $result['slug']);
    }

    public function testFetchSeoUpdatesUsesTokenPayload(): void
    {
        $mockClient = $this->createMock(ClientInterface::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->with(
                $this->identicalTo('POST'),
                $this->identicalTo('https://hub.example.com/api/wordpress/seo/pull'),
                $this->callback(function (array $options): bool {
                    $this->assertSame('site-id', $options['json']['site_id']);
                    $this->assertSame('token', $options['json']['token']);
                    $this->assertSame(['pending'], $options['json']['statuses']);
                    $this->assertSame(25, $options['json']['limit']);
                    return true;
                })
            )
            ->willReturn(
                new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode([
                        'updates' => [
                            ['id' => 'draft-1'],
                        ],
                    ], JSON_THROW_ON_ERROR)
                )
            );

        $client = new ApiClient($mockClient);
        $updates = $client->fetchSeoUpdates(
            'https://hub.example.com/api',
            'site-id',
            'token',
            [
                'statuses' => ['pending'],
                'limit' => 25,
            ]
        );

        $this->assertCount(1, $updates);
        $this->assertSame('draft-1', $updates[0]['id']);
    }

    public function testApplySeoUpdatesUsesTokenField(): void
    {
        $mockClient = $this->createMock(ClientInterface::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->with(
                $this->identicalTo('POST'),
                $this->identicalTo('https://hub.example.com/api/wordpress/seo/apply'),
                $this->callback(function (array $options): bool {
                    $this->assertSame('site-id', $options['json']['site_id']);
                    $this->assertSame('token', $options['json']['token']);
                    $this->assertSame('draft-1', $options['json']['updates'][0]['id']);
                    $this->assertSame('applied', $options['json']['updates'][0]['status']);
                    return true;
                })
            )
            ->willReturn(
                new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode(['updates' => []], JSON_THROW_ON_ERROR)
                )
            );

        $client = new ApiClient($mockClient);
        $result = $client->applySeoUpdates(
            'https://hub.example.com/api',
            'site-id',
            'token',
            [
                [
                    'id' => 'draft-1',
                    'status' => 'applied',
                    'note' => '',
                ],
            ]
        );

        $this->assertIsArray($result);
    }
}
