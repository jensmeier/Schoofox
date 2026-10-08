<?php

declare(strict_types=1);

/**
 * Schulmanager Online -> SymDo Stundenplan.
 *
 * Eigenständiges IP-Symcon-Modul. Schulmanager wird ausschließlich gelesen.
 * Der Stundenplan wird über die öffentliche SymDo-Funktion STPL_ImportSlots()
 * in eine bestehende SymDo-Stundenplaninstanz geschrieben.
 *
 * Hausaufgaben werden über die gekoppelte SymDo-App-API in den normalen
 * SymDo-Hausaufgabenbestand synchronisiert. Prüfungen können mit exakter
 * Schulstunde in einen über SymDo/OpenCalendar beschreibbaren Kalender geschrieben
 * werden. Noten und Elternbriefe zeigt die eigene Schulmanager-Schulseite.
 */
class SchulmanagerSymDo extends IPSModule
{
    private const BASE = 'https://login.schulmanager-online.de';
    private const SALT_URL = self::BASE . '/api/get-salt';
    private const LOGIN_URL = self::BASE . '/api/login';
    private const CALLS_URL = self::BASE . '/api/calls';

    private const PBKDF2_ITERATIONS = 99999;
    private const PBKDF2_DK_LEN = 512;
    private const BUNDLE_FALLBACK = '3505280ee7';

    private const PLAN_DAYS = 14;
    private const TEMPLATE_DAYS = 28;
    private const EXAM_DAYS = 56;
    private const LETTER_DETAIL_MAX = 100;
    private const INTERVAL_DEFAULT = 30;
    private const INTERVAL_MIN = 15;
    private const INTERVAL_MAX = 1440;
    private const FAIL_MAX = 3;
    private const FAIL_PAUSE = 21600; // 6 h

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Enabled', false);
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('InstitutionID', 0);
        $this->RegisterPropertyInteger('UpdateMinutes', self::INTERVAL_DEFAULT);
        $this->RegisterPropertyInteger('TimetableInstanceID', 0);
        $this->RegisterPropertyInteger('SymDoGatewayInstanceID', 0);
        $this->RegisterPropertyString('Mappings', '[]');

        $this->RegisterPropertyBoolean('ReadHomework', true);
        $this->RegisterPropertyBoolean('SyncHomeworkToSymDo', true);
        $this->RegisterPropertyInteger('HomeworkLookbackDays', 7);
        $this->RegisterPropertyBoolean('ReadExams', true);
        $this->RegisterPropertyBoolean('SyncExamsToCalendar', true);
        $this->RegisterPropertyInteger('CalendarID', 0);
        $this->RegisterPropertyBoolean('ReadGrades', true);
        $this->RegisterPropertyBoolean('ReadLetters', true);

        // Darstellung der eigenen Klassenseiten-Kachel.
        $this->RegisterPropertyString('ClassPageTitle', 'Klassenseiten');
        $this->RegisterPropertyInteger('LetterLimit', 10);
        $this->RegisterPropertyInteger('LetterScrollHeight', 320);
        $this->RegisterPropertyInteger('ExamDisplayLimit', 8);
        $this->RegisterPropertyBoolean('ShowRefreshButton', true);

        $this->RegisterAttributeString('DetectedStudents', '[]');
        $this->RegisterAttributeString('StatusData', '{}');
        $this->RegisterAttributeString('BundleVersion', self::BUNDLE_FALLBACK);
        $this->RegisterAttributeInteger('BundleVersionAt', 0);
        $this->RegisterAttributeInteger('LoginFails', 0);
        $this->RegisterAttributeInteger('LoginFailAt', 0);
        $this->RegisterAttributeString('SymDoApiBase', '');
        $this->RegisterAttributeString('SymDoToken', '');
        $this->RegisterAttributeString('HomeworkLinks', '{}');
        $this->RegisterAttributeString('CalendarLinks', '{}');
        $this->RegisterAttributeString('LetterDetails', '{}');

        $this->RegisterVariableString('Status', 'Status');
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', '~UnixTimestamp');
        $this->RegisterVariableString('Overview', 'Schulmanager Schulseite', '~HTMLBox');
        $this->RegisterVariableString('HomeworkJSON', 'Hausaufgaben JSON');
        $this->RegisterVariableString('ExamsJSON', 'Prüfungen JSON');
        $this->RegisterVariableString('GradesJSON', 'Noten JSON');
        $this->RegisterVariableString('LettersJSON', 'Elternbriefe JSON');

        // Eigene HTML-Kachel: sie ersetzt die bisherige große HTMLBox-Anzeige.
        $this->SetVisualizationType(
            defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN')
                ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN
                : 1
        );

        $this->RegisterTimer(
            'UpdateTimer',
            0,
            'IPS_RequestAction($_IPS[\'TARGET\'], \'UpdateNow\', 0);'
        );
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Auch bestehende Instanzen, die vor Version 1.3 angelegt wurden, auf die
        // eigene Klassenseiten-Kachel umstellen.
        $this->SetVisualizationType(
            defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN')
                ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN
                : 1
        );

        foreach (['Overview', 'HomeworkJSON', 'ExamsJSON', 'GradesJSON', 'LettersJSON'] as $ident) {
            $oid = $this->GetIDForIdent($ident);
            if ($oid > 0) {
                @IPS_SetHidden($oid, true);
            }
        }

        $minutes = max(self::INTERVAL_MIN, min(
            self::INTERVAL_MAX,
            $this->ReadPropertyInteger('UpdateMinutes')
        ));

        $active = $this->ReadPropertyBoolean('Enabled')
            && trim($this->ReadPropertyString('Email')) !== ''
            && trim($this->ReadPropertyString('Password')) !== ''
            && $this->ReadPropertyInteger('TimetableInstanceID') > 0
            && $this->Mappings() !== [];

        $this->SetTimerInterval('UpdateTimer', $active ? $minutes * 60000 : 0);
        $this->SetStatus($active ? 102 : 104);

        // Anzeigeeinstellungen (z. B. 10 Elternbriefe oder Scrollhöhe) sofort
        // übernehmen, ohne einen neuen Schulmanager-Abruf zu erzwingen.
        $this->RefreshOverviewFromStored();
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ((string)$Ident) {
            case 'TestConnection':
                $this->UpdateFormField('StatusLabel', 'caption', $this->TestConnection());
                return;

            case 'FetchStudents':
                $this->UpdateFormField('StatusLabel', 'caption', $this->FetchStudents());
                return;

            case 'PairSymDo':
                $this->UpdateFormField('SymDoStatusLabel', 'caption', $this->PairSymDo());
                return;

            case 'TestSymDo':
                $this->UpdateFormField('SymDoStatusLabel', 'caption', $this->TestSymDo());
                return;

            case 'DisconnectSymDo':
                $this->UpdateFormField('SymDoStatusLabel', 'caption', $this->DisconnectSymDo());
                return;

            case 'DryRun':
                $this->UpdateFormField('StatusLabel', 'caption', $this->RunUpdate(false));
                return;

            case 'UpdateNow':
                $text = $this->RunUpdate(true);
                @ $this->UpdateFormField('StatusLabel', 'caption', $text);
                return;

            case 'ImportTemplate':
                $this->UpdateFormField('StatusLabel', 'caption', $this->ImportWeeklyTemplate());
                return;

            case 'RenameClassPage':
                IPS_SetName($this->InstanceID, 'SymDo - Klassenseiten');
                return;
        }

        throw new Exception('Unbekannte Aktion: ' . (string)$Ident);
    }

    /**
     * Eigene Tile-Visualisierung. Die Rohvariable "Overview" bleibt nur als
     * interner Fallback im Objektbaum und ist ab 1.3 ausgeblendet.
     */
    public function GetVisualizationTile(): string
    {
        $html = $this->CurrentOverviewHtml();
        $initial = json_encode(
            $html,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($initial === false) {
            $initial = '""';
        }

        return <<<'HTML'
<style>
:root{font-family:Arial,sans-serif;color:#202124}
*{box-sizing:border-box}
body{margin:0;background:transparent;color:inherit}
#smsd-root{height:100%;overflow:hidden}
.smsd-shell{height:100%;display:flex;flex-direction:column;gap:10px;padding:10px}
.smsd-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.smsd-head h2{margin:0;font-size:24px;line-height:1.15}
.smsd-source{font-size:12px;color:#667085;background:rgba(127,127,127,.10);padding:4px 8px;border-radius:999px}
.smsd-spacer{flex:1}
.smsd-refresh{border:0;border-radius:9px;padding:7px 10px;cursor:pointer;background:rgba(0,150,136,.12);color:inherit;font-weight:600}
.smsd-tabs{display:flex;gap:8px;overflow-x:auto;padding-bottom:2px;scrollbar-width:thin}
.smsd-tab{border:1px solid rgba(127,127,127,.24);background:rgba(127,127,127,.07);color:inherit;border-radius:999px;padding:7px 13px;cursor:pointer;white-space:nowrap;font-weight:600}
.smsd-tab.active{background:#10bfae;color:white;border-color:#10bfae}
.smsd-body{min-height:0;overflow:hidden;flex:1}
.smsd-child-panel{display:none;height:100%;overflow-y:auto;padding-right:4px;scrollbar-width:thin}
.smsd-child-panel.active{display:block}
.smsd-card{border:1px solid rgba(127,127,127,.20);border-radius:12px;padding:12px;background:rgba(255,255,255,.03);margin-bottom:10px}
.smsd-card h3{margin:0 0 9px 0;font-size:18px}
.smsd-muted{color:#777}
.smsd-empty{padding:10px;border-radius:8px;background:rgba(127,127,127,.08)}
.smsd-table{width:100%;border-collapse:collapse;font-size:14px}
.smsd-table th,.smsd-table td{padding:7px;border-bottom:1px solid rgba(127,127,127,.18);text-align:left;vertical-align:top}
.smsd-table th:last-child,.smsd-table td:last-child{text-align:right}
.smsd-letter-scroll{overflow-y:auto;padding-right:4px;scrollbar-width:thin}
.smsd-letter{margin:5px 0;padding:8px 10px;border:1px solid rgba(127,127,127,.18);border-radius:8px;background:rgba(127,127,127,.035)}
.smsd-letter summary{cursor:pointer}
.smsd-letter-text{white-space:pre-wrap;margin:10px 2px 4px 2px;line-height:1.45}
.smsd-foot{font-size:11px;color:#777;margin-top:6px}
@media(max-width:650px){.smsd-head h2{font-size:20px}.smsd-table{font-size:12px}.smsd-table th,.smsd-table td{padding:5px}.smsd-shell{padding:7px}}
</style>
<script>
function smsdActivate(key){
  document.querySelectorAll('.smsd-tab').forEach(function(b){b.classList.toggle('active',b.dataset.smsdTab===key)});
  document.querySelectorAll('.smsd-child-panel').forEach(function(p){p.classList.toggle('active',p.dataset.smsdPanel===key)});
}
function smsdInit(){
  var active=document.querySelector('.smsd-tab.active')||document.querySelector('.smsd-tab');
  if(active) smsdActivate(active.dataset.smsdTab);
}
document.addEventListener('click',function(e){
  var b=e.target.closest('.smsd-tab');
  if(b){smsdActivate(b.dataset.smsdTab);}
});
function handleMessage(data){
  var root=document.getElementById('smsd-root');
  if(!root) return;
  root.innerHTML=typeof data==='string'?data:String(data??'');
  smsdInit();
}
</script>
<div id="smsd-root"></div>
HTML
            . '<script>handleMessage(' . $initial . ');</script>';
    }

    private function PushVisualization(string $html): void
    {
        try {
            $this->UpdateVisualizationValue($html);
        } catch (Throwable $e) {
            // Alte Visualisierungen ignorieren den Push; beim nächsten Öffnen
            // liefert GetVisualizationTile() trotzdem den aktuellen Stand.
        }
    }

    private function CurrentOverviewHtml(): string
    {
        $id = $this->GetIDForIdent('Overview');
        if ($id > 0) {
            $value = (string)GetValue($id);
            if (trim($value) !== '') {
                return $value;
            }
        }
        return '<div class="smsd-shell"><div class="smsd-head"><h2>Klassenseiten</h2><span class="smsd-source">Schulmanager</span></div><div class="smsd-empty">Noch keine Daten. In der Instanz „Jetzt abrufen und übernehmen“ ausführen.</div></div>';
    }

    private function JsonVariable(string $ident, array $fallback): array
    {
        $id = $this->GetIDForIdent($ident);
        if ($id <= 0) {
            return $fallback;
        }
        $decoded = json_decode((string)GetValue($id), true);
        return is_array($decoded) ? $decoded : $fallback;
    }

    private function RefreshOverviewFromStored(): void
    {
        // Beim allerersten Create existiert noch kein verwertbarer Bestand.
        if ($this->GetIDForIdent('Overview') <= 0) {
            return;
        }
        $homework = $this->JsonVariable('HomeworkJSON', []);
        $exams = $this->JsonVariable('ExamsJSON', []);
        $grades = $this->JsonVariable('GradesJSON', []);
        $letters = $this->JsonVariable('LettersJSON', []);
        $html = $this->BuildOverview($homework, $exams, $grades, $letters);
        $this->SetStringIfChanged('Overview', $html);
        $this->PushVisualization($html);
    }

    public function GetConfigurationForm(): string
    {
        $status = $this->StatusText();
        $students = $this->DetectedStudents();
        $calendarOptions = $this->CalendarOptions();

        $studentOptions = [
            ['caption' => '— auswählen —', 'value' => '']
        ];
        foreach ($students as $student) {
            $studentOptions[] = [
                'caption' => (string)$student['name'],
                'value' => (string)$student['id']
            ];
        }

        $elements = [
            [
                'type' => 'ExpansionPanel',
                'caption' => 'Schulmanager Online',
                'expanded' => true,
                'items' => [
                    [
                        'type' => 'Label',
                        'caption' => 'Eigenständige, reine Leseanbindung. Der Stundenplan wird direkt in die vorhandene SymDo-Stundenplaninstanz importiert.'
                    ],
                    [
                        'type' => 'CheckBox',
                        'name' => 'Enabled',
                        'caption' => 'Automatische Aktualisierung aktivieren'
                    ],
                    [
                        'type' => 'RowLayout',
                        'items' => [
                            [
                                'type' => 'ValidationTextBox',
                                'name' => 'Email',
                                'caption' => 'E-Mail / Benutzername',
                                'width' => '300px'
                            ],
                            [
                                'type' => 'PasswordTextBox',
                                'name' => 'Password',
                                'caption' => 'Passwort',
                                'width' => '260px'
                            ],
                            [
                                'type' => 'NumberSpinner',
                                'name' => 'InstitutionID',
                                'caption' => 'Institution-ID (0 = automatisch)',
                                'minimum' => 0,
                                'maximum' => 999999,
                                'width' => '220px'
                            ]
                        ]
                    ],
                    [
                        'type' => 'RowLayout',
                        'items' => [
                            [
                                'type' => 'NumberSpinner',
                                'name' => 'UpdateMinutes',
                                'caption' => 'Aktualisierung',
                                'minimum' => self::INTERVAL_MIN,
                                'maximum' => self::INTERVAL_MAX,
                                'suffix' => ' min',
                                'width' => '200px'
                            ],
                            [
                                'type' => 'SelectInstance',
                                'name' => 'TimetableInstanceID',
                                'caption' => 'SymDo-Stundenplan',
                                'width' => '360px'
                            ]
                        ]
                    ],
                    [
                        'type' => 'SelectInstance',
                        'name' => 'SymDoGatewayInstanceID',
                        'caption' => 'SymDo-Gateway (Hausaufgaben / Kalender)',
                        'width' => '580px'
                    ],
                    [
                        'type' => 'Label',
                        'caption' => 'Empfehlung: 30 Minuten. Mehrere Kinder unter demselben Elternkonto werden mit nur einer Anmeldung gelesen.'
                    ],
                    [
                        'type' => 'RowLayout',
                        'items' => [
                            [
                                'type' => 'Button',
                                'caption' => 'Verbindung testen',
                                'onClick' => 'IPS_RequestAction($id, \'TestConnection\', 0);'
                            ],
                            [
                                'type' => 'Button',
                                'caption' => 'Kinder abrufen',
                                'onClick' => 'IPS_RequestAction($id, \'FetchStudents\', 0);'
                            ]
                        ]
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => 'Kinder zuordnen',
                'expanded' => true,
                'items' => [
                    [
                        'type' => 'List',
                        'name' => 'Mappings',
                        'rowCount' => max(5, count($students)),
                        'add' => true,
                        'delete' => true,
                        'caption' => 'Schulmanager-Kind → SymDo-Kind',
                        'columns' => [
                            [
                                'caption' => 'Aktiv',
                                'name' => 'enabled',
                                'width' => '70px',
                                'add' => true,
                                'edit' => ['type' => 'CheckBox']
                            ],
                            [
                                'caption' => 'Schulmanager-Kind',
                                'name' => 'studentId',
                                'width' => '260px',
                                'add' => '',
                                'edit' => [
                                    'type' => 'Select',
                                    'options' => $studentOptions
                                ]
                            ],
                            [
                                'caption' => 'SymDo-Kindname',
                                'name' => 'symdoChild',
                                'width' => '220px',
                                'add' => '',
                                'edit' => ['type' => 'ValidationTextBox']
                            ]
                        ]
                    ],
                    [
                        'type' => 'Label',
                        'caption' => 'Der SymDo-Kindname muss dem Namen entsprechen, der in deiner SymDo-Stundenplaninstanz unter „Kinder“ steht. Nach „Kinder abrufen“ werden neue Kinder automatisch als Zeilen angelegt.'
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => 'Klassenseite / Anzeige',
                'expanded' => true,
                'items' => [
                    [
                        'type' => 'RowLayout',
                        'items' => [
                            [
                                'type' => 'ValidationTextBox',
                                'name' => 'ClassPageTitle',
                                'caption' => 'Überschrift in der Kachel',
                                'width' => '300px'
                            ],
                            [
                                'type' => 'NumberSpinner',
                                'name' => 'ExamDisplayLimit',
                                'caption' => 'Prüfungen anzeigen',
                                'minimum' => 3,
                                'maximum' => 20,
                                'suffix' => ' Stück',
                                'width' => '190px'
                            ]
                        ]
                    ],
                    [
                        'type' => 'RowLayout',
                        'items' => [
                            [
                                'type' => 'NumberSpinner',
                                'name' => 'LetterLimit',
                                'caption' => 'Neueste Elternbriefe je Kind',
                                'minimum' => 5,
                                'maximum' => 30,
                                'suffix' => ' Stück',
                                'width' => '240px'
                            ],
                            [
                                'type' => 'NumberSpinner',
                                'name' => 'LetterScrollHeight',
                                'caption' => 'Höhe Elternbrief-Liste',
                                'minimum' => 180,
                                'maximum' => 800,
                                'suffix' => ' px',
                                'width' => '230px'
                            ],
                            [
                                'type' => 'CheckBox',
                                'name' => 'ShowRefreshButton',
                                'caption' => 'Aktualisieren-Knopf in Kachel'
                            ]
                        ]
                    ],
                    [
                        'type' => 'Label',
                        'caption' => 'Bei mehreren Kindern erscheinen oben Umschaltknöpfe. Noten, Prüfungen und Elternbriefe bleiben je Kind getrennt. Standard: 10 Elternbriefe mit eigenem Scrollbereich.'
                    ],
                    [
                        'type' => 'Button',
                        'caption' => 'Instanzname auf „SymDo - Klassenseiten“ setzen',
                        'onClick' => 'IPS_RequestAction($id, \'RenameClassPage\', 0);'
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => 'Daten',
                'expanded' => true,
                'items' => [
                    ['type' => 'CheckBox', 'name' => 'ReadHomework', 'caption' => 'Hausaufgaben lesen'],
                    ['type' => 'CheckBox', 'name' => 'SyncHomeworkToSymDo', 'caption' => 'Hausaufgaben in die normale SymDo-Hausaufgabenliste übernehmen'],
                    [
                        'type' => 'NumberSpinner',
                        'name' => 'HomeworkLookbackDays',
                        'caption' => 'Überfällige Hausaufgaben noch übernehmen',
                        'minimum' => 0,
                        'maximum' => 60,
                        'suffix' => ' Tage',
                        'width' => '260px'
                    ],
                    ['type' => 'CheckBox', 'name' => 'ReadExams', 'caption' => 'Klassenarbeiten / Prüfungen lesen'],
                    ['type' => 'CheckBox', 'name' => 'SyncExamsToCalendar', 'caption' => 'Prüfungen mit exakter Schulstunde in den Kalender synchronisieren'],
                    [
                        'type' => 'Select',
                        'name' => 'CalendarID',
                        'caption' => 'Kalender für Klassenarbeiten',
                        'width' => '520px',
                        'options' => $calendarOptions
                    ],
                    ['type' => 'CheckBox', 'name' => 'ReadGrades', 'caption' => 'Noten / Zensuren lesen'],
                    ['type' => 'CheckBox', 'name' => 'ReadLetters', 'caption' => 'Elternbriefe lesen und auf der Schulseite anzeigen'],
                    [
                        'type' => 'Label',
                        'caption' => 'Prüfungen werden nur dann in OpenCalendar geschrieben, wenn ein beschreibbarer Kalender gewählt ist. Noten werden nach Fach und Bewertungsblock getrennt; ein Gesamtdurchschnitt erscheint nur, wenn Schulmanager die Gewichtung liefert.'
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => 'SymDo verbinden',
                'expanded' => true,
                'items' => [
                    [
                        'type' => 'Label',
                        'caption' => 'Einmalig mit dem oben gewählten SymDo-Gateway koppeln. Die Verbindung wird für Hausaufgaben, Familienzuordnung und Kalender verwendet. Das Modul erscheint als Gerät „Schulmanager Sync“.'
                    ],
                    ['type' => 'Label', 'name' => 'SymDoStatusLabel', 'caption' => $this->SymDoStatusText()],
                    [
                        'type' => 'RowLayout',
                        'items' => [
                            [
                                'type' => 'Button',
                                'caption' => 'Mit SymDo verbinden',
                                'onClick' => 'IPS_RequestAction($id, \'PairSymDo\', 0);'
                            ],
                            [
                                'type' => 'Button',
                                'caption' => 'SymDo-Verbindung testen',
                                'onClick' => 'IPS_RequestAction($id, \'TestSymDo\', 0);'
                            ],
                            [
                                'type' => 'Button',
                                'caption' => 'SymDo-Verbindung trennen',
                                'onClick' => 'IPS_RequestAction($id, \'DisconnectSymDo\', 0);'
                            ]
                        ]
                    ]
                ]
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => 'Test und Übernahme',
                'expanded' => true,
                'items' => [
                    ['type' => 'Label', 'name' => 'StatusLabel', 'caption' => $status],
                    [
                        'type' => 'RowLayout',
                        'items' => [
                            [
                                'type' => 'Button',
                                'caption' => 'Trockenlauf',
                                'onClick' => 'IPS_RequestAction($id, \'DryRun\', 0);'
                            ],
                            [
                                'type' => 'Button',
                                'caption' => 'Jetzt abrufen und übernehmen',
                                'onClick' => 'IPS_RequestAction($id, \'UpdateNow\', 0);'
                            ],
                            [
                                'type' => 'Button',
                                'caption' => 'Wochenvorlage aus Schulmanager übernehmen',
                                'confirm' => 'Die regulären Schulstunden der nächsten Wochen werden als feste Wochenvorlage in die gewählte SymDo-Stundenplaninstanz geschrieben. Fortfahren?',
                                'onClick' => 'IPS_RequestAction($id, \'ImportTemplate\', 0);'
                            ]
                        ]
                    ],
                    [
                        'type' => 'Label',
                        'caption' => 'Die datierten Schulmanager-Tage bleiben immer die aktuelle Ebene. Die Wochenvorlage ist nur die feste Grundbelegung für weiter entfernte Wochen.'
                    ]
                ]
            ]
        ];

        return json_encode(['elements' => $elements], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function TestConnection(): string
    {
        if ($this->IsPaused()) {
            $this->ResetFailures();
        }

        try {
            $login = $this->Login();
            $this->ResetFailures();
            $students = $login['students'];
            $institution = (int)($login['institutionId'] ?? 0);
            $text = 'Verbindung OK — ' . count($students) . ' Kind(er) gefunden';
            if ($institution > 0) {
                $text .= ', Institution ' . $institution;
            }
            $this->SetStatusText($text);
            return $text;
        } catch (Throwable $e) {
            $this->CountFailure();
            $text = 'Verbindung fehlgeschlagen: ' . $e->getMessage();
            $this->SetStatusText($text);
            return $text;
        }
    }

    private function FetchStudents(): string
    {
        try {
            $login = $this->Login();
            $this->ResetFailures();
            $students = $login['students'];
            $this->WriteAttributeString('DetectedStudents', json_encode($students, JSON_UNESCAPED_UNICODE));

            $mappings = $this->Mappings(false);
            $known = [];
            foreach ($mappings as $row) {
                $known[(string)($row['studentId'] ?? '')] = true;
            }

            foreach ($students as $student) {
                $sid = (string)$student['id'];
                if (isset($known[$sid])) {
                    continue;
                }
                $first = trim((string)($student['firstName'] ?? ''));
                $full = trim((string)($student['name'] ?? ''));
                $mappings[] = [
                    'enabled' => true,
                    'studentId' => $sid,
                    'symdoChild' => $first !== '' ? $first : $full
                ];
            }

            IPS_SetProperty($this->InstanceID, 'Mappings', json_encode($mappings, JSON_UNESCAPED_UNICODE));
            IPS_ApplyChanges($this->InstanceID);

            $this->UpdateFormField('Mappings', 'values', json_encode($mappings, JSON_UNESCAPED_UNICODE));
            $this->UpdateFormField('Mappings', 'columns', json_encode($this->MappingColumns($students), JSON_UNESCAPED_UNICODE));

            $text = count($students) . ' Kind(er) abgerufen — Zuordnung prüfen und „Übernehmen“ drücken.';
            $this->SetStatusText($text);
            return $text;
        } catch (Throwable $e) {
            $this->CountFailure();
            $text = 'Kinder konnten nicht abgerufen werden: ' . $e->getMessage();
            $this->SetStatusText($text);
            return $text;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function MappingColumns(array $students): array
    {
        $studentOptions = [['caption' => '— auswählen —', 'value' => '']];
        foreach ($students as $student) {
            $studentOptions[] = [
                'caption' => (string)$student['name'],
                'value' => (string)$student['id']
            ];
        }
        return [
            ['caption' => 'Aktiv', 'name' => 'enabled', 'width' => '70px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
            ['caption' => 'Schulmanager-Kind', 'name' => 'studentId', 'width' => '260px', 'add' => '', 'edit' => ['type' => 'Select', 'options' => $studentOptions]],
            ['caption' => 'SymDo-Kindname', 'name' => 'symdoChild', 'width' => '220px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']]
        ];
    }

    private function RunUpdate(bool $apply): string
    {
        if ($this->IsPaused()) {
            $remaining = max(1, (int)ceil((self::FAIL_PAUSE - (time() - $this->ReadAttributeInteger('LoginFailAt'))) / 60));
            $text = 'Nach wiederholten Loginfehlern noch ca. ' . $remaining . ' Minuten pausiert.';
            $this->SetStatusText($text);
            return $text;
        }

        $mappings = $this->Mappings();
        if ($mappings === []) {
            $text = 'Keine aktive Kinderzuordnung vorhanden.';
            $this->SetStatusText($text);
            return $text;
        }

        $timetableId = $this->ReadPropertyInteger('TimetableInstanceID');
        if ($timetableId <= 0 || !IPS_InstanceExists($timetableId)) {
            $text = 'Keine gültige SymDo-Stundenplaninstanz gewählt.';
            $this->SetStatusText($text);
            return $text;
        }

        if ($apply && !function_exists('STPL_ImportSlots')) {
            $text = 'STPL_ImportSlots fehlt. SymDo-Stundenplan ist nicht geladen oder der Kernel muss neu gestartet werden.';
            $this->SetStatusText($text);
            return $text;
        }

        $loginOk = false;
        try {
            $login = $this->Login();
            $loginOk = true;
            $this->ResetFailures();

            $studentMap = [];
            foreach ($login['students'] as $student) {
                $studentMap[(string)$student['id']] = $student;
            }

            $homeworkAll = [];
            $examsAll = [];
            $gradesAll = [];
            $parts = [];

            foreach ($mappings as $mapping) {
                $sid = (string)$mapping['studentId'];
                $child = trim((string)$mapping['symdoChild']);
                $student = $studentMap[$sid] ?? null;
                if (!is_array($student)) {
                    $parts[] = $sid . ': nicht am Konto gefunden';
                    continue;
                }
                if ($child === '') {
                    $parts[] = (string)$student['name'] . ': SymDo-Kindname fehlt';
                    continue;
                }

                $from = new DateTimeImmutable('today');
                $to = $from->modify('+' . self::PLAN_DAYS . ' days');
                $schedule = $this->ApiCall($login['token'], 'schedules', 'get-actual-lessons', [
                    'student' => ['id' => (int)$sid],
                    'start' => $from->format('Y-m-d'),
                    'end' => $to->format('Y-m-d')
                ]);

                if (!is_array($schedule)) {
                    $schedule = [];
                }

                $classId = $this->FindClassId($schedule);
                if ($classId <= 0) {
                    $rawStudent = is_array($student['raw'] ?? null) ? $student['raw'] : [];
                    $classId = (int)($rawStudent['classId'] ?? ($rawStudent['class']['id'] ?? 0));
                }

                $classHours = $classId > 0
                    ? $this->ApiCall($login['token'], 'schedules', 'get-class-hours', ['classId' => $classId])
                    : [];
                if (!is_array($classHours)) {
                    $classHours = [];
                }

                $exams = [];
                if ($this->ReadPropertyBoolean('ReadExams')) {
                    $examTo = $from->modify('+' . self::EXAM_DAYS . ' days');
                    try {
                        $e = $this->ApiCall($login['token'], 'exams', 'get-exams', [
                            'student' => ['id' => (int)$sid],
                            'start' => $from->format('Y-m-d'),
                            'end' => $examTo->format('Y-m-d')
                        ]);
                        $exams = is_array($e) ? $e : [];
                    } catch (Throwable $e) {
                        $exams = [];
                    }
                    $examsAll[$sid] = [
                        'child' => $child,
                        'name' => (string)$student['name'],
                        'items' => $exams,
                        'classHours' => $classHours
                    ];
                }

                if ($this->ReadPropertyBoolean('ReadGrades')) {
                    try {
                        $gradesAll[$sid] = [
                            'child' => $child,
                            'name' => (string)$student['name'],
                            'data' => $this->FetchGrades($login['token'], (int)$sid, $classId)
                        ];
                    } catch (Throwable $e) {
                        $gradesAll[$sid] = [
                            'child' => $child,
                            'name' => (string)$student['name'],
                            'data' => [
                                'subjects' => [],
                                'hasGrades' => false,
                                'error' => $e->getMessage()
                            ]
                        ];
                    }
                }

                [$days, $skipped] = $this->BuildDatedDays($schedule, $classHours, $from, $to, $exams);

                $written = 0;
                if ($apply) {
                    $payload = json_encode([
                        'child' => $child,
                        'source' => 'Schulmanager',
                        'days' => (object)$days
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $response = json_decode((string)@STPL_ImportSlots($timetableId, $payload), true);
                    if (($response['ok'] ?? false) !== true) {
                        $message = (string)($response['error']['message'] ?? 'Import abgelehnt');
                        $parts[] = (string)$student['name'] . ': ' . $message;
                        continue;
                    }
                    $written = (int)($response['slots'] ?? 0);
                } else {
                    foreach ($days as $slots) {
                        $written += count($slots);
                    }
                }

                if ($this->ReadPropertyBoolean('ReadHomework')) {
                    try {
                        $h = $this->ApiCall($login['token'], 'classbook', 'get-homework', [
                            'student' => ['id' => (int)$sid]
                        ]);
                        $homeworkAll[$sid] = [
                            'child' => $child,
                            'name' => (string)$student['name'],
                            'items' => is_array($h) ? $h : []
                        ];
                    } catch (Throwable $e) {
                        $homeworkAll[$sid] = [
                            'child' => $child,
                            'name' => (string)$student['name'],
                            'items' => [],
                            'error' => $e->getMessage()
                        ];
                    }
                }

                $parts[] = sprintf(
                    '%s: %d Stunde(n)%s',
                    (string)$student['name'],
                    $written,
                    $skipped > 0 ? ', ' . $skipped . ' ohne Zeit übersprungen' : ''
                );
            }

            $letters = [];
            if ($this->ReadPropertyBoolean('ReadLetters')) {
                try {
                    $l = $this->ApiCall($login['token'], 'letters', 'get-letters', []);
                    $letters = is_array($l) ? $this->EnrichLetters($login['token'], $l) : [];
                } catch (Throwable $e) {
                    $letters = [];
                }
            }

            $this->StoreData($homeworkAll, $examsAll, $gradesAll, $letters);

            if ($apply
                && $this->ReadPropertyBoolean('ReadHomework')
                && $this->ReadPropertyBoolean('SyncHomeworkToSymDo')) {
                try {
                    $parts[] = $this->SyncHomeworkToSymDo($homeworkAll);
                } catch (Throwable $e) {
                    $parts[] = 'SymDo-Hausaufgaben: FEHLER — ' . $e->getMessage();
                }
            }

            if ($apply
                && $this->ReadPropertyBoolean('ReadExams')
                && $this->ReadPropertyBoolean('SyncExamsToCalendar')) {
                try {
                    $parts[] = $this->SyncExamsToCalendar($examsAll);
                } catch (Throwable $e) {
                    $parts[] = 'Kalender: FEHLER — ' . $e->getMessage();
                }
            }

            $prefix = $apply ? 'Übernommen' : 'Trockenlauf';
            $text = $prefix . ' — ' . implode(' | ', $parts);
            if ($letters !== []) {
                $text .= ' | Elternbriefe: ' . count($letters);
            }
            $this->SetStatusText($text, true);
            return $text;
        } catch (Throwable $e) {
            if (!$loginOk) {
                $this->CountFailure();
            }
            $text = 'Abruf fehlgeschlagen: ' . $e->getMessage();
            $this->SetStatusText($text);
            return $text;
        }
    }

    private function ImportWeeklyTemplate(): string
    {
        $mappings = $this->Mappings();
        if ($mappings === []) {
            return 'Keine aktive Kinderzuordnung vorhanden.';
        }
        $timetableId = $this->ReadPropertyInteger('TimetableInstanceID');
        if ($timetableId <= 0 || !IPS_InstanceExists($timetableId) || !function_exists('STPL_ImportSlots')) {
            return 'Keine gültige SymDo-Stundenplaninstanz oder STPL_ImportSlots fehlt.';
        }

        $loginOk = false;
        try {
            $login = $this->Login();
            $loginOk = true;
            $this->ResetFailures();
            $studentMap = [];
            foreach ($login['students'] as $student) {
                $studentMap[(string)$student['id']] = $student;
            }

            $parts = [];
            foreach ($mappings as $mapping) {
                $sid = (string)$mapping['studentId'];
                $child = trim((string)$mapping['symdoChild']);
                $student = $studentMap[$sid] ?? null;
                if (!is_array($student) || $child === '') {
                    continue;
                }

                $from = new DateTimeImmutable('today');
                $to = $from->modify('+' . self::TEMPLATE_DAYS . ' days');
                $schedule = $this->ApiCall($login['token'], 'schedules', 'get-actual-lessons', [
                    'student' => ['id' => (int)$sid],
                    'start' => $from->format('Y-m-d'),
                    'end' => $to->format('Y-m-d')
                ]);
                if (!is_array($schedule)) {
                    $schedule = [];
                }
                $classId = $this->FindClassId($schedule);
                $classHours = $classId > 0
                    ? $this->ApiCall($login['token'], 'schedules', 'get-class-hours', ['classId' => $classId])
                    : [];
                if (!is_array($classHours)) {
                    $classHours = [];
                }

                $weekdays = $this->BuildWeeklyTemplate($schedule, $classHours);
                if ($weekdays === []) {
                    $parts[] = (string)$student['name'] . ': keine regulären Stunden gefunden';
                    continue;
                }

                $payload = json_encode([
                    'child' => $child,
                    'source' => 'Schulmanager Vorlage',
                    'days' => (object)$weekdays
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $response = json_decode((string)@STPL_ImportSlots($timetableId, $payload), true);
                if (($response['ok'] ?? false) !== true) {
                    $parts[] = (string)$student['name'] . ': ' . (string)($response['error']['message'] ?? 'Vorlage abgelehnt');
                    continue;
                }
                $parts[] = (string)$student['name'] . ': ' . (int)($response['slots'] ?? 0) . ' Wochenstunden';
            }

            $text = 'Wochenvorlage übernommen — ' . implode(' | ', $parts);
            $this->SetStatusText($text, true);
            return $text;
        } catch (Throwable $e) {
            if (!$loginOk) {
                $this->CountFailure();
            }
            $text = 'Wochenvorlage fehlgeschlagen: ' . $e->getMessage();
            $this->SetStatusText($text);
            return $text;
        }
    }

    /** @return array{0: array<string, array<int, array<string, mixed>>>, 1: int} */
    private function BuildDatedDays(
        array $schedule,
        array $classHours,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $exams
    ): array {
        $days = [];
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            if ((int)$d->format('N') <= 5) {
                $days[$d->format('Y-m-d')] = [];
            }
        }

        $hoursById = [];
        $hoursByNumber = [];
        foreach ($classHours as $hour) {
            if (!is_array($hour)) {
                continue;
            }
            if ((int)($hour['id'] ?? 0) > 0) {
                $hoursById[(int)$hour['id']] = $hour;
            }
            $n = trim((string)($hour['number'] ?? ''));
            if ($n !== '') {
                $hoursByNumber[$n] = $hour;
            }
        }

        $slotIndex = [];
        $skipped = 0;

        foreach ($schedule as $lesson) {
            if (!is_array($lesson)) {
                continue;
            }
            $date = trim((string)($lesson['date'] ?? ''));
            if ($date === '' || $date < $from->format('Y-m-d') || $date > $to->format('Y-m-d')) {
                continue;
            }
            $number = trim((string)($lesson['classHour']['number'] ?? ''));
            $hourId = (int)($lesson['classHour']['id'] ?? 0);
            $hour = $hoursById[$hourId] ?? ($hoursByNumber[$number] ?? null);
            if (!is_array($hour)) {
                $skipped++;
                continue;
            }

            [$start, $end] = $this->HourTimes($hour, $date);
            if ($start === '' || $end === '') {
                $skipped++;
                continue;
            }

            $slot = $this->LessonToSlot($lesson, $start, $end);
            if ($slot === null) {
                $skipped++;
                continue;
            }

            $days[$date] ??= [];
            $days[$date][] = $slot;
            $slotIndex[$date][$number] = count($days[$date]) - 1;
        }

        foreach ($exams as $exam) {
            if (!is_array($exam)) {
                continue;
            }
            $date = trim((string)($exam['date'] ?? ''));
            $number = trim((string)($exam['startClassHour']['number'] ?? ''));
            if ($date === '' || $number === '' || !isset($slotIndex[$date][$number], $days[$date])) {
                continue;
            }
            $idx = (int)$slotIndex[$date][$number];
            if (!isset($days[$date][$idx])) {
                continue;
            }
            $title = trim((string)($exam['type']['name'] ?? 'Klassenarbeit'));
            $comment = trim((string)($exam['comment'] ?? ''));
            if ($comment !== '') {
                $title .= ': ' . $comment;
            }
            $days[$date][$idx]['exam'] = true;
            $days[$date][$idx]['examTitle'] = mb_substr($title, 0, 80);
        }

        foreach ($days as &$slots) {
            usort($slots, static fn(array $a, array $b): int => strcmp((string)$a['start'], (string)$b['start']));
            foreach ($slots as &$slot) {
                unset($slot['_hour']);
            }
            unset($slot);
        }
        unset($slots);

        return [$days, $skipped];
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    private function BuildWeeklyTemplate(array $schedule, array $classHours): array
    {
        $hoursById = [];
        $hoursByNumber = [];
        foreach ($classHours as $hour) {
            if (!is_array($hour)) {
                continue;
            }
            if ((int)($hour['id'] ?? 0) > 0) {
                $hoursById[(int)$hour['id']] = $hour;
            }
            $n = trim((string)($hour['number'] ?? ''));
            if ($n !== '') {
                $hoursByNumber[$n] = $hour;
            }
        }

        $candidates = [];
        foreach ($schedule as $lesson) {
            if (!is_array($lesson) || (string)($lesson['type'] ?? '') !== 'regularLesson') {
                continue;
            }
            $date = trim((string)($lesson['date'] ?? ''));
            if ($date === '') {
                continue;
            }
            $weekday = (int)date('N', strtotime($date . ' 12:00:00'));
            if ($weekday < 1 || $weekday > 5) {
                continue;
            }
            $number = trim((string)($lesson['classHour']['number'] ?? ''));
            $hourId = (int)($lesson['classHour']['id'] ?? 0);
            $hour = $hoursById[$hourId] ?? ($hoursByNumber[$number] ?? null);
            if (!is_array($hour)) {
                continue;
            }
            [$start, $end] = $this->HourTimes($hour, $date);
            if ($start === '' || $end === '') {
                continue;
            }
            $slot = $this->LessonToSlot($lesson, $start, $end);
            if ($slot === null) {
                continue;
            }
            $slot['status'] = 'normal';
            $key = $weekday . '|' . $number;
            $fingerprint = json_encode([
                $slot['subject'], $slot['start'], $slot['end'], $slot['room'], $slot['teacher']
            ], JSON_UNESCAPED_UNICODE);
            $candidates[$key][$fingerprint]['count'] = (int)($candidates[$key][$fingerprint]['count'] ?? 0) + 1;
            $candidates[$key][$fingerprint]['slot'] = $slot;
        }

        $days = [];
        foreach ($candidates as $key => $variants) {
            [$weekday] = explode('|', $key, 2);
            uasort($variants, static fn(array $a, array $b): int => ((int)$b['count']) <=> ((int)$a['count']));
            $best = reset($variants);
            if (!is_array($best) || !isset($best['slot'])) {
                continue;
            }
            $slot = $best['slot'];
            unset($slot['_hour']);
            $days[(int)$weekday][] = $slot;
        }

        foreach ($days as &$slots) {
            usort($slots, static fn(array $a, array $b): int => strcmp((string)$a['start'], (string)$b['start']));
        }
        unset($slots);
        ksort($days);
        return $days;
    }

    private function LessonToSlot(array $lesson, string $start, string $end): ?array
    {
        $type = (string)($lesson['type'] ?? '');
        $subject = '';
        $room = '';
        $teacher = '';
        $status = 'normal';
        $insteadOf = '';
        $notes = trim((string)($lesson['comment'] ?? ''));

        if ($type === 'regularLesson' || $type === 'changedLesson') {
            $actual = is_array($lesson['actualLesson'] ?? null) ? $lesson['actualLesson'] : [];
            $subject = trim((string)($actual['subject']['name'] ?? ($actual['subjectLabel'] ?? '')));
            $room = trim((string)($actual['room']['name'] ?? ''));
            $teacher = $this->TeacherText((array)($actual['teachers'] ?? []));
            if ($type === 'changedLesson') {
                $status = 'vertretung';
                $original = is_array(($lesson['originalLessons'][0] ?? null)) ? $lesson['originalLessons'][0] : [];
                $insteadOf = $this->TeacherText((array)($original['teachers'] ?? []));
                if ($notes === '') {
                    $notes = trim((string)($actual['comment'] ?? ''));
                }
            }
        } elseif ($type === 'cancelledLesson') {
            $original = is_array(($lesson['originalLessons'][0] ?? null)) ? $lesson['originalLessons'][0] : [];
            $subject = trim((string)($original['subject']['name'] ?? ($original['subjectLabel'] ?? '')));
            $room = trim((string)($original['room']['name'] ?? ''));
            $teacher = $this->TeacherText((array)($original['teachers'] ?? []));
            $status = 'entfall';
        } elseif ($type === 'event') {
            $event = is_array($lesson['event'] ?? null) ? $lesson['event'] : [];
            $subject = trim((string)($event['text'] ?? 'Schultermin'));
            $room = trim((string)($event['rooms'][0]['name'] ?? ''));
            $teacher = $this->TeacherText((array)($event['teachers'] ?? []));
            $status = 'termin';
        } else {
            return null;
        }

        if ($subject === '') {
            $subject = $status === 'termin' ? 'Schultermin' : 'Unterricht';
        }

        $slot = [
            'subject' => $subject,
            'start' => $start,
            'end' => $end,
            'room' => $room,
            'teacher' => $teacher,
            'status' => $status,
            'insteadOf' => $insteadOf,
            '_hour' => trim((string)($lesson['classHour']['number'] ?? ''))
        ];
        if ($notes !== '') {
            $slot['notes'] = mb_substr($notes, 0, 500);
        }
        return $slot;
    }

    private function TeacherText(array $teachers): string
    {
        $names = [];
        foreach ($teachers as $teacher) {
            if (!is_array($teacher)) {
                continue;
            }
            $name = trim((string)($teacher['abbreviation'] ?? ''));
            if ($name === '') {
                $name = trim((string)($teacher['lastname'] ?? ($teacher['lastName'] ?? '')));
            }
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return implode(', ', array_values(array_unique($names)));
    }

    /** @return array{0:string,1:string} */
    private function HourTimes(array $hour, string $date): array
    {
        $idx = max(0, min(6, (int)date('N', strtotime($date . ' 12:00:00')) - 1));
        $from = trim((string)($hour['fromByDay'][$idx] ?? ($hour['from'] ?? '')));
        $until = trim((string)($hour['untilByDay'][$idx] ?? ($hour['until'] ?? '')));
        return [$this->TimeHHMM($from), $this->TimeHHMM($until)];
    }

    private function TimeHHMM(string $value): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', trim($value), $m) !== 1) {
            return '';
        }
        $h = (int)$m[1];
        $min = (int)$m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            return '';
        }
        return sprintf('%02d:%02d', $h, $min);
    }

    private function FindClassId(array $schedule): int
    {
        foreach ($schedule as $lesson) {
            if (!is_array($lesson)) {
                continue;
            }
            $sources = [];
            if (is_array($lesson['actualLesson'] ?? null)) {
                $sources[] = $lesson['actualLesson'];
            }
            if (is_array($lesson['originalLessons'][0] ?? null)) {
                $sources[] = $lesson['originalLessons'][0];
            }
            if (is_array($lesson['event'] ?? null)) {
                $sources[] = $lesson['event'];
            }
            foreach ($sources as $src) {
                foreach ((array)($src['classes'] ?? []) as $class) {
                    if (is_array($class) && (int)($class['id'] ?? 0) > 0) {
                        return (int)$class['id'];
                    }
                }
                foreach ((array)($src['studentGroups'] ?? []) as $group) {
                    if (is_array($group) && (int)($group['classId'] ?? 0) > 0) {
                        return (int)$group['classId'];
                    }
                }
            }
        }
        return 0;
    }

    /** @return array{token:string, students:array<int,array<string,mixed>>, institutionId:int} */
    private function Login(): array
    {
        $email = trim($this->ReadPropertyString('Email'));
        $password = $this->ReadPropertyString('Password');
        if ($email === '' || $password === '') {
            throw new Exception('E-Mail/Benutzername oder Passwort fehlt.');
        }

        $saltResponse = $this->RequestJson(self::SALT_URL, [
            'emailOrUsername' => $email,
            'mobileApp' => false
        ]);
        if (is_string($saltResponse)) {
            $salt = $saltResponse;
        } elseif (is_array($saltResponse)) {
            $salt = (string)($saltResponse['salt'] ?? '');
        } else {
            $salt = '';
        }
        if ($salt === '') {
            throw new Exception('Login-Salt konnte nicht gelesen werden.');
        }

        $binaryHash = hash_pbkdf2(
            'sha512',
            $password,
            $salt,
            self::PBKDF2_ITERATIONS,
            self::PBKDF2_DK_LEN,
            true
        );
        $hash = bin2hex($binaryHash);
        $institutionId = max(0, $this->ReadPropertyInteger('InstitutionID'));

        $login = $this->RequestJson(self::LOGIN_URL, [
            'emailOrUsername' => $email,
            'password' => $password,
            'hash' => $hash,
            'mobileApp' => false,
            'institutionId' => $institutionId > 0 ? $institutionId : null
        ]);
        if (!is_array($login)) {
            throw new Exception('Unerwartete Login-Antwort.');
        }

        $token = trim((string)($login['jwt'] ?? ($login['token'] ?? '')));
        if ($token === '') {
            throw new Exception('Login fehlgeschlagen — kein Token erhalten.');
        }

        $user = is_array($login['user'] ?? null) ? $login['user'] : [];
        $students = [];
        foreach ((array)($user['associatedParents'] ?? []) as $link) {
            if (!is_array($link) || !is_array($link['student'] ?? null)) {
                continue;
            }
            $student = $this->NormalizeStudent($link['student']);
            if ($student !== null) {
                $students[] = $student;
            }
        }
        if ($students === [] && is_array($user['associatedStudent'] ?? null)) {
            $student = $this->NormalizeStudent($user['associatedStudent']);
            if ($student !== null) {
                $students[] = $student;
            }
        }

        $foundInstitution = (int)($user['institutionId'] ?? ($login['institutionId'] ?? $institutionId));
        return [
            'token' => $token,
            'students' => $students,
            'institutionId' => $foundInstitution
        ];
    }

    private function NormalizeStudent(array $student): ?array
    {
        $id = (int)($student['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $first = trim((string)($student['firstname'] ?? ($student['firstName'] ?? '')));
        $last = trim((string)($student['lastname'] ?? ($student['lastName'] ?? '')));
        $name = trim($first . ' ' . $last);
        if ($name === '') {
            $name = trim((string)($student['name'] ?? ('ID ' . $id)));
        }
        return [
            'id' => $id,
            'firstName' => $first,
            'lastName' => $last,
            'name' => $name,
            'raw' => $student
        ];
    }

    private function ApiCall(string $token, string $module, string $endpoint, array $parameters)
    {
        $bundle = $this->BundleVersion();
        $body = $this->RequestJson(self::CALLS_URL, [
            'bundleVersion' => $bundle,
            'requests' => [[
                'moduleName' => $module,
                'endpointName' => $endpoint,
                'parameters' => (object)$parameters
            ]]
        ], $token);

        $results = is_array($body) ? ($body['results'] ?? []) : [];
        if (!is_array($results) || $results === []) {
            throw new Exception($module . '/' . $endpoint . ': leere Antwort.');
        }
        $first = $results[0];
        if (!is_array($first)) {
            throw new Exception($module . '/' . $endpoint . ': ungültige Antwort.');
        }
        $status = (int)($first['status'] ?? 200);
        if ($status < 200 || $status >= 300) {
            throw new Exception($module . '/' . $endpoint . ': Status ' . $status);
        }
        if (array_key_exists('data', $first)) {
            return $first['data'];
        }
        return $first;
    }


    /**
     * Noten des laufenden Schuljahres. Schulmanager nutzt hierfür NICHT den alten
     * get-grades-Endpunkt, sondern get-grading-information-for-student.
     *
     * @return array<string,mixed>
     */
    private function FetchGrades(string $token, int $studentId, int $classId): array
    {
        $today = new DateTimeImmutable('today');
        if ((int)$today->format('n') >= 8) {
            $start = new DateTimeImmutable($today->format('Y') . '-08-01');
            $end = new DateTimeImmutable(((int)$today->format('Y') + 1) . '-07-31');
        } else {
            $start = new DateTimeImmutable(((int)$today->format('Y') - 1) . '-08-01');
            $end = new DateTimeImmutable($today->format('Y') . '-07-31');
        }

        $termId = 0;
        if ($classId > 0) {
            try {
                $classes = $this->ApiCall($token, 'grades', 'poqa', [
                    'action' => [
                        'model' => 'main/class',
                        'action' => 'findAll',
                        'parameters' => [[
                            'where' => ['id' => $classId]
                        ]]
                    ],
                    'uiState' => 'main.modules.grades.student'
                ]);
                if (is_array($classes) && is_array($classes[0] ?? null)) {
                    $termId = (int)($classes[0]['termId'] ?? 0);
                }
            } catch (Throwable $e) {
                $termId = 0;
            }
        }

        $params = [
            'studentId' => $studentId,
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'gradingPeriodType' => 'entireYear'
        ];
        if ($termId > 0) {
            $params['termId'] = $termId;
        }

        $raw = $this->ApiCall(
            $token,
            'grades',
            'get-grading-information-for-student',
            $params
        );
        if (!is_array($raw)) {
            $raw = [];
        }

        $subjectMap = [];
        $hasRawGrades = (array)($raw['gradingEvents'] ?? []) !== []
            || (array)($raw['finalGrades'] ?? []) !== [];
        if ($hasRawGrades) {
            try {
                $subjects = $this->ApiCall($token, 'grades', 'poqa', [
                    'action' => [
                        'model' => 'main/subject',
                        'action' => 'findAll',
                        'parameters' => [[
                            'attributes' => ['id', 'name', 'abbreviation', 'orderIndex', 'officialKey']
                        ]]
                    ],
                    'uiState' => 'main.modules.grades.student'
                ]);
                foreach (is_array($subjects) ? $subjects : [] as $subject) {
                    if (!is_array($subject)) {
                        continue;
                    }
                    $id = (int)($subject['id'] ?? 0);
                    if ($id > 0) {
                        $subjectMap[$id] = [
                            'name' => trim((string)($subject['name'] ?? '')),
                            'abbreviation' => trim((string)($subject['abbreviation'] ?? ''))
                        ];
                    }
                }
            } catch (Throwable $e) {
                // Kursname ist ein brauchbarer Rückfall.
            }
        }

        return $this->NormalizeGrades($raw, $subjectMap, $start, $end);
    }

    /** @return array<string,mixed> */
    private function NormalizeGrades(
        array $raw,
        array $subjectMap,
        DateTimeImmutable $schoolYearStart,
        DateTimeImmutable $schoolYearEnd
    ): array {
        $courses = [];
        foreach ((array)($raw['courses'] ?? []) as $course) {
            if (!is_array($course)) {
                continue;
            }
            $id = (int)($course['id'] ?? 0);
            if ($id > 0) {
                $courses[$id] = $course;
            }
        }

        $typeNames = [];
        foreach ((array)($raw['typePresets'] ?? []) as $preset) {
            if (!is_array($preset) || !is_array($preset['gradeType'] ?? null)) {
                continue;
            }
            $type = $preset['gradeType'];
            $id = (int)($type['id'] ?? 0);
            if ($id > 0) {
                $typeNames[$id] = trim((string)($type['name'] ?? ($type['abbreviation'] ?? '')));
            }
        }

        $blockNames = [];
        $blockWeights = [];
        foreach ((array)($raw['blockPresets'] ?? []) as $preset) {
            if (!is_array($preset) || !is_array($preset['gradingBlock'] ?? null)) {
                continue;
            }
            $courseId = (int)($preset['courseId'] ?? 0);
            $block = $preset['gradingBlock'];
            $blockId = (int)($block['id'] ?? 0);
            if ($courseId <= 0 || $blockId <= 0) {
                continue;
            }
            $blockNames[$blockId] = trim((string)($block['name'] ?? ('Block ' . $blockId)));
            $weight = (float)($preset['weighting'] ?? 0);
            if ($weight > 0) {
                $blockWeights[$courseId][$blockId] = $weight;
            }
        }

        $subjects = [];
        foreach ((array)($raw['gradingEvents'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $courseId = (int)($event['courseId'] ?? 0);
            $course = $courses[$courseId] ?? [];
            $subjectId = (int)($course['subjectId'] ?? 0);
            if ($subjectId <= 0) {
                continue;
            }
            $name = trim((string)($subjectMap[$subjectId]['name'] ?? ''));
            if ($name === '') {
                $name = trim((string)($course['name'] ?? ''));
            }
            if ($name === '') {
                $name = 'Fach ' . $subjectId;
            }

            if (!isset($subjects[$subjectId])) {
                $subjects[$subjectId] = [
                    'name' => $name,
                    'abbreviation' => trim((string)($subjectMap[$subjectId]['abbreviation'] ?? '')),
                    'categories' => [],
                    'average' => null,
                    'averageWeighted' => false
                ];
            }

            $blockId = (int)($event['gradingBlockId'] ?? 0);
            $typeId = (int)($event['gradeTypeId'] ?? 0);
            $category = trim((string)($blockNames[$blockId] ?? ($typeNames[$typeId] ?? 'Sonstige')));
            if ($category === '') {
                $category = 'Sonstige';
            }
            if (!isset($subjects[$subjectId]['categories'][$category])) {
                $subjects[$subjectId]['categories'][$category] = [
                    'grades' => [],
                    'average' => null,
                    'blockId' => $blockId,
                    'blockWeight' => (float)($blockWeights[$courseId][$blockId] ?? 0)
                ];
            }

            $eventWeight = max(0.0, (float)($event['weighting'] ?? 1));
            if ($eventWeight <= 0) {
                $eventWeight = 1.0;
            }
            foreach ((array)($event['grades'] ?? []) as $grade) {
                if (!is_array($grade)) {
                    continue;
                }
                $rawValue = $grade['value'] ?? null;
                if ($rawValue === null || $rawValue === '') {
                    continue;
                }
                $display = $this->GradeDisplay($rawValue);
                $numeric = $this->GradeNumeric($display);
                $subjects[$subjectId]['categories'][$category]['grades'][] = [
                    'value' => $display,
                    'numeric' => $numeric,
                    'date' => trim((string)($event['date'] ?? '')),
                    'topic' => trim((string)($event['topic'] ?? '')),
                    'weight' => $eventWeight,
                    'repeat' => ($grade['isRepeatExam'] ?? false) === true
                ];
            }
        }

        foreach ($subjects as &$subject) {
            $weightedBlocks = [];
            foreach ($subject['categories'] as &$category) {
                $sum = 0.0;
                $weights = 0.0;
                foreach ($category['grades'] as $grade) {
                    if (!is_array($grade) || !is_numeric($grade['numeric'] ?? null)) {
                        continue;
                    }
                    $w = max(0.01, (float)($grade['weight'] ?? 1));
                    $sum += (float)$grade['numeric'] * $w;
                    $weights += $w;
                }
                if ($weights > 0) {
                    $category['average'] = round($sum / $weights, 2);
                    if ((float)$category['blockWeight'] > 0) {
                        $weightedBlocks[] = [
                            'average' => (float)$category['average'],
                            'weight' => (float)$category['blockWeight']
                        ];
                    }
                }
            }
            unset($category);

            if ($weightedBlocks !== []) {
                $sum = 0.0;
                $weights = 0.0;
                foreach ($weightedBlocks as $block) {
                    $sum += $block['average'] * $block['weight'];
                    $weights += $block['weight'];
                }
                if ($weights > 0) {
                    $subject['average'] = round($sum / $weights, 2);
                    $subject['averageWeighted'] = true;
                }
            }
        }
        unset($subject);

        uasort($subjects, static fn(array $a, array $b): int =>
            strcasecmp((string)$a['name'], (string)$b['name'])
        );

        $hasGrades = false;
        foreach ($subjects as $subject) {
            foreach ((array)($subject['categories'] ?? []) as $category) {
                if ((array)($category['grades'] ?? []) !== []) {
                    $hasGrades = true;
                    break 2;
                }
            }
        }

        return [
            'schoolYear' => $this->SchoolYearLabel($schoolYearStart),
            'schoolYearStart' => $schoolYearStart->format('Y-m-d'),
            'schoolYearEnd' => $schoolYearEnd->format('Y-m-d'),
            'hasGrades' => $hasGrades,
            'subjects' => array_values($subjects)
        ];
    }

    private function GradeDisplay(mixed $value): string
    {
        $display = trim((string)$value);
        if (str_contains($display, '~')) {
            $parts = explode('~', $display);
            $display = trim((string)end($parts));
        }
        return $display;
    }

    private function GradeNumeric(string $display): ?float
    {
        $v = trim($display);
        if ($v === '') {
            return null;
        }
        // Plus/Minus bleibt sichtbar; für den rechnerischen Blockdurchschnitt wird
        // bewusst nur die Grundnote verwendet, solange Schulmanager keinen exakten
        // numerischen Wert dafür liefert.
        $v = rtrim($v, '+-');
        return is_numeric($v) ? (float)$v : null;
    }

    private function SchoolYearLabel(DateTimeImmutable $start): string
    {
        $y = (int)$start->format('Y');
        return $y . '/' . substr((string)($y + 1), -2);
    }

    private function BundleVersion(): string
    {
        $cached = trim($this->ReadAttributeString('BundleVersion'));
        $at = $this->ReadAttributeInteger('BundleVersionAt');
        if ($cached !== '' && $at > 0 && date('Y-m-d', $at) === date('Y-m-d')) {
            return $cached;
        }

        $version = $this->DetectBundleVersion();
        if ($version === '') {
            $version = $cached !== '' ? $cached : self::BUNDLE_FALLBACK;
        }
        $this->WriteAttributeString('BundleVersion', $version);
        $this->WriteAttributeInteger('BundleVersionAt', time());
        return $version;
    }

    private function DetectBundleVersion(): string
    {
        try {
            $html = $this->RequestText(self::BASE);
            preg_match_all('~src=["\'](/[^"\']*\.js[^"\']*)["\']~i', $html, $matches);
            foreach (array_slice((array)($matches[1] ?? []), 0, 12) as $path) {
                try {
                    $js = $this->RequestText(self::BASE . $path);
                    foreach ([
                        '~bundleVersion["\'\s:]+["\']([a-f0-9]{8,})["\']~i',
                        '~["\']bundleVersion["\']\s*:\s*["\']([a-f0-9]{8,})["\']~i'
                    ] as $pattern) {
                        if (preg_match($pattern, $js, $m) === 1) {
                            return (string)$m[1];
                        }
                    }
                } catch (Throwable $e) {
                    // nächste JS-Datei
                }
            }
        } catch (Throwable $e) {
            // Fallback
        }
        return self::BUNDLE_FALLBACK;
    }

    private function RequestJson(string $url, ?array $payload = null, string $token = '')
    {
        $raw = $this->Request($url, $payload, $token);
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Ungültige JSON-Antwort: ' . json_last_error_msg());
        }
        return $data;
    }

    private function RequestText(string $url): string
    {
        return $this->Request($url, null, '');
    }

    private function Request(string $url, ?array $payload = null, string $token = ''): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new Exception('cURL konnte nicht gestartet werden.');
        }

        $headers = [
            'Accept: application/json, text/plain, */*',
            'User-Agent: Mozilla/5.0 IP-Symcon Schulmanager-SymDo/1.2'
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('HTTP-Fehler: ' . $error);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            throw new Exception('HTTP ' . $status . ' bei ' . parse_url($url, PHP_URL_PATH));
        }
        return (string)$response;
    }


    /**
     * Ergänzt die Elternbriefliste um Text, Anhänge und Empfängerstatus.
     * Bereits gelesene Details werden anhand updatedAt zwischengespeichert, damit
     * der 30-Minuten-Takt nicht jedes Mal jeden Brief neu abruft.
     *
     * @return array<int,array<string,mixed>>
     */
    private function EnrichLetters(string $token, array $letters): array
    {
        usort($letters, static function (array $a, array $b): int {
            $ad = (string)($a['sentDate'] ?? ($a['createdAt'] ?? ''));
            $bd = (string)($b['sentDate'] ?? ($b['createdAt'] ?? ''));
            return strcmp($bd, $ad);
        });

        $cache = json_decode($this->ReadAttributeString('LetterDetails'), true);
        $cache = is_array($cache) ? $cache : [];
        $keep = [];
        $out = [];

        $detailLimit = min(
            self::LETTER_DETAIL_MAX,
            max(10, $this->ReadPropertyInteger('LetterLimit') * max(1, count($this->Mappings())))
        );

        foreach ($letters as $index => $letter) {
            if (!is_array($letter)) {
                continue;
            }
            $id = (int)($letter['id'] ?? 0);
            if ($id <= 0 || $index >= $detailLimit) {
                $out[] = $letter;
                continue;
            }
            $stamp = trim((string)($letter['updatedAt'] ?? ($letter['sentDate'] ?? '')));
            $cached = is_array($cache[(string)$id] ?? null) ? $cache[(string)$id] : [];
            $detail = [];
            if ($cached !== [] && (string)($cached['_stamp'] ?? '') === $stamp) {
                $detail = is_array($cached['detail'] ?? null) ? $cached['detail'] : [];
            } else {
                $detail = $this->FetchLetterDetailForMappedStudents($token, $id);
            }

            if ($detail !== []) {
                foreach (['title', 'text', 'content', 'sentDate', 'createdAt', 'updatedAt', 'answerDeadline', 'attachments', 'studentStatuses'] as $key) {
                    if (array_key_exists($key, $detail)) {
                        $letter[$key] = $detail[$key];
                    }
                }
            }
            $keep[(string)$id] = ['_stamp' => $stamp, 'detail' => $detail];
            $out[] = $letter;
        }

        // Nur die aktuellen Details halten; alte Briefe außerhalb des Limits brauchen
        // keinen dauerhaften Cache.
        $this->WriteAttributeString(
            'LetterDetails',
            json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $out;
    }


    private function FetchLetterDetailForMappedStudents(string $token, int $letterId): array
    {
        $merged = [];
        $statuses = [];
        $attachments = [];
        $studentIds = [];
        foreach ($this->Mappings() as $mapping) {
            $sid = (int)($mapping['studentId'] ?? 0);
            if ($sid > 0) {
                $studentIds[] = $sid;
            }
        }
        $studentIds = array_values(array_unique($studentIds));
        if ($studentIds === []) {
            return [];
        }

        foreach ($studentIds as $studentId) {
            try {
                $fetched = $this->ApiCall($token, 'letters', 'poqa', [
                    'action' => [
                        'model' => 'modules/letters/letter',
                        'action' => 'findByPk',
                        'parameters' => [
                            $letterId,
                            [
                                'include' => [
                                    [
                                        'association' => 'attachments',
                                        'required' => false,
                                        'attributes' => ['id', 'filename', 'contentType', 'inline', 'letterId']
                                    ],
                                    [
                                        'association' => 'studentStatuses',
                                        'required' => true,
                                        'where' => ['studentId' => ['$in' => [$studentId]]],
                                        'include' => [[
                                            'association' => 'student',
                                            'required' => true
                                        ]]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'uiState' => 'main.modules.letters.view.details'
                ]);
            } catch (Throwable $e) {
                continue;
            }
            if (!is_array($fetched) || $fetched === []) {
                continue;
            }
            if ($merged === []) {
                $merged = $fetched;
            }
            foreach ((array)($fetched['studentStatuses'] ?? []) as $status) {
                if (!is_array($status)) {
                    continue;
                }
                $key = (string)($status['studentId'] ?? ($status['id'] ?? count($statuses)));
                $statuses[$key] = $status;
            }
            foreach ((array)($fetched['attachments'] ?? []) as $attachment) {
                if (!is_array($attachment)) {
                    continue;
                }
                $key = (string)($attachment['id'] ?? ($attachment['filename'] ?? count($attachments)));
                $attachments[$key] = $attachment;
            }
        }

        if ($merged !== []) {
            if ($statuses !== []) {
                $merged['studentStatuses'] = array_values($statuses);
            }
            if ($attachments !== []) {
                $merged['attachments'] = array_values($attachments);
            }
        }
        return $merged;
    }

    private function LetterBelongsToStudent(array $letter, string $studentId): bool
    {
        $statuses = (array)($letter['studentStatuses'] ?? []);
        if ($statuses === []) {
            return true;
        }
        foreach ($statuses as $status) {
            if (!is_array($status)) {
                continue;
            }
            if ((string)($status['studentId'] ?? '') === $studentId) {
                return true;
            }
            if (is_array($status['student'] ?? null)
                && (string)($status['student']['id'] ?? '') === $studentId) {
                return true;
            }
        }
        return false;
    }

    private function PlainLetterText(string $html): string
    {
        $text = preg_replace(
            ['~<\s*br\s*/?\s*>~i', '~</\s*p\s*>~i', '~</\s*div\s*>~i', '~</\s*li\s*>~i'],
            ["\n", "\n", "\n", "\n"],
            $html
        );
        $text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/u", "\n", $text);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text);
        return trim((string)$text);
    }

    private function SymDoGatewayID(): int
    {
        $configured = $this->ReadPropertyInteger('SymDoGatewayInstanceID');
        if ($configured > 0 && IPS_InstanceExists($configured)) {
            return $configured;
        }
        $ids = @IPS_GetInstanceListByModuleID('{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}');
        if (is_array($ids) && count($ids) === 1) {
            return (int)$ids[0];
        }
        return 0;
    }

    private function PairSymDo(): string
    {
        $gateway = $this->SymDoGatewayID();
        if ($gateway <= 0) {
            return 'Kein eindeutiges SymDo-Gateway gewählt.';
        }
        if (!function_exists('TGW_CreatePairing')) {
            return 'TGW_CreatePairing fehlt. SymDo-Gateway ist nicht geladen oder zu alt.';
        }
        try {
            $pair = json_decode((string)@TGW_CreatePairing($gateway), true);
            if (!is_array($pair)) {
                throw new Exception('Pairing-Antwort ist ungültig.');
            }
            $code = trim((string)($pair['code'] ?? ''));
            $connectUrl = rtrim(trim((string)($pair['connectUrl'] ?? '')), '/');
            if ($code === '' || $connectUrl === '') {
                throw new Exception('Symcon Connect ist nicht verfügbar oder der Pairing-Code fehlt.');
            }
            $api = $connectUrl . '/hook/lists/app/v1';
            $answer = $this->RequestJson($api . '/pair', [
                'code' => $code,
                'deviceName' => 'Schulmanager Sync',
                'model' => 'IP-Symcon Modul',
                'platform' => 'IP-Symcon',
                'appVersion' => '1.2'
            ]);
            if (!is_array($answer) || ($answer['ok'] ?? false) !== true) {
                throw new Exception('SymDo hat das Pairing abgelehnt.');
            }
            $token = trim((string)($answer['token'] ?? ''));
            if ($token === '') {
                throw new Exception('SymDo lieferte keinen Geräte-Token.');
            }
            $this->WriteAttributeString('SymDoApiBase', $api);
            $this->WriteAttributeString('SymDoToken', $token);
            return $this->TestSymDo();
        } catch (Throwable $e) {
            return 'SymDo-Verbindung fehlgeschlagen: ' . $e->getMessage();
        }
    }

    private function TestSymDo(): string
    {
        $api = rtrim($this->ReadAttributeString('SymDoApiBase'), '/');
        $token = trim($this->ReadAttributeString('SymDoToken'));
        if ($api === '' || $token === '') {
            return 'Noch nicht mit SymDo gekoppelt.';
        }
        try {
            $answer = $this->RequestJson($api . '/discovery', null, $token);
            if (!is_array($answer) || ($answer['ok'] ?? false) !== true) {
                throw new Exception('Discovery wurde abgelehnt.');
            }
            $users = is_array($answer['users'] ?? null) ? $answer['users'] : [];
            return 'SymDo verbunden — ' . count($users) . ' Familienmitglied(er) gefunden.';
        } catch (Throwable $e) {
            return 'SymDo-Verbindung fehlerhaft: ' . $e->getMessage();
        }
    }

    private function DisconnectSymDo(): string
    {
        $api = rtrim($this->ReadAttributeString('SymDoApiBase'), '/');
        $token = trim($this->ReadAttributeString('SymDoToken'));
        if ($api !== '' && $token !== '') {
            try {
                $this->RequestJson($api . '/unpair', ['reason' => 'Schulmanager Sync'], $token);
            } catch (Throwable $e) {
            }
        }
        $this->WriteAttributeString('SymDoApiBase', '');
        $this->WriteAttributeString('SymDoToken', '');
        $this->WriteAttributeString('HomeworkLinks', '{}');
        return 'SymDo-Verbindung getrennt.';
    }

    private function SymDoStatusText(): string
    {
        return trim($this->ReadAttributeString('SymDoToken')) !== ''
            ? 'SymDo ist gekoppelt. Mit „SymDo-Verbindung testen“ kann die Verbindung geprüft werden.'
            : 'Noch nicht mit SymDo gekoppelt.';
    }


    /** @return array<int,array{caption:string,value:int}> */
    private function CalendarOptions(): array
    {
        $options = [['caption' => '— keinen Kalender synchronisieren —', 'value' => 0]];
        $current = max(0, $this->ReadPropertyInteger('CalendarID'));
        $known = [];
        foreach ($this->SymDoCalendars() as $calendar) {
            if (!is_array($calendar)) {
                continue;
            }
            $id = (int)($calendar['id'] ?? 0);
            if ($id <= 0 || ($calendar['canWrite'] ?? false) !== true) {
                continue;
            }
            $name = trim((string)($calendar['name'] ?? ('Kalender #' . $id)));
            $options[] = ['caption' => $name, 'value' => $id];
            $known[$id] = true;
        }
        if ($current > 0 && !isset($known[$current])) {
            $options[] = ['caption' => 'Aktuell gewählt: Kalender #' . $current, 'value' => $current];
        }
        return $options;
    }

    /** @return array<int,array<string,mixed>> */
    private function SymDoCalendars(): array
    {
        $api = rtrim($this->ReadAttributeString('SymDoApiBase'), '/');
        $token = trim($this->ReadAttributeString('SymDoToken'));
        if ($api === '' || $token === '') {
            return [];
        }
        try {
            $answer = $this->RequestJson($api . '/calendar', null, $token);
            return is_array($answer['calendars'] ?? null)
                ? array_values(array_filter($answer['calendars'], 'is_array'))
                : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function SymDoCalendarRequest(array $body): array
    {
        $api = rtrim($this->ReadAttributeString('SymDoApiBase'), '/');
        $token = trim($this->ReadAttributeString('SymDoToken'));
        if ($api === '' || $token === '') {
            throw new Exception('SymDo ist noch nicht gekoppelt.');
        }
        $answer = $this->RequestJson($api . '/calendar', $body, $token);
        if (!is_array($answer) || ($answer['ok'] ?? false) !== true) {
            $code = is_array($answer) ? (string)($answer['error']['code'] ?? 'unknown') : 'invalid_response';
            $message = is_array($answer) ? trim((string)($answer['error']['message'] ?? '')) : '';
            throw new Exception('SymDo-Kalender: ' . $code . ($message !== '' ? ' — ' . $message : ''));
        }
        return $answer;
    }

    private function SyncExamsToCalendar(array $examsAll): string
    {
        $calendarId = max(0, $this->ReadPropertyInteger('CalendarID'));
        if ($calendarId <= 0) {
            return 'Kalender: kein Kalender gewählt';
        }

        $writable = false;
        foreach ($this->SymDoCalendars() as $calendar) {
            if ((int)($calendar['id'] ?? 0) === $calendarId && ($calendar['canWrite'] ?? false) === true) {
                $writable = true;
                break;
            }
        }
        if (!$writable) {
            throw new Exception('gewählter Kalender ist nicht beschreibbar oder nicht erreichbar');
        }

        $users = $this->SymDoUsers();
        if ($users === []) {
            throw new Exception('keine SymDo-Familienmitglieder gefunden');
        }

        $links = json_decode($this->ReadAttributeString('CalendarLinks'), true);
        $links = is_array($links) ? $links : [];
        $seen = [];
        $created = 0;
        $updated = 0;
        $deleted = 0;
        $unchanged = 0;
        $skipped = 0;
        $today = date('Y-m-d');

        foreach ($examsAll as $studentId => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $child = trim((string)($entry['child'] ?? ''));
            $userId = $this->ResolveSymDoUserID($child, $users);
            if ($userId === '') {
                $skipped += count((array)($entry['items'] ?? []));
                continue;
            }
            $classHours = is_array($entry['classHours'] ?? null) ? $entry['classHours'] : [];

            foreach ((array)($entry['items'] ?? []) as $exam) {
                if (!is_array($exam)) {
                    continue;
                }
                $date = trim((string)($exam['date'] ?? ''));
                if ($date === '' || $date < $today) {
                    continue;
                }

                [$start, $end, $hourText] = $this->ExamDateTimes($exam, $classHours);
                if ($start === '' || $end === '') {
                    $skipped++;
                    continue;
                }

                $subject = trim((string)($exam['subject']['name'] ?? ($exam['subjectText'] ?? '')));
                if ($subject === '') {
                    $subject = 'Prüfung';
                }
                $type = trim((string)($exam['type']['name'] ?? 'Klassenarbeit'));
                if ($type === '') {
                    $type = 'Klassenarbeit';
                }
                $comment = trim((string)($exam['comment'] ?? ''));
                $title = '📚 ' . $type . ' ' . $subject;
                $info = 'Schulmanager Online • ' . $child;
                if ($hourText !== '') {
                    $info .= ' • ' . $hourText;
                }
                if ($comment !== '') {
                    $info .= "\n" . $comment;
                }

                $examId = trim((string)($exam['id'] ?? ''));
                $keyMaterial = $examId !== ''
                    ? (string)$studentId . '|id|' . $examId
                    : (string)$studentId . '|' . $date . '|' . mb_strtolower($subject) . '|' . mb_strtolower($type) . '|' . $start;
                $key = sha1($keyMaterial);
                $seen[$key] = true;

                $event = [
                    'title' => mb_substr($title, 0, 160),
                    'info' => mb_substr($info, 0, 2000),
                    'location' => '',
                    'start' => $start,
                    'end' => $end,
                    'allDay' => false,
                    'members' => [$userId]
                ];
                $hash = sha1(json_encode([
                    'calendarID' => $calendarId,
                    'event' => $event
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                $link = is_array($links[$key] ?? null) ? $links[$key] : [];
                $oldCalendarId = (int)($link['calendarID'] ?? $calendarId);

                if ($link !== [] && $oldCalendarId !== $calendarId) {
                    try {
                        $this->SymDoCalendarRequest([
                            'action' => 'delete',
                            'calendarID' => $oldCalendarId,
                            'event' => [
                                'id' => (string)($link['id'] ?? ''),
                                'uid' => (string)($link['uid'] ?? ''),
                                'startTimestamp' => (int)($link['startTimestamp'] ?? 0)
                            ]
                        ]);
                        $deleted++;
                    } catch (Throwable $e) {
                        // Neuer Kalender soll trotzdem beliefert werden.
                    }
                    $link = [];
                }

                if ($link !== [] && (string)($link['hash'] ?? '') === $hash) {
                    $links[$key]['seenAt'] = time();
                    $unchanged++;
                    continue;
                }

                if ($link !== []) {
                    $updateEvent = $event + [
                        'id' => (string)($link['id'] ?? ''),
                        'uid' => (string)($link['uid'] ?? ''),
                        'startTimestamp' => (int)($link['startTimestamp'] ?? 0)
                    ];
                    $answer = $this->SymDoCalendarRequest([
                        'action' => 'update',
                        'calendarID' => $calendarId,
                        'event' => $updateEvent
                    ]);
                    $saved = is_array($answer['event'] ?? null) ? $answer['event'] : [];
                    $updated++;
                } else {
                    $answer = $this->SymDoCalendarRequest([
                        'action' => 'create',
                        'calendarID' => $calendarId,
                        'event' => $event
                    ]);
                    $saved = is_array($answer['event'] ?? null) ? $answer['event'] : [];
                    $created++;
                }

                $links[$key] = [
                    'calendarID' => $calendarId,
                    'id' => trim((string)($saved['id'] ?? ($link['id'] ?? ''))),
                    'uid' => trim((string)($saved['uid'] ?? ($link['uid'] ?? ''))),
                    'startTimestamp' => (int)($saved['startTimestamp'] ?? strtotime($start)),
                    'date' => $date,
                    'hash' => $hash,
                    'studentId' => (string)$studentId,
                    'seenAt' => time()
                ];
            }
        }

        foreach (array_keys($links) as $key) {
            if (isset($seen[$key])) {
                continue;
            }
            $link = is_array($links[$key] ?? null) ? $links[$key] : [];
            $date = trim((string)($link['date'] ?? ''));
            if ($date !== '' && $date >= $today) {
                try {
                    $this->SymDoCalendarRequest([
                        'action' => 'delete',
                        'calendarID' => (int)($link['calendarID'] ?? $calendarId),
                        'event' => [
                            'id' => (string)($link['id'] ?? ''),
                            'uid' => (string)($link['uid'] ?? ''),
                            'startTimestamp' => (int)($link['startTimestamp'] ?? 0)
                        ]
                    ]);
                    $deleted++;
                } catch (Throwable $e) {
                    // Link bleibt nicht hängen; beim nächsten Abruf würde sonst immer
                    // wieder versucht, einen längst entfernten Schulmanager-Termin zu löschen.
                }
                unset($links[$key]);
            } elseif ($date !== '' && $date < date('Y-m-d', strtotime('-7 days'))) {
                unset($links[$key]);
            }
        }

        $this->WriteAttributeString(
            'CalendarLinks',
            json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $text = sprintf(
            'Kalender: %d neu, %d aktualisiert, %d entfernt, %d unverändert',
            $created,
            $updated,
            $deleted,
            $unchanged
        );
        if ($skipped > 0) {
            $text .= ', ' . $skipped . ' ohne eindeutige Zeit/Kind-Zuordnung';
        }
        return $text;
    }

    /** @return array{0:string,1:string,2:string} */
    private function ExamDateTimes(array $exam, array $classHours): array
    {
        $date = trim((string)($exam['date'] ?? ''));
        if ($date === '') {
            return ['', '', ''];
        }

        $startHour = is_array($exam['startClassHour'] ?? null) ? $exam['startClassHour'] : [];
        $endHour = is_array($exam['endClassHour'] ?? null) ? $exam['endClassHour'] : $startHour;
        $number = trim((string)($startHour['number'] ?? ''));
        $endNumber = trim((string)($endHour['number'] ?? $number));

        $from = $this->TimeHHMM((string)($startHour['from'] ?? ''));
        $until = $this->TimeHHMM((string)($endHour['until'] ?? ''));

        if ($from === '' || $until === '') {
            $byId = [];
            $byNumber = [];
            foreach ($classHours as $hour) {
                if (!is_array($hour)) {
                    continue;
                }
                $id = (int)($hour['id'] ?? 0);
                if ($id > 0) {
                    $byId[$id] = $hour;
                }
                $n = trim((string)($hour['number'] ?? ''));
                if ($n !== '') {
                    $byNumber[$n] = $hour;
                }
            }
            $startFallback = $byId[(int)($startHour['id'] ?? 0)] ?? ($byNumber[$number] ?? null);
            $endFallback = $byId[(int)($endHour['id'] ?? 0)] ?? ($byNumber[$endNumber] ?? $startFallback);
            if (is_array($startFallback)) {
                [$fallbackStart, ] = $this->HourTimes($startFallback, $date);
                if ($from === '') {
                    $from = $fallbackStart;
                }
            }
            if (is_array($endFallback)) {
                [, $fallbackEnd] = $this->HourTimes($endFallback, $date);
                if ($until === '') {
                    $until = $fallbackEnd;
                }
            }
        }

        if ($from === '' || $until === '') {
            return ['', '', ''];
        }

        $hourText = $number !== ''
            ? ($endNumber !== '' && $endNumber !== $number
                ? $number . '.–' . $endNumber . '. Stunde'
                : $number . '. Stunde')
            : '';

        return [
            $date . 'T' . $from,
            $date . 'T' . $until,
            $hourText
        ];
    }

    private function SymDoUsers(): array
    {
        $gateway = $this->SymDoGatewayID();
        if ($gateway > 0 && function_exists('TGW_GetUsers')) {
            try {
                $users = json_decode((string)@TGW_GetUsers($gateway), true);
                if (is_array($users)) {
                    return array_values(array_filter($users, 'is_array'));
                }
            } catch (Throwable $e) {
            }
        }
        $api = rtrim($this->ReadAttributeString('SymDoApiBase'), '/');
        $token = trim($this->ReadAttributeString('SymDoToken'));
        if ($api === '' || $token === '') {
            return [];
        }
        $answer = $this->RequestJson($api . '/discovery', null, $token);
        return is_array($answer['users'] ?? null)
            ? array_values(array_filter($answer['users'], 'is_array'))
            : [];
    }

    private function ResolveSymDoUserID(string $childName, array $users): string
    {
        $needle = mb_strtolower(trim($childName));
        if ($needle === '') {
            return '';
        }
        foreach ($users as $u) {
            if (!is_array($u)) {
                continue;
            }
            if (mb_strtolower(trim((string)($u['name'] ?? ''))) === $needle) {
                return trim((string)($u['id'] ?? ''));
            }
        }
        $hits = [];
        foreach ($users as $u) {
            if (!is_array($u)) {
                continue;
            }
            $name = trim((string)($u['name'] ?? ''));
            $parts = preg_split('/\\s+/u', $name);
            $first = is_array($parts) && $parts !== [] ? (string)$parts[0] : '';
            if (mb_strtolower($first) === $needle) {
                $hits[] = trim((string)($u['id'] ?? ''));
            }
        }
        $hits = array_values(array_filter(array_unique($hits)));
        return count($hits) === 1 ? $hits[0] : '';
    }

    private function SymDoHomeworkRequest(array $body): array
    {
        $api = rtrim($this->ReadAttributeString('SymDoApiBase'), '/');
        $token = trim($this->ReadAttributeString('SymDoToken'));
        if ($api === '' || $token === '') {
            throw new Exception('SymDo ist noch nicht gekoppelt.');
        }
        $answer = $this->RequestJson($api . '/homework', $body, $token);
        if (!is_array($answer) || ($answer['ok'] ?? false) !== true) {
            $code = is_array($answer) ? (string)($answer['error']['code'] ?? 'unknown') : 'invalid_response';
            throw new Exception('SymDo-Hausaufgaben: ' . $code);
        }
        return $answer;
    }

    private function SyncHomeworkToSymDo(array $homeworkAll): string
    {
        $api = rtrim($this->ReadAttributeString('SymDoApiBase'), '/');
        $token = trim($this->ReadAttributeString('SymDoToken'));
        if ($api === '' || $token === '') {
            return 'SymDo-Hausaufgaben: noch nicht verbunden';
        }
        $users = $this->SymDoUsers();
        if ($users === []) {
            throw new Exception('keine SymDo-Familienmitglieder gefunden');
        }
        $current = $this->RequestJson($api . '/homework', null, $token);
        if (!is_array($current) || ($current['ok'] ?? false) !== true) {
            throw new Exception('Bestand konnte nicht gelesen werden');
        }
        $existing = [];
        foreach ((array)($current['items'] ?? []) as $item) {
            if (is_array($item) && trim((string)($item['id'] ?? '')) !== '') {
                $existing[(string)$item['id']] = $item;
            }
        }
        $links = json_decode($this->ReadAttributeString('HomeworkLinks'), true);
        $links = is_array($links) ? $links : [];
        $seen = [];
        $newCount = 0;
        $updateCount = 0;
        $deleteCount = 0;
        $skipCount = 0;
        $lookback = max(0, min(60, $this->ReadPropertyInteger('HomeworkLookbackDays')));
        $cutoff = (new DateTimeImmutable('today'))->modify('-' . $lookback . ' days')->format('Y-m-d');

        foreach ($homeworkAll as $studentId => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $childName = trim((string)($entry['child'] ?? ''));
            $userId = $this->ResolveSymDoUserID($childName, $users);
            if ($userId === '') {
                $skipCount += count((array)($entry['items'] ?? []));
                continue;
            }
            foreach ((array)($entry['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $due = trim((string)($item['date'] ?? ''));
                $subject = trim((string)($item['subject'] ?? ''));
                $note = trim((string)($item['homework'] ?? ''));
                if ($due === '' || $subject === '' || $note === '' || $due < $cutoff) {
                    continue;
                }
                $schoolId = trim((string)($item['id'] ?? ''));
                $keyMaterial = $schoolId !== ''
                    ? (string)$studentId . '|id|' . $schoolId
                    : (string)$studentId . '|' . $due . '|' . mb_strtolower($subject) . '|' . $note;
                $key = sha1($keyMaterial);
                $seen[$key] = true;
                $srcId = (int)(crc32($keyMaterial) & 0x7fffffff);
                if ($srcId <= 0) {
                    $srcId = 1;
                }
                $linkedId = trim((string)($links[$key]['id'] ?? ''));
                if ($linkedId !== '' && isset($existing[$linkedId])) {
                    $old = $existing[$linkedId];
                    if ((string)($old['childId'] ?? '') !== $userId
                        || (string)($old['subject'] ?? '') !== $subject
                        || (string)($old['due'] ?? '') !== $due
                        || (string)($old['note'] ?? '') !== $note) {
                        $res = $this->SymDoHomeworkRequest([
                            'action' => 'update',
                            'id' => $linkedId,
                            'subject' => $subject,
                            'due' => $due,
                            'note' => $note
                        ]);
                        if (is_array($res['item'] ?? null)) {
                            $existing[$linkedId] = $res['item'];
                        }
                        $updateCount++;
                    }
                    $links[$key] = [
                        'id' => $linkedId,
                        'studentId' => (string)$studentId,
                        'due' => $due,
                        'seenAt' => time()
                    ];
                    continue;
                }
                $res = $this->SymDoHomeworkRequest([
                    'action' => 'create',
                    'childId' => $userId,
                    'subject' => $subject,
                    'due' => $due,
                    'note' => $note,
                    'source' => 'app',
                    'srcId' => $srcId
                ]);
                $created = is_array($res['item'] ?? null) ? $res['item'] : [];
                $newId = trim((string)($created['id'] ?? ''));
                if ($newId === '') {
                    throw new Exception('angelegte Hausaufgabe ohne ID');
                }
                $existing[$newId] = $created;
                $links[$key] = [
                    'id' => $newId,
                    'studentId' => (string)$studentId,
                    'due' => $due,
                    'seenAt' => time()
                ];
                $newCount++;
            }
        }

        foreach (array_keys($links) as $key) {
            if (isset($seen[$key])) {
                continue;
            }
            $id = trim((string)($links[$key]['id'] ?? ''));
            if ($id !== '' && isset($existing[$id]) && ($existing[$id]['done'] ?? false) !== true) {
                $this->SymDoHomeworkRequest(['action' => 'delete', 'id' => $id]);
                $deleteCount++;
            }
            unset($links[$key]);
        }
        $this->WriteAttributeString('HomeworkLinks', json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $text = sprintf('SymDo-Hausaufgaben: %d neu, %d aktualisiert, %d entfernt', $newCount, $updateCount, $deleteCount);
        if ($skipCount > 0) {
            $text .= ', ' . $skipCount . ' ohne eindeutige Kind-Zuordnung';
        }
        return $text;
    }

    /** @return array<int, array{enabled:bool,studentId:string,symdoChild:string}> */
    private function Mappings(bool $activeOnly = true): array
    {
        $raw = json_decode($this->ReadPropertyString('Mappings'), true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $enabled = ($row['enabled'] ?? true) !== false;
            $sid = trim((string)($row['studentId'] ?? ''));
            $child = trim((string)($row['symdoChild'] ?? ''));
            if ($sid === '') {
                continue;
            }
            if ($activeOnly && !$enabled) {
                continue;
            }
            $out[] = [
                'enabled' => $enabled,
                'studentId' => $sid,
                'symdoChild' => $child
            ];
        }
        return $out;
    }

    /** @return array<int, array<string,mixed>> */
    private function DetectedStudents(): array
    {
        $raw = json_decode($this->ReadAttributeString('DetectedStudents'), true);
        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    private function StoreData(array $homework, array $exams, array $grades, array $letters): void
    {
        $homeworkJson = json_encode($homework, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $examsJson = json_encode($exams, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $gradesJson = json_encode($grades, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lettersJson = json_encode($letters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->SetStringIfChanged('HomeworkJSON', $homeworkJson === false ? '{}' : $homeworkJson);
        $this->SetStringIfChanged('ExamsJSON', $examsJson === false ? '{}' : $examsJson);
        $this->SetStringIfChanged('GradesJSON', $gradesJson === false ? '{}' : $gradesJson);
        $this->SetStringIfChanged('LettersJSON', $lettersJson === false ? '[]' : $lettersJson);
        $overview = $this->BuildOverview($homework, $exams, $grades, $letters);
        $this->SetStringIfChanged('Overview', $overview);
        $this->PushVisualization($overview);
    }

    private function BuildOverview(array $homework, array $exams, array $grades, array $letters): string
    {
        $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mappings = $this->Mappings();
        $title = trim($this->ReadPropertyString('ClassPageTitle'));
        if ($title === '') {
            $title = 'Klassenseiten';
        }
        $letterLimit = max(5, min(30, $this->ReadPropertyInteger('LetterLimit')));
        $letterHeight = max(180, min(800, $this->ReadPropertyInteger('LetterScrollHeight')));
        $examLimit = max(3, min(20, $this->ReadPropertyInteger('ExamDisplayLimit')));
        $showRefresh = $this->ReadPropertyBoolean('ShowRefreshButton');

        $html = '<div class="smsd-shell">';
        $html .= '<div class="smsd-head"><h2>' . $esc($title) . '</h2><span class="smsd-source">Schulmanager</span><span class="smsd-spacer"></span>';
        if ($showRefresh) {
            $html .= '<button class="smsd-refresh" onclick="requestAction(\'UpdateNow\',0)">↻ Aktualisieren</button>';
        }
        $html .= '</div>';

        if ($mappings === []) {
            $html .= '<div class="smsd-empty">Keine Kinder zugeordnet. In der Instanz zuerst „Kinder abrufen“ und zuordnen.</div></div>';
            return $html;
        }

        // Kind-Umschaltung. Alle aktiv zugeordneten Kinder werden unterstützt;
        // es gibt bewusst kein festes Zwei-Kinder-Limit.
        $html .= '<div class="smsd-tabs">';
        $first = true;
        foreach ($mappings as $mapping) {
            $sid = (string)$mapping['studentId'];
            $child = trim((string)$mapping['symdoChild']);
            $gradeEntry = is_array($grades[$sid] ?? null) ? $grades[$sid] : [];
            $examEntry = is_array($exams[$sid] ?? null) ? $exams[$sid] : [];
            $homeworkEntry = is_array($homework[$sid] ?? null) ? $homework[$sid] : [];
            $fullName = trim((string)($gradeEntry['name'] ?? ($examEntry['name'] ?? ($homeworkEntry['name'] ?? $child))));
            $caption = $child !== '' ? $child : $fullName;
            if ($caption === '') {
                $caption = 'Kind ' . $sid;
            }
            $html .= '<button class="smsd-tab' . ($first ? ' active' : '') . '" data-smsd-tab="' . $esc($sid) . '">' . $esc($caption) . '</button>';
            $first = false;
        }
        $html .= '</div><div class="smsd-body">';

        $first = true;
        foreach ($mappings as $mapping) {
            $sid = (string)$mapping['studentId'];
            $child = trim((string)$mapping['symdoChild']);
            $gradeEntry = is_array($grades[$sid] ?? null) ? $grades[$sid] : [];
            $examEntry = is_array($exams[$sid] ?? null) ? $exams[$sid] : [];
            $homeworkEntry = is_array($homework[$sid] ?? null) ? $homework[$sid] : [];
            $name = trim((string)($gradeEntry['name'] ?? ($examEntry['name'] ?? ($homeworkEntry['name'] ?? $child))));
            if ($name === '') {
                $name = $child !== '' ? $child : ('Kind ' . $sid);
            }

            $html .= '<section class="smsd-child-panel' . ($first ? ' active' : '') . '" data-smsd-panel="' . $esc($sid) . '">';
            $html .= '<div style="font-size:20px;font-weight:700;margin:2px 0 10px 2px">' . $esc($name) . '</div>';

            // ── Noten ──────────────────────────────────────────────────────
            if ($this->ReadPropertyBoolean('ReadGrades')) {
                $gradeData = is_array($gradeEntry['data'] ?? null) ? $gradeEntry['data'] : [];
                $schoolYear = trim((string)($gradeData['schoolYear'] ?? ''));
                $html .= '<div class="smsd-card"><h3>Noten' . ($schoolYear !== '' ? ' – Schuljahr ' . $esc($schoolYear) : '') . '</h3>';
                $subjects = is_array($gradeData['subjects'] ?? null) ? $gradeData['subjects'] : [];
                $hasGrades = ($gradeData['hasGrades'] ?? false) === true;
                if (!$hasGrades || $subjects === []) {
                    if (trim((string)($gradeData['error'] ?? '')) !== '') {
                        $html .= '<div class="smsd-empty">Noten derzeit nicht lesbar. Beim nächsten Abruf wird erneut versucht.</div>';
                    } else {
                        $html .= '<div class="smsd-empty">Noch keine Noten' . ($schoolYear !== '' ? ' im Schuljahr ' . $esc($schoolYear) : '') . '.</div>';
                    }
                } else {
                    $html .= '<table class="smsd-table"><tr><th>Fach</th><th>Noten</th><th>Ø Fach</th></tr>';
                    foreach ($subjects as $subject) {
                        if (!is_array($subject)) {
                            continue;
                        }
                        $subjectName = trim((string)($subject['name'] ?? 'Fach'));
                        $categoryTexts = [];
                        $categoryCount = 0;
                        $singleAverage = null;
                        foreach ((array)($subject['categories'] ?? []) as $categoryName => $category) {
                            if (!is_array($category)) {
                                continue;
                            }
                            $values = [];
                            foreach ((array)($category['grades'] ?? []) as $grade) {
                                if (is_array($grade) && trim((string)($grade['value'] ?? '')) !== '') {
                                    $values[] = trim((string)$grade['value']);
                                }
                            }
                            if ($values === []) {
                                continue;
                            }
                            $categoryCount++;
                            $avg = is_numeric($category['average'] ?? null)
                                ? number_format((float)$category['average'], 2, ',', '')
                                : '—';
                            if ($categoryCount === 1 && is_numeric($category['average'] ?? null)) {
                                $singleAverage = (float)$category['average'];
                            }
                            $categoryTexts[] = '<b>' . $esc((string)$categoryName) . ':</b> ' . $esc(implode(', ', $values))
                                . ' <span class="smsd-muted">(Ø ' . $esc($avg) . ')</span>';
                        }
                        $overall = '—';
                        if (($subject['averageWeighted'] ?? false) === true && is_numeric($subject['average'] ?? null)) {
                            $overall = number_format((float)$subject['average'], 2, ',', '');
                        } elseif ($categoryCount === 1 && $singleAverage !== null) {
                            $overall = number_format($singleAverage, 2, ',', '');
                        }
                        $html .= '<tr><td><b>' . $esc($subjectName) . '</b></td><td>' . implode('<br>', $categoryTexts) . '</td><td><b>' . $esc($overall) . '</b>'
                            . (($subject['averageWeighted'] ?? false) === true ? '' : ($categoryCount > 1 ? '<div class="smsd-foot">Gewichtung fehlt</div>' : ''))
                            . '</td></tr>';
                    }
                    $html .= '</table><div class="smsd-foot">Durchschnitte sind rechnerische Werte. Schriftlich/mündlich werden nur zusammengeführt, wenn Schulmanager eine Gewichtung liefert.</div>';
                }
                $html .= '</div>';
            }

            // ── Prüfungen ──────────────────────────────────────────────────
            if ($this->ReadPropertyBoolean('ReadExams')) {
                $html .= '<div class="smsd-card"><h3>Nächste Prüfungen</h3>';
                $examItems = is_array($examEntry['items'] ?? null) ? $examEntry['items'] : [];
                $classHours = is_array($examEntry['classHours'] ?? null) ? $examEntry['classHours'] : [];
                if ($examItems === []) {
                    $html .= '<div class="smsd-empty">Keine anstehenden Prüfungen im Abrufzeitraum.</div>';
                } else {
                    usort($examItems, static fn(array $a, array $b): int => strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')));
                    $html .= '<table class="smsd-table"><tr><th>Datum</th><th>Fach</th><th>Art</th><th>Stunde</th></tr>';
                    foreach (array_slice($examItems, 0, $examLimit) as $exam) {
                        if (!is_array($exam)) {
                            continue;
                        }
                        $date = trim((string)($exam['date'] ?? ''));
                        $ts = $date !== '' ? strtotime($date . ' 12:00') : false;
                        $dateText = $ts !== false ? date('d.m.Y', $ts) : $date;
                        $subject = trim((string)($exam['subject']['name'] ?? ($exam['subjectText'] ?? '')));
                        $type = trim((string)($exam['type']['name'] ?? 'Klassenarbeit'));
                        [, , $hourText] = $this->ExamDateTimes($exam, $classHours);
                        $html .= '<tr><td>' . $esc($dateText) . '</td><td><b>' . $esc($subject) . '</b></td><td>' . $esc($type) . '</td><td>' . $esc($hourText) . '</td></tr>';
                    }
                    $html .= '</table>';
                }
                $html .= '</div>';
            }

            // ── Elternbriefe: nur die neuesten N, eigener Scrollbereich ─────
            if ($this->ReadPropertyBoolean('ReadLetters')) {
                $html .= '<div class="smsd-card"><h3>Elternbriefe <span class="smsd-muted" style="font-size:12px;font-weight:400">– neueste ' . $letterLimit . '</span></h3>';
                $childLetters = [];
                foreach ($letters as $letter) {
                    if (is_array($letter) && $this->LetterBelongsToStudent($letter, $sid)) {
                        $childLetters[] = $letter;
                    }
                }
                usort($childLetters, static function (array $a, array $b): int {
                    $ad = (string)($a['sentDate'] ?? ($a['createdAt'] ?? ''));
                    $bd = (string)($b['sentDate'] ?? ($b['createdAt'] ?? ''));
                    return strcmp($bd, $ad);
                });
                $childLetters = array_slice($childLetters, 0, $letterLimit);
                if ($childLetters === []) {
                    $html .= '<div class="smsd-empty">Keine Elternbriefe.</div>';
                } else {
                    $html .= '<div class="smsd-letter-scroll" style="max-height:' . $letterHeight . 'px">';
                    foreach ($childLetters as $letter) {
                        $dateRaw = (string)($letter['sentDate'] ?? ($letter['createdAt'] ?? ''));
                        $date = $dateRaw !== '' && strtotime($dateRaw) !== false ? date('d.m.Y H:i', (int)strtotime($dateRaw)) : '';
                        $letterTitle = trim((string)($letter['title'] ?? 'Elternbrief'));
                        $text = $this->PlainLetterText((string)($letter['text'] ?? ($letter['content'] ?? '')));
                        $attachments = is_array($letter['attachments'] ?? null) ? $letter['attachments'] : [];
                        $html .= '<details class="smsd-letter"><summary><b>' . $esc($letterTitle) . '</b>'
                            . ($date !== '' ? ' <span class="smsd-muted">— ' . $esc($date) . '</span>' : '') . '</summary>';
                        if ($text !== '') {
                            $html .= '<div class="smsd-letter-text">' . $esc($text) . '</div>';
                        } else {
                            $html .= '<div class="smsd-letter-text smsd-muted">Kein Brieftext im Detailabruf vorhanden.</div>';
                        }
                        if ($attachments !== []) {
                            $names = [];
                            foreach ($attachments as $attachment) {
                                if (is_array($attachment)) {
                                    $filename = trim((string)($attachment['filename'] ?? ''));
                                    if ($filename !== '') {
                                        $names[] = $esc($filename);
                                    }
                                }
                            }
                            $html .= '<div class="smsd-foot"><b>Anhänge:</b> ' . ($names !== [] ? implode(', ', $names) : 'vorhanden') . '</div>';
                        }
                        $html .= '</details>';
                    }
                    $html .= '</div>';
                }
                $html .= '</div>';
            }

            $html .= '<div class="smsd-foot">Hausaufgaben werden in der normalen SymDo-Hausaufgaben-Kachel geführt. Stundenplan und Vertretungen bleiben in „SymDo - Stundenplan“.</div>';
            $html .= '</section>';
            $first = false;
        }

        $html .= '</div></div>';
        return $html;
    }

    private function SetStatusText(string $text, bool $updated = false): void
    {
        $data = ['t' => time(), 'text' => $text];
        $this->WriteAttributeString('StatusData', json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->SetStringIfChanged('Status', $text);
        if ($updated) {
            $id = $this->GetIDForIdent('LastUpdate');
            if ($id > 0) {
                SetValueInteger($id, time());
            }
            $this->RefreshOverviewFromStored();
        }
        $this->SendDebug('Schulmanager', $text, 0);
    }

    private function StatusText(): string
    {
        $raw = json_decode($this->ReadAttributeString('StatusData'), true);
        if (!is_array($raw) || trim((string)($raw['text'] ?? '')) === '') {
            return 'Noch nicht ausgeführt.';
        }
        $t = (int)($raw['t'] ?? 0);
        return ($t > 0 ? 'Letzter Stand ' . date('d.m.Y H:i', $t) . ': ' : '') . (string)$raw['text'];
    }

    private function SetStringIfChanged(string $ident, string $value): void
    {
        $id = $this->GetIDForIdent($ident);
        if ($id <= 0) {
            return;
        }
        if ((string)GetValue($id) !== $value) {
            SetValueString($id, $value);
        }
    }

    private function IsPaused(): bool
    {
        $fails = $this->ReadAttributeInteger('LoginFails');
        if ($fails < self::FAIL_MAX) {
            return false;
        }
        $at = $this->ReadAttributeInteger('LoginFailAt');
        if ($at <= 0 || (time() - $at) >= self::FAIL_PAUSE) {
            $this->ResetFailures();
            return false;
        }
        return true;
    }

    private function CountFailure(): void
    {
        $this->WriteAttributeInteger('LoginFails', $this->ReadAttributeInteger('LoginFails') + 1);
        $this->WriteAttributeInteger('LoginFailAt', time());
    }

    private function ResetFailures(): void
    {
        $this->WriteAttributeInteger('LoginFails', 0);
        $this->WriteAttributeInteger('LoginFailAt', 0);
    }
}
