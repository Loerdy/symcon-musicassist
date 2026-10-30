<?php

declare(strict_types=1);

include_once __DIR__ . '/MusicAssistantTestCase.php';

class MusicAssistantPlayerPlaybackOptionsTest extends MusicAssistantTestCase
{
    private const PLAYER_ID = 'player-1';
    private const QUEUE_ID = 'queue-1';

    public function testOptionVariablesExistOnlyWhenEnabled(): void
    {
        [, $playerInstanceId] = $this->createActivePlayer([]);

        foreach (['SleepTimer', 'SleepTimerEnd', 'Crossfade', 'Autoplay'] as $ident) {
            $this->assertFalse(@IPS_GetObjectIDByIdent($ident, $playerInstanceId), $ident);
        }

        [, $playerInstanceId] = $this->createActivePlayer(['ShowSleepTimer' => true, 'ShowQueueOptions' => true]);

        $this->assertSame(0, $this->value($playerInstanceId, 'SleepTimer'));
        $this->assertSame('-', $this->value($playerInstanceId, 'SleepTimerEnd'));
        $this->assertFalse($this->value($playerInstanceId, 'Crossfade'));
        $this->assertFalse($this->value($playerInstanceId, 'Autoplay'));
    }

    public function testSleepTimerSendsSecondsAndShowsEnd(): void
    {
        [, $playerInstanceId] = $this->createActivePlayer(['ShowSleepTimer' => true]);
        $expiresAt = time() + 1800;
        $this->simulateHttpResult('players/sleep_timer/set', $expiresAt);

        IPS_RequestAction($playerInstanceId, 'SleepTimer', 30);

        $this->assertSame(
            [['command' => 'players/sleep_timer/set', 'args' => ['player_id' => self::PLAYER_ID, 'seconds' => 1800]]],
            $this->httpCalls()
        );
        $this->assertSame(30, $this->value($playerInstanceId, 'SleepTimer'));
        $this->assertSame(date('H:i', $expiresAt), $this->value($playerInstanceId, 'SleepTimerEnd'));
    }

    public function testSleepTimerOffClearsTimer(): void
    {
        [, $playerInstanceId] = $this->createActivePlayer(['ShowSleepTimer' => true]);
        $this->simulateHttpResult('players/sleep_timer/set', time() + 900);
        IPS_RequestAction($playerInstanceId, 'SleepTimer', 15);
        $this->clearHttpCalls();
        $this->simulateHttpResult('players/sleep_timer/clear', null);

        IPS_RequestAction($playerInstanceId, 'SleepTimer', 0);

        $this->assertSame(
            [['command' => 'players/sleep_timer/clear', 'args' => ['player_id' => self::PLAYER_ID]]],
            $this->httpCalls()
        );
        $this->assertSame(0, $this->value($playerInstanceId, 'SleepTimer'));
        $this->assertSame('-', $this->value($playerInstanceId, 'SleepTimerEnd'));
    }

    public function testSleepTimerSchedulesExpiryCheck(): void
    {
        [, $playerInstanceId] = $this->createActivePlayer(['ShowSleepTimer' => true]);
        $this->simulateHttpResult('players/sleep_timer/set', time() + 60);

        IPS_RequestAction($playerInstanceId, 'SleepTimer', 1);

        $interval = $this->timerInterval($playerInstanceId, 'SleepTimerExpired');
        $this->assertGreaterThanOrEqual(60000, $interval);
        $this->assertLessThanOrEqual(61000, $interval);

        $this->simulateHttpResult('players/sleep_timer/clear', null);
        IPS_RequestAction($playerInstanceId, 'SleepTimer', 0);

        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'SleepTimerExpired'));
    }

    public function testExpiryResetsSelectionAndEnd(): void
    {
        [, $playerInstanceId] = $this->createActivePlayer(['ShowSleepTimer' => true]);
        $this->simulateHttpResult('players/sleep_timer/set', time() + 60);
        IPS_RequestAction($playerInstanceId, 'SleepTimer', 1);
        $this->clearHttpCalls();
        $this->simulateHttpResult('players/sleep_timer/get', null);

        IPS_RequestAction($playerInstanceId, 'SleepTimerExpired', 0);

        $this->assertSame(
            [['command' => 'players/sleep_timer/get', 'args' => ['player_id' => self::PLAYER_ID]]],
            $this->httpCalls()
        );
        $this->assertSame(0, $this->value($playerInstanceId, 'SleepTimer'));
        $this->assertSame('-', $this->value($playerInstanceId, 'SleepTimerEnd'));
        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'SleepTimerExpired'));
    }

    public function testExpiryKeepsTimerExtendedInMusicAssistant(): void
    {
        [, $playerInstanceId] = $this->createActivePlayer(['ShowSleepTimer' => true]);
        $this->simulateHttpResult('players/sleep_timer/set', time() + 60);
        IPS_RequestAction($playerInstanceId, 'SleepTimer', 1);
        $expiresAt = time() + 30 * 60;
        $this->simulateHttpResult('players/sleep_timer/get', (float)$expiresAt);

        IPS_RequestAction($playerInstanceId, 'SleepTimerExpired', 0);

        $this->assertSame(1, $this->value($playerInstanceId, 'SleepTimer'));
        $this->assertSame(date('H:i', $expiresAt), $this->value($playerInstanceId, 'SleepTimerEnd'));
        $this->assertGreaterThan(60000, $this->timerInterval($playerInstanceId, 'SleepTimerExpired'));
    }

    public function testExpiryResetsEvenIfStateCannotBeRead(): void
    {
        [, $playerInstanceId] = $this->createActivePlayer(['ShowSleepTimer' => true]);
        $this->simulateHttpResult('players/sleep_timer/set', time() + 60);
        IPS_RequestAction($playerInstanceId, 'SleepTimer', 1);
        $this->simulateHttpFailure('players/sleep_timer/get');

        IPS_RequestAction($playerInstanceId, 'SleepTimerExpired', 0);

        $this->assertSame(0, $this->value($playerInstanceId, 'SleepTimer'));
        $this->assertSame('-', $this->value($playerInstanceId, 'SleepTimerEnd'));
        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'SleepTimerExpired'));
    }

    public function testUnknownSleepTimerDurationSendsNoCommand(): void
    {
        [, $playerInstanceId] = $this->createActivePlayer(['ShowSleepTimer' => true]);

        IPS_RequestAction($playerInstanceId, 'SleepTimer', 20);

        $this->assertSame([], $this->httpCalls());
        $this->assertSame(0, $this->value($playerInstanceId, 'SleepTimer'));
    }

    public function testFailedSleepTimerKeepsState(): void
    {
        [, $playerInstanceId] = $this->createActivePlayer(['ShowSleepTimer' => true]);
        $this->simulateHttpFailure('players/sleep_timer/set');

        IPS_RequestAction($playerInstanceId, 'SleepTimer', 30);

        $this->assertSame(0, $this->value($playerInstanceId, 'SleepTimer'));
        $this->assertSame('-', $this->value($playerInstanceId, 'SleepTimerEnd'));
    }

    public function testPlayerEventShowsExternalTimerAndItsEnd(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(['ShowSleepTimer' => true]);
        // Ein in der Music-Assistant-App gesetzter Timer mit 50 Minuten Restzeit.
        $expiresAt = time() + 50 * 60;

        $this->sendPlayerUpdated($connectionId, ['sleep_timer_expires_at' => (float)$expiresAt]);

        $this->assertSame(60, $this->value($playerInstanceId, 'SleepTimer'));
        $this->assertSame(date('H:i', $expiresAt), $this->value($playerInstanceId, 'SleepTimerEnd'));

        $this->sendPlayerUpdated($connectionId, ['volume_level' => 20]);
        $this->assertSame(60, $this->value($playerInstanceId, 'SleepTimer'));

        $this->sendPlayerUpdated($connectionId, ['sleep_timer_expires_at' => null]);

        $this->assertSame(0, $this->value($playerInstanceId, 'SleepTimer'));
        $this->assertSame('-', $this->value($playerInstanceId, 'SleepTimerEnd'));
    }

    public function testEventBeforeOwnSelectionKeepsChosenStep(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(['ShowSleepTimer' => true]);
        // Music Assistant meldet das Ende mit Sekundenbruchteilen, bevor die eigene Auswahl gespeichert ist.
        $expiresAt = time() + 15 * 60 + 0.4;

        $this->sendPlayerUpdated($connectionId, ['sleep_timer_expires_at' => $expiresAt]);

        $this->assertSame(15, $this->value($playerInstanceId, 'SleepTimer'));
    }

    public function testMillisecondTimestampIsRecognized(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(['ShowSleepTimer' => true]);
        $expiresAt = time() + 10 * 60;

        $this->sendPlayerUpdated($connectionId, ['sleep_timer_expires_at' => $expiresAt * 1000]);

        $this->assertSame(15, $this->value($playerInstanceId, 'SleepTimer'));
        $this->assertSame(date('H:i', $expiresAt), $this->value($playerInstanceId, 'SleepTimerEnd'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function queueOptionProvider(): array
    {
        return [
            'crossfade' => ['Crossfade', 'player_queues/crossfade', 'crossfade_enabled'],
            'autoplay'  => ['Autoplay', 'player_queues/autoplay', 'autoplay_enabled']
        ];
    }

    /**
     * @dataProvider queueOptionProvider
     */
    public function testQueueOptionSendsCommandForActiveQueue(string $ident, string $command, string $field): void
    {
        [, $playerInstanceId] = $this->createActivePlayer(['ShowQueueOptions' => true]);
        $this->simulateHttpResult('player_queues/get_active_queue', ['queue_id' => self::QUEUE_ID]);
        $this->simulateHttpResult($command, null);

        IPS_RequestAction($playerInstanceId, $ident, true);

        $this->assertSame(['queue_id' => self::QUEUE_ID, $field => true], $this->lastCall($command)['args']);
        $this->assertTrue($this->value($playerInstanceId, $ident));
    }

    /**
     * @dataProvider queueOptionProvider
     */
    public function testFailedQueueOptionKeepsVariable(string $ident, string $command, string $field): void
    {
        [, $playerInstanceId] = $this->createActivePlayer(['ShowQueueOptions' => true]);
        $this->simulateHttpResult('player_queues/get_active_queue', ['queue_id' => self::QUEUE_ID]);
        $this->simulateHttpFailure($command);

        IPS_RequestAction($playerInstanceId, $ident, true);

        $this->assertFalse($this->value($playerInstanceId, $ident));
    }

    public function testQueueEventUpdatesCrossfadeAndAutoplay(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(['ShowQueueOptions' => true]);
        $this->answerActiveQueue($connectionId);

        $this->sendQueueUpdated($connectionId, ['crossfade_enabled' => true, 'autoplay_enabled' => true]);

        $this->assertTrue($this->value($playerInstanceId, 'Crossfade'));
        $this->assertTrue($this->value($playerInstanceId, 'Autoplay'));

        $this->sendQueueUpdated($connectionId, ['autoplay_enabled' => false]);

        $this->assertTrue($this->value($playerInstanceId, 'Crossfade'));
        $this->assertFalse($this->value($playerInstanceId, 'Autoplay'));
    }

    public function testEnablingOptionsReadsCurrentState(): void
    {
        [, $playerInstanceId] = $this->createActivePlayer([]);
        $expiresAt = time() + 25 * 60;
        $this->simulateHttpResult('players/sleep_timer/get', (float)$expiresAt);
        $this->simulateHttpResult('player_queues/get_active_queue', [
            'queue_id'          => self::QUEUE_ID,
            'crossfade_enabled' => true,
            'autoplay_enabled'  => false
        ]);

        IPS_SetProperty($playerInstanceId, 'ShowSleepTimer', true);
        IPS_SetProperty($playerInstanceId, 'ShowQueueOptions', true);
        IPS_ApplyChanges($playerInstanceId);

        $this->assertSame(30, $this->value($playerInstanceId, 'SleepTimer'));
        $this->assertSame(date('H:i', $expiresAt), $this->value($playerInstanceId, 'SleepTimerEnd'));
        $this->assertTrue($this->value($playerInstanceId, 'Crossfade'));
        $this->assertFalse($this->value($playerInstanceId, 'Autoplay'));
    }

    public function testDisablingOptionsRemovesVariables(): void
    {
        [, $playerInstanceId] = $this->createActivePlayer(['ShowSleepTimer' => true, 'ShowQueueOptions' => true]);

        IPS_SetProperty($playerInstanceId, 'ShowSleepTimer', false);
        IPS_SetProperty($playerInstanceId, 'ShowQueueOptions', false);
        IPS_ApplyChanges($playerInstanceId);

        foreach (['SleepTimer', 'SleepTimerEnd', 'Crossfade', 'Autoplay'] as $ident) {
            $this->assertFalse(@IPS_GetObjectIDByIdent($ident, $playerInstanceId), $ident);
        }
    }

    public function testDisablingSleepTimerStopsExpiryCheck(): void
    {
        [, $playerInstanceId] = $this->createActivePlayer(['ShowSleepTimer' => true]);
        $this->simulateHttpResult('players/sleep_timer/set', time() + 60);
        IPS_RequestAction($playerInstanceId, 'SleepTimer', 1);

        IPS_SetProperty($playerInstanceId, 'ShowSleepTimer', false);
        IPS_ApplyChanges($playerInstanceId);

        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'SleepTimerExpired'));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function createActivePlayer(array $properties): array
    {
        $connectionId = $this->createConnection();
        $playerInstanceId = $this->createPlayer($connectionId, self::PLAYER_ID);
        foreach ($properties as $name => $value) {
            IPS_SetProperty($playerInstanceId, $name, $value);
        }
        IPS_ApplyChanges($playerInstanceId);
        $this->authenticate($connectionId);
        $this->notifyConnectionStatus($connectionId, $playerInstanceId);
        $this->clearHttpCalls();

        return [$connectionId, $playerInstanceId];
    }

    private function answerActiveQueue(int $connectionId): void
    {
        $queueRequest = $this->lastSentMessage($connectionId, 'player_queues/get_active_queue');
        $this->receiveFromServer($connectionId, [
            'message_id' => $queueRequest['message_id'],
            'result'     => [
                'queue_id'     => self::QUEUE_ID,
                'active'       => true,
                'display_name' => 'Wohnzimmer',
                'available'    => true,
                'items'        => 0,
                'state'        => 'idle',
                'current_item' => null
            ]
        ]);
    }

    private function sendPlayerUpdated(int $connectionId, array $data): void
    {
        $this->receiveFromServer($connectionId, [
            'event'     => 'player_updated',
            'object_id' => self::PLAYER_ID,
            'data'      => $data
        ]);
    }

    private function sendQueueUpdated(int $connectionId, array $data): void
    {
        $this->receiveFromServer($connectionId, [
            'event'     => 'queue_updated',
            'object_id' => self::QUEUE_ID,
            'data'      => $data
        ]);
    }

    private function lastCall(string $command): array
    {
        $calls = array_values(array_filter($this->httpCalls(), static function (array $call) use ($command): bool {
            return $call['command'] === $command;
        }));
        $this->assertNotEmpty($calls, 'Kein Aufruf von ' . $command);

        return $calls[count($calls) - 1];
    }

    private function timerInterval(int $instanceId, string $ident): int
    {
        return IPS\InstanceManager::getInstanceInterface($instanceId)->GetTimerIntervalForTest($ident);
    }

    private function value(int $instanceId, string $ident)
    {
        return GetValue(IPS_GetObjectIDByIdent($ident, $instanceId));
    }
}
