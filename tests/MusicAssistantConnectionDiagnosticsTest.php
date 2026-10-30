<?php

declare(strict_types=1);

include_once __DIR__ . '/MusicAssistantTestCase.php';

class MusicAssistantConnectionDiagnosticsTest extends MusicAssistantTestCase
{
    private const HOST = 'ma-server.example';
    private const PLAYER_ID = 'player-aa-bb-cc';

    public function testReportBeforeGreetingShowsUnknownServer(): void
    {
        $connectionId = $this->createConnection(self::HOST);

        $report = $this->report($connectionId);

        $library = json_decode((string)file_get_contents(__DIR__ . '/../library.json'), true);
        $this->assertStringContainsString('Library: ' . $library['version'] . '.' . $library['build'], $report);
        $this->assertStringContainsString('PHP: ' . PHP_VERSION, $report);
        $this->assertStringContainsString('Music Assistant: unknown (no server greeting received)', $report);
        $this->assertStringContainsString('Tested with Music Assistant: 2.10.9', $report);
        $this->assertStringContainsString('Port 8095, token set', $report);
        $this->assertStringContainsString(sprintf('WebSocket Client #%d: active', $this->webSocketId($connectionId)), $report);
        $this->assertStringContainsString('Player instances: 0', $report);
    }

    public function testReportShowsServerVersionAndConnectionStatus(): void
    {
        $connectionId = $this->createConnection(self::HOST);
        $this->authenticate($connectionId);

        $report = $this->report($connectionId);

        $this->assertStringContainsString('Music Assistant: 2.10.8 (schema 28)', $report);
        $this->assertStringContainsString(sprintf('Connection #%d: status 102 – Authenticated', $connectionId), $report);
    }

    public function testReportListsPlayersWithoutIdentifyingData(): void
    {
        $connectionId = $this->createConnection(self::HOST);
        $playerInstanceId = $this->createPlayer($connectionId, self::PLAYER_ID);
        $unconfiguredInstanceId = $this->createPlayer($connectionId, '');
        IPS_SetName($playerInstanceId, 'Wohnzimmer');
        $this->authenticate($connectionId);
        $this->notifyConnectionStatus($connectionId, $playerInstanceId);

        $report = $this->report($connectionId);

        $this->assertStringContainsString('Player instances: 2', $report);
        $this->assertMatchesRegularExpression('/- #' . $playerInstanceId . ': status \d+, registered$/m', $report);
        $this->assertMatchesRegularExpression('/- #' . $unconfiguredInstanceId . ': status \d+, no player ID$/m', $report);
        foreach ([self::TOKEN, self::HOST, self::PLAYER_ID, 'Wohnzimmer'] as $secret) {
            $this->assertStringNotContainsString($secret, $report);
        }
    }

    public function testReportWithoutConnectionDoesNotClaimMissingRegistration(): void
    {
        $connectionId = $this->createConnection(self::HOST);
        $playerInstanceId = $this->createPlayer($connectionId, self::PLAYER_ID);

        $report = $this->report($connectionId);

        $this->assertMatchesRegularExpression(
            '/- #' . $playerInstanceId . ': status \d+, registration after connecting$/m',
            $report
        );
    }

    public function testReportShowsMissingToken(): void
    {
        $connectionId = $this->createConnection(self::HOST);
        IPS_SetProperty($connectionId, 'Token', '');
        IPS_ApplyChanges($connectionId);

        $this->assertStringContainsString('Port 8095, token missing', $this->report($connectionId));
    }

    public function testRefreshActionIsHandled(): void
    {
        $connectionId = $this->createConnection(self::HOST);

        IPS_RequestAction($connectionId, 'RefreshDiagnostics', true);

        $this->addToAssertionCount(1);
    }

    private function report(int $connectionId): string
    {
        $form = json_decode($this->connection($connectionId)->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
        $panel = $form['actions'][0];
        $this->assertSame('ExpansionPanel', $panel['type']);
        foreach ($panel['items'] as $item) {
            if (($item['name'] ?? null) === 'DiagnosticsReport') {
                $this->assertTrue($item['multiline']);
                return $item['value'];
            }
        }
        $this->fail('Kein Diagnosefeld im Formular');
    }
}
