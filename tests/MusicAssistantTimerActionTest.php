<?php

declare(strict_types=1);

include_once __DIR__ . '/MusicAssistantTestCase.php';

// Die Stubs speichern keine Timer-Skripte; der Skripttext wird deshalb aus dem Quelltext geprüft.
class MusicAssistantTimerActionTest extends MusicAssistantTestCase
{
    private const TIMERS = [
        'MusicAssistantConnection' => ['CheckConnection'],
        'MusicAssistantPlayer'     => ['ResetPlaylist', 'ResetRadio', 'ResolveFavorite', 'DownloadCover', 'RefreshGroupMemberVolumes', 'SleepTimerExpired'],
        'MusicAssistantShortcut'   => ['LoadImages']
    ];

    // Für jeden Timer bleibt die bisherige öffentliche Funktion für bestehende Skripte erhalten.
    private const LEGACY_FUNCTIONS = [
        'MusicAssistantConnection' => ['CheckConnection'],
        'MusicAssistantPlayer'     => [
            'ResetPlaylistSelection', 'ResetRadioSelection', 'ProcessFavoriteResolution',
            'ProcessCoverDownload', 'ProcessGroupMemberVolumeRefresh'
        ]
    ];

    public function testTimersCallRequestActionWithTheirOwnName(): void
    {
        foreach (self::TIMERS as $module => $timers) {
            $source = (string)file_get_contents(__DIR__ . '/../' . $module . '/module.php');
            preg_match_all('/RegisterTimer\(\'([A-Za-z]+)\', 0, \'(.*)\'\);/', $source, $matches, PREG_SET_ORDER);

            $this->assertSame($timers, array_column($matches, 1), $module);
            foreach ($matches as [, $timer, $script]) {
                $this->assertMatchesRegularExpression(
                    '/^IPS_RequestAction\(\$_IPS\["TARGET"\], "' . $timer . '", (0|false)\);$/D',
                    $script,
                    $module . ': ' . $timer
                );
            }
        }
    }

    public function testLegacyTimerFunctionsRemainPublic(): void
    {
        foreach (self::LEGACY_FUNCTIONS as $class => $functions) {
            foreach ($functions as $function) {
                $method = new ReflectionMethod($class, $function);
                $this->assertTrue($method->isPublic(), $class . '::' . $function);
            }
        }
    }

    public function testConnectionHandlesTimerAction(): void
    {
        $connectionId = $this->createConnection();
        $this->authenticate($connectionId);

        IPS_RequestAction($connectionId, 'CheckConnection', 0);

        $this->assertSame(102, IPS_GetInstance($connectionId)['InstanceStatus']);
    }

    public function testPlayerResetsMediaSelectionsThroughTimerActions(): void
    {
        IPS_CreateVariableProfile('MA.Radios.timer', VARIABLETYPE_INTEGER);
        IPS_SetVariableProfileAssociation('MA.Radios.timer', 12, 'SWR3', '', -1);
        IPS_CreateVariableProfile('MA.Playlists.timer', VARIABLETYPE_INTEGER);
        IPS_SetVariableProfileAssociation('MA.Playlists.timer', 7, 'Favoriten', '', -1);

        $playerInstanceId = IPS_CreateInstance(self::PLAYER_MODULE_ID);
        IPS_SetProperty($playerInstanceId, 'PlayerID', 'player-1');
        IPS_SetProperty($playerInstanceId, 'RadioProfile', 'MA.Radios.timer');
        IPS_SetProperty($playerInstanceId, 'RadioMap', json_encode(['12' => 'library://radio/12']));
        IPS_SetProperty($playerInstanceId, 'PlaylistProfile', 'MA.Playlists.timer');
        IPS_SetProperty($playerInstanceId, 'PlaylistMap', json_encode(['7' => ['uri' => 'library://playlist/7', 'provider_mappings' => []]]));
        IPS_ConnectInstance($playerInstanceId, $this->createConnection());
        IPS_ApplyChanges($playerInstanceId);
        $player = IPS\InstanceManager::getInstanceInterface($playerInstanceId);

        foreach (['ResetPlaylist' => ['Playlist', 7], 'ResetRadio' => ['Radio', 12]] as $timer => [$ident, $itemId]) {
            $variableId = IPS_GetObjectIDByIdent($ident, $playerInstanceId);
            SetValue($variableId, $itemId);

            IPS_RequestAction($playerInstanceId, $timer, 0);

            $this->assertSame(0, GetValue($variableId), $ident);
            $this->assertSame(0, $player->GetTimerIntervalForTest($timer), $timer);
        }
    }
}
