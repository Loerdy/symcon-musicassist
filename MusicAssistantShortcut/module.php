<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/MusicAssistantSelectionOptions.php';

class MusicAssistantShortcut extends IPSModule
{
    use MusicAssistantSelectionOptions;

    private const PLAYER_MODULE_ID = '{70DC85BD-4828-B5F2-12E4-AC8B6A173B36}';
    private const CONNECTION_EVENT = '{3D0660F0-556B-7070-DA8E-CD7C6D19595C}';
    // Typ des Eintrags => Ident der Auswahlvariable und Profileigenschaft im Player
    private const MEDIA_TYPES = [
        'radio'    => ['ident' => 'Radio', 'profileProperty' => 'RadioProfile'],
        'playlist' => ['ident' => 'Playlist', 'profileProperty' => 'PlaylistProfile']
    ];
    private const STATUS_INVALID_PLAYER = 201;
    private const STATUS_FOREIGN_PLAYER = 202;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('PlayerInstanceID', 0);
        $this->RegisterPropertyString('Entries', '[]');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // Nur neu geladene Playlist-/Radio-Profile betreffen die Beschriftungen der Kachel.
        $this->SetReceiveDataFilter('.*"Event":"profiles_updated".*');
        $this->SetVisualizationType(1);
        $this->SetStatus($this->configurationStatus());
        $this->updateVisualization();
    }

    public function ReceiveData($JSONString): string
    {
        $packet = json_decode($JSONString, true);
        if (is_array($packet)
            && ($packet['DataID'] ?? null) === self::CONNECTION_EVENT
            && ($packet['Event'] ?? null) === 'profiles_updated') {
            $this->updateVisualization();
        }
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $form = [
            'elements' => [
                [
                    'type'         => 'SelectInstance',
                    'name'         => 'PlayerInstanceID',
                    'caption'      => 'Player',
                    'validModules' => [self::PLAYER_MODULE_ID],
                    'onChange'     => 'IPS_RequestAction($id, "LoadPlayerOptions", $PlayerInstanceID);'
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'The entries are taken from the playlist and radio profiles of the selected player.'
                ],
                [
                    'type'     => 'List',
                    'name'     => 'Entries',
                    'caption'  => 'Entries',
                    'rowCount' => 8,
                    'add'      => true,
                    'delete'   => true,
                    'changeOrder' => true,
                    'columns'  => $this->buildEntryColumns($this->ReadPropertyInteger('PlayerInstanceID'))
                ]
            ],
            'status' => [
                ['code' => self::STATUS_INVALID_PLAYER, 'icon' => 'error', 'caption' => 'The selected player does not exist or is not a Music Assistant player.'],
                ['code' => self::STATUS_FOREIGN_PLAYER, 'icon' => 'error', 'caption' => 'The selected player is not connected to the same Music Assistant Connection.']
            ]
        ];

        return json_encode($form);
    }

    public function GetVisualizationTile(): string
    {
        $template = @file_get_contents(__DIR__ . '/module.html');
        if (!is_string($template)) {
            $this->SendDebug('Visualization', 'HTML-Vorlage konnte nicht geladen werden', 0);
            return '';
        }

        try {
            $initialState = json_encode(
                $this->buildVisualizationState(),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            $this->SendDebug('Visualization', 'Initialzustand konnte nicht erstellt werden: ' . $e->getMessage(), 0);
            $initialState = '{"entries":[]}';
        }

        return str_replace('__MUSIC_ASSISTANT_INITIAL_STATE__', $initialState, $template);
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'Play':
                $this->playEntry((int)$Value);
                break;

            case 'LoadPlayerOptions':
                $this->UpdateFormField('Entries', 'columns', json_encode($this->buildEntryColumns((int)$Value)));
                break;

            default:
                throw new Exception(sprintf($this->Translate('Unknown action: %s'), $Ident));
        }
    }

    private function configurationStatus(): int
    {
        $playerId = $this->ReadPropertyInteger('PlayerInstanceID');
        if ($playerId <= 0) {
            return IS_INACTIVE;
        }
        if (!$this->isMusicAssistantPlayer($playerId)) {
            return self::STATUS_INVALID_PLAYER;
        }
        $connectionId = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($connectionId <= 0 || (int)(IPS_GetInstance($playerId)['ConnectionID'] ?? 0) !== $connectionId) {
            return self::STATUS_FOREIGN_PLAYER;
        }
        return IS_ACTIVE;
    }

    private function isMusicAssistantPlayer(int $instanceId): bool
    {
        return $instanceId > 0
            && IPS_InstanceExists($instanceId)
            && (IPS_GetInstance($instanceId)['ModuleInfo']['ModuleID'] ?? null) === self::PLAYER_MODULE_ID;
    }

    private function playEntry(int $index): void
    {
        $entries = $this->readEntries();
        if (!isset($entries[$index])) {
            $this->SendDebug('Play', 'Unbekannter Eintrag: ' . $index, 0);
            return;
        }
        if ($this->configurationStatus() !== IS_ACTIVE) {
            $this->SendDebug('Play', 'Konfiguration ist nicht vollständig gültig', 0);
            return;
        }
        $entry = $entries[$index];
        $options = $this->readPlayerOptions($this->ReadPropertyInteger('PlayerInstanceID'));
        if (!isset($options[$entry['type']][$entry['itemId']])) {
            $message = sprintf(
                $this->Translate('The entry %s is no longer offered by the player.'),
                $entry['type'] . ':' . $entry['itemId']
            );
            $this->SendDebug('Play', $message, 0);
            $this->LogMessage($message, KL_WARNING);
            return;
        }

        // Der Player löst Playlist bzw. Sender selbst auf und protokolliert Fehler beim Start.
        IPS_RequestAction(
            $this->ReadPropertyInteger('PlayerInstanceID'),
            self::MEDIA_TYPES[$entry['type']]['ident'],
            $entry['itemId']
        );
        $this->SendDebug('Play', 'Gestartet: ' . $entry['type'] . ':' . $entry['itemId'], 0);
    }

    /**
     * @return array<int, array{type: string, itemId: int, label: string}>
     */
    private function readEntries(): array
    {
        $rows = json_decode($this->ReadPropertyString('Entries'), true);
        if (!is_array($rows)) {
            $this->SendDebug('Entries', 'Eintragsliste ist kein gültiges JSON', 0);
            return [];
        }

        $entries = [];
        foreach (array_values($rows) as $row) {
            $item = is_array($row) && is_string($row['item'] ?? null) ? $row['item'] : '';
            if (preg_match('/^(radio|playlist):([1-9][0-9]*)$/D', $item, $match) !== 1) {
                continue;
            }
            $entries[] = [
                'type'   => $match[1],
                'itemId' => (int)$match[2],
                'label'  => is_string($row['label'] ?? null) ? trim($row['label']) : ''
            ];
        }
        return $entries;
    }

    /**
     * @return array<string, array<int, string>> Typ => (Eintrags-ID => Name)
     */
    private function readPlayerOptions(int $playerId): array
    {
        $result = ['radio' => [], 'playlist' => []];
        if (!$this->isMusicAssistantPlayer($playerId)) {
            return $result;
        }
        foreach (self::MEDIA_TYPES as $type => $definition) {
            $variableId = @IPS_GetObjectIDByIdent($definition['ident'], $playerId);
            $options = $this->readSelectionOptionsOfVariable(
                is_int($variableId) ? $variableId : 0,
                (string)@IPS_GetProperty($playerId, $definition['profileProperty'])
            ) ?? [];
            foreach ($options as $option) {
                $result[$type][$option['value']] = $option['label'];
            }
        }
        return $result;
    }

    private function buildEntryColumns(int $playerId): array
    {
        $selectOptions = [['caption' => '-', 'value' => '']];
        $captions = ['radio' => $this->Translate('Radio'), 'playlist' => $this->Translate('Playlist')];
        foreach ($this->readPlayerOptions($playerId) as $type => $options) {
            foreach ($options as $itemId => $label) {
                $selectOptions[] = ['caption' => $captions[$type] . ': ' . $label, 'value' => $type . ':' . $itemId];
            }
        }

        return [
            [
                'caption' => 'Entry',
                'name'    => 'item',
                'width'   => '320px',
                'add'     => '',
                'edit'    => ['type' => 'Select', 'options' => $selectOptions]
            ],
            [
                'caption' => 'Label (optional)',
                'name'    => 'label',
                'width'   => 'auto',
                'add'     => '',
                'edit'    => ['type' => 'ValidationTextBox']
            ]
        ];
    }

    private function buildVisualizationState(): array
    {
        $options = $this->readPlayerOptions($this->ReadPropertyInteger('PlayerInstanceID'));
        $tiles = [];
        foreach ($this->readEntries() as $index => $entry) {
            $name = $options[$entry['type']][$entry['itemId']] ?? null;
            $tiles[] = [
                'index'     => $index,
                'type'      => $entry['type'],
                'label'     => $entry['label'] !== '' ? $entry['label'] : (string)$name,
                'available' => $name !== null
            ];
        }
        return ['entries' => $tiles];
    }

    private function updateVisualization(): void
    {
        try {
            $this->UpdateVisualizationValue(json_encode($this->buildVisualizationState(), JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->SendDebug('Visualization', 'Kachelzustand konnte nicht übertragen werden: ' . $e->getMessage(), 0);
        }
    }
}
