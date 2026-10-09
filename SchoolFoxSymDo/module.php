<?php

declare(strict_types=1);

/**
 * SchoolFox -> SymDo Klassenseite.
 *
 * - liest genau ein SchoolFox-Kind (z. B. Tom) und die letzten Mitteilungen
 * - übernimmt KEINE schreibenden Aktionen zu SchoolFox
 * - liest die Fächer des Kindes aus einer bestehenden SymDo-Stundenplaninstanz
 * - verwaltet manuelle Noten lokal in IP-Symcon; Note anklicken = ändern/löschen
 * - beobachtet SchoolFox-Metadaten auf neue explizite Hinweise zu Stundenplan/Noten
 *
 * Die manuellen Noten werden niemals durch SchoolFox gelöscht oder überschrieben.
 */
class SchoolFoxSymDo extends IPSModule
{
    private const CONFIG_URL = 'https://my.schoolfox.app/config.json';
    private const FALLBACK_BASE = 'https://api.schoolfox.com/';
    private const UPDATE_DEFAULT = 30;
    private const UPDATE_MIN = 15;
    private const UPDATE_MAX = 1440;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Enabled', false);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('UpdateMinutes', self::UPDATE_DEFAULT);

        $this->RegisterPropertyInteger('TimetableInstanceID', 0);
        $this->RegisterPropertyString('ChildName', 'Tom');
        $this->RegisterPropertyString('SchoolFoxPupilID', ''); // leer = automatisch passend zu ChildName

        $this->RegisterPropertyInteger('MessageLimit', 10);
        $this->RegisterPropertyInteger('MessageScrollHeight', 300);
        $this->RegisterPropertyBoolean('ReadAttachments', true);
        $this->RegisterPropertyBoolean('FeatureWatch', true);
        $this->RegisterPropertyBoolean('ShowRefreshButton', true);

        $this->RegisterAttributeString('DetectedPupils', '[]');
        $this->RegisterAttributeString('Messages', '[]');
        $this->RegisterAttributeString('Grades', '[]');
        $this->RegisterAttributeString('SchoolInfo', '{}');
        $this->RegisterAttributeString('Capabilities', '{}');
        $this->RegisterAttributeString('CapabilitiesAcknowledged', '[]');
        $this->RegisterAttributeInteger('LastSuccessfulUpdate', 0);

        $this->RegisterVariableString('Status', 'Status');
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', '~UnixTimestamp');
        $this->RegisterVariableString('MessagesJSON', 'SchoolFox Mitteilungen JSON');
        $this->RegisterVariableString('GradesJSON', 'Manuelle Noten JSON');

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

        $this->SetVisualizationType(
            defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN')
                ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN
                : 1
        );

        foreach (['MessagesJSON', 'GradesJSON'] as $ident) {
            $id = $this->GetIDForIdent($ident);
            if ($id > 0) {
                @IPS_SetHidden($id, true);
            }
        }

        $minutes = max(self::UPDATE_MIN, min(self::UPDATE_MAX, $this->ReadPropertyInteger('UpdateMinutes')));
        $active = $this->ReadPropertyBoolean('Enabled')
            && trim($this->ReadPropertyString('Username')) !== ''
            && trim($this->ReadPropertyString('Password')) !== '';

        $this->SetTimerInterval('UpdateTimer', $active ? $minutes * 60000 : 0);
        $this->SetStatus($active ? 102 : 104);
        $this->SyncVariables();
        $this->PushTile();
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ((string)$Ident) {
            case 'TestConnection':
                $this->UpdateFormField('StatusLabel', 'caption', $this->TestConnection());
                return;

            case 'UpdateNow':
                $text = $this->RunUpdate();
                @ $this->UpdateFormField('StatusLabel', 'caption', $text);
                return;

            case 'AddGrade':
                $this->AddGrade((string)$Value);
                return;

            case 'EditGrade':
                $this->EditGrade((string)$Value);
                return;

            case 'DeleteGrade':
                $this->DeleteGrade((string)$Value);
                return;

            case 'DismissCapabilities':
                $this->DismissCapabilities();
                return;

            case 'RenameClassPage':
                IPS_SetName($this->InstanceID, 'SymDo - Klassenseiten');
                return;
        }

        throw new Exception('Unbekannte Aktion: ' . (string)$Ident);
    }

    public function GetConfigurationForm(): string
    {
        $pupils = $this->DetectedPupils();
        $pupilText = $pupils === []
            ? 'Noch kein SchoolFox-Kind erkannt. „Verbindung testen“ oder „Jetzt aktualisieren“ ausführen.'
            : implode(' · ', array_map(static function (array $p): string {
                $name = trim((string)($p['name'] ?? ''));
                $class = trim((string)($p['class'] ?? ''));
                return $class !== '' ? ($name . ' (' . $class . ')') : $name;
            }, $pupils));

        $subjects = $this->TimetableSubjectsForChild();
        $cap = $this->Capabilities();
        $capText = 'Stundenplan: ' . (($cap['timetable'] ?? false) ? 'Hinweis erkannt' : 'derzeit nicht von SchoolFox erkannt')
            . ' · Noten: ' . (($cap['grades'] ?? false) ? 'Hinweis erkannt' : 'derzeit nicht von SchoolFox erkannt');

        $elements = [
            [
                'type' => 'ExpansionPanel',
                'caption' => 'SchoolFox',
                'expanded' => true,
                'items' => [
                    ['type' => 'CheckBox', 'name' => 'Enabled', 'caption' => 'Automatische Aktualisierung aktivieren'],
                    [
                        'type' => 'RowLayout',
                        'items' => [
                            ['type' => 'ValidationTextBox', 'name' => 'Username', 'caption' => 'E-Mail / Benutzername', 'width' => '300px'],
                            ['type' => 'PasswordTextBox', 'name' => 'Password', 'caption' => 'Passwort', 'width' => '260px'],
                            ['type' => 'NumberSpinner', 'name' => 'UpdateMinutes', 'caption' => 'Aktualisierung', 'minimum' => self::UPDATE_MIN, 'maximum' => self::UPDATE_MAX, 'suffix' => ' min', 'width' => '190px'],
                        ],
                    ],
                    [
                        'type' => 'RowLayout',
                        'items' => [
                            ['type' => 'Button', 'caption' => 'VERBINDUNG TESTEN', 'onClick' => 'IPS_RequestAction($id, "TestConnection", 0);'],
                            ['type' => 'Button', 'caption' => 'JETZT AKTUALISIEREN', 'onClick' => 'IPS_RequestAction($id, "UpdateNow", 0);'],
                        ],
                    ],
                    ['type' => 'Label', 'name' => 'StatusLabel', 'caption' => $this->StatusText()],
                    ['type' => 'Label', 'caption' => 'Erkannte Kinder: ' . $pupilText],
                ],
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => 'Tom / SymDo-Stundenplan',
                'expanded' => true,
                'items' => [
                    ['type' => 'SelectInstance', 'name' => 'TimetableInstanceID', 'caption' => 'SymDo-Stundenplan'],
                    ['type' => 'ValidationTextBox', 'name' => 'ChildName', 'caption' => 'Kind in SymDo', 'width' => '240px'],
                    ['type' => 'ValidationTextBox', 'name' => 'SchoolFoxPupilID', 'caption' => 'SchoolFox-Schüler-ID (leer = automatisch)', 'width' => '360px'],
                    ['type' => 'Label', 'caption' => 'Fächer werden ausschließlich aus dem SymDo-Stundenplan dieses Kindes erzeugt. SchoolFox-Mitteilungen verändern den Stundenplan nicht.'],
                    ['type' => 'Label', 'caption' => $subjects === [] ? 'Aktuell keine Fächer für dieses Kind gefunden.' : ('Aktuelle Fächer: ' . implode(', ', $subjects))],
                ],
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => 'Klassenseite / Noten',
                'expanded' => false,
                'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'MessageLimit', 'caption' => 'SchoolFox-Mitteilungen anzeigen', 'minimum' => 1, 'maximum' => 50, 'width' => '240px'],
                    ['type' => 'NumberSpinner', 'name' => 'MessageScrollHeight', 'caption' => 'Scrollhöhe Mitteilungen', 'minimum' => 160, 'maximum' => 900, 'suffix' => ' px', 'width' => '240px'],
                    ['type' => 'CheckBox', 'name' => 'ReadAttachments', 'caption' => 'Anhang-Dateinamen mitlesen'],
                    ['type' => 'CheckBox', 'name' => 'ShowRefreshButton', 'caption' => 'Aktualisieren-Knopf in der Kachel anzeigen'],
                    ['type' => 'Label', 'caption' => 'Noten werden manuell direkt in der Kachel eingetragen. Eine Note anklicken, um sie zu ändern oder zu löschen. Pro Fach wird nur der einfache Durchschnitt angezeigt.'],
                    ['type' => 'Button', 'caption' => 'INSTANZNAME AUF „SYMDO - KLASSENSEITEN“ SETZEN', 'onClick' => 'IPS_RequestAction($id, "RenameClassPage", 0);'],
                ],
            ],
            [
                'type' => 'ExpansionPanel',
                'caption' => 'SchoolFox-Funktionswächter',
                'expanded' => false,
                'items' => [
                    ['type' => 'CheckBox', 'name' => 'FeatureWatch', 'caption' => 'Auf neue SchoolFox-Funktionen achten'],
                    ['type' => 'Label', 'caption' => 'Der Wächter prüft nur von SchoolFox selbst gelieferte Konfigurations-/Inventar-Metadaten auf eindeutige Hinweise zu Stundenplan oder Noten. Er erfindet keine Endpunkte und importiert nichts automatisch.'],
                    ['type' => 'Label', 'caption' => $capText],
                ],
            ],
        ];

        return (string)json_encode([
            'elements' => $elements,
            'actions' => [],
            'status' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function GetVisualizationTile(): string
    {
        $content = $this->BuildTileContent();
        $initial = json_encode(
            $content,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($initial === false) {
            $initial = '""';
        }

        return <<<'HTML'
<style>
:root{font-family:Arial,sans-serif;color:#202124;--sym-mt:0px;--sym-ms:0px;--sym-mb:0px;--sfx-accent:#10bfae}
*{box-sizing:border-box}html,body{height:100%}body{margin:0!important;padding:0!important;overflow:hidden;background:transparent;color:inherit}
#sfx-root{height:100%;overflow:hidden}.sfx-shell{height:100%;display:flex;flex-direction:column;gap:10px;padding:max(var(--sym-mt),env(safe-area-inset-top,0px)) max(var(--sym-ms),9px) calc(var(--sym-mb) + 9px) max(var(--sym-ms),9px)}
.sfx-head{display:flex;align-items:center;gap:9px;min-height:30px}.sfx-head h2{font-size:23px;margin:0}.sfx-badge{font-size:12px;padding:4px 8px;border-radius:999px;background:rgba(126,87,194,.12);color:inherit}.sfx-spacer{flex:1}.sfx-refresh{border:0;border-radius:9px;padding:7px 10px;background:rgba(0,150,136,.12);color:inherit;cursor:pointer;font-weight:700}
html.sfx-has-system-title .sfx-head h2{display:none}.sfx-body{min-height:0;overflow-y:auto;scrollbar-width:thin;scrollbar-gutter:stable;padding-right:4px}.sfx-child{font-size:21px;font-weight:700;margin:2px 0 8px}
.sfx-alert{border:1px solid rgba(245,166,35,.55);background:rgba(245,166,35,.12);border-radius:11px;padding:10px 12px;margin-bottom:10px;display:flex;gap:8px;align-items:flex-start}.sfx-alert button{margin-left:auto;border:0;background:transparent;cursor:pointer;font-size:18px;color:inherit}
.sfx-card{border:1px solid rgba(127,127,127,.20);border-radius:12px;padding:12px;background:rgba(255,255,255,.03);margin-bottom:10px}.sfx-card h3{margin:0 0 10px;font-size:18px}.sfx-grade-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 14px}.sfx-subject{min-width:0;display:grid;grid-template-columns:minmax(90px,1.1fr) minmax(0,1.4fr) auto 30px;align-items:center;gap:6px;padding:6px 0;border-bottom:1px solid rgba(127,127,127,.14)}.sfx-subject-name{min-width:0;font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.sfx-add{width:28px;height:28px;border-radius:50%;border:0;background:rgba(16,191,174,.15);color:inherit;font-size:18px;line-height:1;cursor:pointer}.sfx-grades{min-width:0;display:flex;gap:4px;flex-wrap:wrap;align-items:center}.sfx-grade{border:1px solid rgba(127,127,127,.25);background:rgba(127,127,127,.07);color:inherit;border-radius:999px;padding:3px 7px;cursor:pointer;font-weight:700;font-size:13px;line-height:1.15}.sfx-average{font-size:12px;color:#666;white-space:nowrap}.sfx-no-grade{font-size:12px;color:#888;white-space:nowrap}.sfx-empty{padding:10px;border-radius:8px;background:rgba(127,127,127,.08);color:#777}
@media(max-width:850px){.sfx-grade-list{grid-template-columns:1fr}}
.sfx-message-scroll{overflow-y:auto;scrollbar-width:thin;scrollbar-gutter:stable;padding-right:4px}.sfx-message{border:1px solid rgba(127,127,127,.18);border-radius:8px;padding:8px 10px;margin:6px 0;background:rgba(127,127,127,.035)}.sfx-message summary{cursor:pointer;font-weight:700}.sfx-meta{font-size:12px;color:#777;margin:5px 0}.sfx-text{white-space:pre-wrap;line-height:1.4;margin-top:8px}.sfx-att{font-size:12px;margin-top:8px}.sfx-att span{display:inline-block;margin:2px 5px 2px 0;padding:3px 7px;border-radius:7px;background:rgba(127,127,127,.08)}
.sfx-modal{position:fixed;inset:0;background:rgba(0,0,0,.40);display:none;align-items:center;justify-content:center;padding:16px;z-index:1000}.sfx-modal.open{display:flex}.sfx-dialog{width:min(380px,100%);background:#fff;color:#202124;border-radius:14px;padding:16px;box-shadow:0 14px 40px rgba(0,0,0,.30)}@media(prefers-color-scheme:dark){.sfx-dialog{background:#252525;color:#eee}}.sfx-dialog h3{margin:0 0 12px}.sfx-dialog input{width:100%;font-size:18px;padding:10px;border:1px solid #aaa;border-radius:9px;background:transparent;color:inherit}.sfx-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}.sfx-actions button{border:0;border-radius:9px;padding:9px 12px;cursor:pointer}.sfx-save{background:var(--sfx-accent);color:white}.sfx-delete{background:#e53935;color:white;margin-right:auto}
@media(max-width:520px){.sfx-head h2{font-size:19px}.sfx-badge{display:none}.sfx-refresh{font-size:0;width:36px;height:32px;padding:0}.sfx-refresh::before{content:"↻";font-size:19px}.sfx-card{padding:10px}}
</style>
<script>
(function(){try{const p=new URLSearchParams(location.search),r=document.documentElement;const px=n=>{const v=parseInt(p.get(n),10);return Number.isFinite(v)&&v>=0?v+'px':null};const mt=px('margintop'),ms=px('marginside'),mb=px('marginbottom');if(mt!==null){r.style.setProperty('--sym-mt',mt);if(parseInt(mt,10)>0)r.classList.add('sfx-has-system-title')}if(ms!==null)r.style.setProperty('--sym-ms',ms);if(mb!==null)r.style.setProperty('--sym-mb',mb)}catch(e){}})();
const sfxRequest=(typeof window.requestAction==='function')?window.requestAction:(ident,value)=>{try{window.parent.postMessage(JSON.stringify({type:'requestAction',ident:String(ident),value}),'*')}catch(e){}try{window.parent.postMessage({type:'requestAction',ident:String(ident),value},'*')}catch(e){}};
let sfxEdit={id:'',subject:'',value:''};
function sfxOpenGrade(subject,id,value){sfxEdit={id:id||'',subject:subject||'',value:value??''};document.getElementById('sfx-modal-title').textContent=(id?'Note ändern: ':'Neue Note: ')+subject;const inp=document.getElementById('sfx-grade-input');inp.value=value??'';document.getElementById('sfx-delete').style.display=id?'':'none';document.getElementById('sfx-modal').classList.add('open');setTimeout(()=>{inp.focus();inp.select()},30)}
function sfxClose(){document.getElementById('sfx-modal').classList.remove('open')}
function sfxSave(){const v=document.getElementById('sfx-grade-input').value.trim();if(v==='')return;if(sfxEdit.id)sfxRequest('EditGrade',JSON.stringify({id:sfxEdit.id,value:v}));else sfxRequest('AddGrade',JSON.stringify({subject:sfxEdit.subject,value:v}));sfxClose()}
function sfxDelete(){if(!sfxEdit.id)return;if(confirm('Diese Note wirklich löschen?')){sfxRequest('DeleteGrade',JSON.stringify({id:sfxEdit.id}));sfxClose()}}
document.addEventListener('click',e=>{const add=e.target.closest('.sfx-add');if(add){sfxOpenGrade(add.dataset.subject,'','');return}const g=e.target.closest('.sfx-grade');if(g){sfxOpenGrade(g.dataset.subject,g.dataset.id,g.dataset.value);return}const r=e.target.closest('[data-sfx-refresh]');if(r){sfxRequest('UpdateNow',0);return}const d=e.target.closest('[data-sfx-dismiss]');if(d){sfxRequest('DismissCapabilities',0);return}if(e.target.id==='sfx-modal')sfxClose()});
document.addEventListener('keydown',e=>{if(e.key==='Escape')sfxClose();if(e.key==='Enter'&&document.getElementById('sfx-modal').classList.contains('open'))sfxSave()});
function handleMessage(data){const root=document.getElementById('sfx-root');if(root)root.innerHTML=typeof data==='string'?data:String(data??'')}
</script>
<div id="sfx-root"></div>
<div id="sfx-modal" class="sfx-modal"><div class="sfx-dialog"><h3 id="sfx-modal-title">Note</h3><input id="sfx-grade-input" type="number" min="1" max="6" step="0.25" inputmode="decimal" placeholder="1 bis 6"><div class="sfx-actions"><button id="sfx-delete" class="sfx-delete" onclick="sfxDelete()">Löschen</button><button onclick="sfxClose()">Abbrechen</button><button class="sfx-save" onclick="sfxSave()">Speichern</button></div></div></div>
HTML
            . '<script>handleMessage(' . $initial . ');</script>';
    }

    private function RunUpdate(): string
    {
        try {
            $session = $this->LoginAndInventory();
            $inventory = $session['inventory'];
            $pupil = $this->ChoosePupil($inventory);
            if ($pupil === null) {
                throw new Exception('Kein passendes SchoolFox-Kind gefunden.');
            }

            $this->WriteAttributeString('DetectedPupils', (string)json_encode($this->PupilsFromInventory($inventory), JSON_UNESCAPED_UNICODE));

            $messages = $this->FetchMessages($session['base'], $session['token'], $pupil);
            $this->WriteAttributeString('Messages', (string)json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $schoolInfo = [
                'name' => (string)($pupil['name'] ?? ''),
                'class' => (string)($pupil['schoolClassName'] ?? ''),
                'pupilId' => (string)($pupil['id'] ?? ''),
                'schoolId' => (string)($pupil['schoolId'] ?? ''),
                'feature' => (string)($pupil['purchasedFeature'] ?? ''),
                'twoFactorAuthPassed' => (bool)($session['login']['twoFactorAuthPassed'] ?? false),
            ];
            $this->WriteAttributeString('SchoolInfo', (string)json_encode($schoolInfo, JSON_UNESCAPED_UNICODE));

            if ($this->ReadPropertyBoolean('FeatureWatch')) {
                $cap = $this->DetectCapabilities([$session['config'], $session['login'], $inventory]);
                $this->WriteAttributeString('Capabilities', (string)json_encode($cap, JSON_UNESCAPED_UNICODE));
            }

            $now = time();
            $this->WriteAttributeInteger('LastSuccessfulUpdate', $now);
            SetValueInteger($this->GetIDForIdent('LastUpdate'), $now);
            $status = 'OK · ' . count($messages) . ' Mitteilung(en) · ' . count($this->TimetableSubjectsForChild()) . ' Fach/Fächer';
            SetValueString($this->GetIDForIdent('Status'), $status);
            $this->SyncVariables();
            $this->PushTile();
            return $status;
        } catch (Throwable $e) {
            $status = 'Fehler: ' . $e->getMessage();
            SetValueString($this->GetIDForIdent('Status'), $status);
            $this->PushTile();
            $this->LogMessage('SchoolFox: ' . $e->getMessage(), KL_WARNING);
            return $status;
        }
    }

    private function TestConnection(): string
    {
        try {
            $session = $this->LoginAndInventory();
            $pupils = $this->PupilsFromInventory($session['inventory']);
            $this->WriteAttributeString('DetectedPupils', (string)json_encode($pupils, JSON_UNESCAPED_UNICODE));
            if ($pupils === []) {
                return 'LOGIN OK, aber kein SchoolFox-Schüler gefunden.';
            }
            $names = array_map(static fn(array $p): string => (string)$p['name'] . ((string)$p['class'] !== '' ? ' (' . (string)$p['class'] . ')' : ''), $pupils);
            return 'LOGIN OK · erkannt: ' . implode(', ', $names);
        } catch (Throwable $e) {
            return 'Fehler: ' . $e->getMessage();
        }
    }

    /** @return array{base:string,token:string,config:array,login:array,inventory:array} */
    private function LoginAndInventory(): array
    {
        $username = trim($this->ReadPropertyString('Username'));
        $password = $this->ReadPropertyString('Password');
        if ($username === '' || $password === '') {
            throw new Exception('SchoolFox-Benutzername oder Passwort fehlt.');
        }

        $cfg = $this->HttpJson('GET', self::CONFIG_URL, null, '');
        $config = is_array($cfg['json']) ? $cfg['json'] : [];
        $base = trim((string)($config['baseURL'] ?? self::FALLBACK_BASE));
        if ($base === '') {
            $base = self::FALLBACK_BASE;
        }

        $loginR = $this->HttpJson('POST', $this->Url($base, 'api/users/login'), [
            'username' => $username,
            'password' => $password,
            'applicationType' => 'SchoolFox',
        ], '');
        if ($loginR['status'] !== 200 || !is_array($loginR['json']) || trim((string)($loginR['json']['token'] ?? '')) === '') {
            throw new Exception('SchoolFox-Anmeldung fehlgeschlagen (HTTP ' . $loginR['status'] . ').');
        }
        $login = $loginR['json'];
        $token = (string)$login['token'];

        $invR = $this->HttpJson('GET', $this->Url($base, 'api/Common/Inventory'), null, $token);
        if ($invR['status'] !== 200 || !is_array($invR['json'])) {
            throw new Exception('SchoolFox-Inventory nicht lesbar (HTTP ' . $invR['status'] . ').');
        }

        return ['base' => $base, 'token' => $token, 'config' => $config, 'login' => $login, 'inventory' => $invR['json']];
    }

    /** @return list<array<string,mixed>> */
    private function FetchMessages(string $base, string $token, array $pupil): array
    {
        $classId = trim((string)($pupil['schoolClassId'] ?? ''));
        $pupilId = trim((string)($pupil['id'] ?? ''));
        if ($classId === '' || $pupilId === '') {
            return [];
        }

        $filter = "Deleted eq false and SchoolClassId eq '" . str_replace("'", "''", $classId)
            . "' and PupilId eq '" . str_replace("'", "''", $pupilId) . "'";
        $limit = max(1, min(50, $this->ReadPropertyInteger('MessageLimit')));
        $query = http_build_query([
            '$filter' => $filter,
            '$orderby' => 'UpdatedAt desc',
            '$top' => (string)$limit,
            '$inlinecount' => 'allpages',
        ]);

        $res = $this->HttpJson('GET', $this->Url($base, 'tables/Messages') . '?' . $query, null, $token);
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw new Exception('SchoolFox-Mitteilungen nicht lesbar (HTTP ' . $res['status'] . ').');
        }
        $rows = is_array($res['json']['results'] ?? null) ? $res['json']['results'] : [];
        $out = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            $attachments = [];
            if ($id !== '' && $this->ReadPropertyBoolean('ReadAttachments')) {
                try {
                    $ar = $this->HttpJson('GET', $this->Url($base, 'api/Files/Messages/' . rawurlencode($id)), null, $token);
                    if ($ar['status'] === 200 && is_array($ar['json'])) {
                        foreach ($ar['json'] as $a) {
                            if (is_array($a) && trim((string)($a['name'] ?? '')) !== '') {
                                $attachments[] = (string)$a['name'];
                            }
                        }
                    }
                } catch (Throwable $e) {
                    // Ein fehlender Anhang darf die restliche Klassenseite nicht blockieren.
                }
            }
            $content = html_entity_decode(strip_tags((string)($row['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $content = preg_replace('/[\x{00A0}\t]+/u', ' ', $content) ?? $content;
            $content = preg_replace('/\r\n?|\n{3,}/', "\n", $content) ?? $content;
            $out[] = [
                'id' => $id,
                'topic' => trim((string)($row['topic'] ?? '')),
                'sender' => trim((string)($row['senderName'] ?? '')),
                'updatedAt' => (string)($row['updatedAt'] ?? $row['createdAt'] ?? ''),
                'content' => trim($content),
                'isRead' => (bool)($row['isRead'] ?? false),
                'isSigned' => (bool)($row['isSigned'] ?? false),
                'signatureRequired' => (bool)($row['signatureRequired'] ?? false),
                'attachments' => $attachments,
            ];
        }
        return $out;
    }

    /** @return list<array{name:string,class:string,id:string,schoolClassId:string,schoolId:string,feature:string}> */
    private function PupilsFromInventory(array $inventory): array
    {
        $out = [];
        foreach ($inventory as $item) {
            if (!is_array($item) || (string)($item['itemType'] ?? '') !== 'Pupil') {
                continue;
            }
            $out[] = [
                'name' => (string)($item['name'] ?? ''),
                'class' => (string)($item['schoolClassName'] ?? ''),
                'id' => (string)($item['id'] ?? ''),
                'schoolClassId' => (string)($item['schoolClassId'] ?? ''),
                'schoolId' => (string)($item['schoolId'] ?? ''),
                'feature' => (string)($item['purchasedFeature'] ?? ''),
            ];
        }
        return $out;
    }

    private function ChoosePupil(array $inventory): ?array
    {
        $wantedId = trim($this->ReadPropertyString('SchoolFoxPupilID'));
        $wantedName = mb_strtolower(trim($this->ReadPropertyString('ChildName')));
        $first = null;
        foreach ($inventory as $item) {
            if (!is_array($item) || (string)($item['itemType'] ?? '') !== 'Pupil') {
                continue;
            }
            $first ??= $item;
            $id = trim((string)($item['id'] ?? ''));
            $name = mb_strtolower(trim((string)($item['name'] ?? '')));
            if ($wantedId !== '' && $id === $wantedId) {
                return $item;
            }
            if ($wantedId === '' && $wantedName !== '' && ($name === $wantedName || str_starts_with($name, $wantedName . ' '))) {
                return $item;
            }
        }
        return $wantedId === '' ? $first : null;
    }

    /** @return list<string> */
    private function TimetableSubjectsForChild(): array
    {
        $id = $this->ReadPropertyInteger('TimetableInstanceID');
        $childName = mb_strtolower(trim($this->ReadPropertyString('ChildName')));
        if ($id <= 0 || !IPS_InstanceExists($id) || !function_exists('STPL_GetPlan')) {
            return $this->SubjectsWithGrades();
        }
        try {
            $raw = @STPL_GetPlan($id);
            $plan = json_decode((string)$raw, true);
            if (!is_array($plan)) {
                return $this->SubjectsWithGrades();
            }
            $subjects = [];
            foreach ((array)($plan['children'] ?? []) as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $name = mb_strtolower(trim((string)($child['name'] ?? '')));
                if ($childName !== '' && $name !== $childName && !str_starts_with($name, $childName . ' ')) {
                    continue;
                }
                foreach ((array)($child['days'] ?? []) as $day) {
                    foreach ((array)($day['slots'] ?? []) as $slot) {
                        if (!is_array($slot)) {
                            continue;
                        }
                        $subject = trim((string)($slot['name'] ?? $slot['subject'] ?? ''));
                        if ($subject !== '' && !in_array($subject, $subjects, true)) {
                            $subjects[] = $subject;
                        }
                    }
                }
                break; // genau eine Klassenseite / ein Kind
            }
            natcasesort($subjects);
            $subjects = array_values($subjects);
            // Fächer mit alten Noten behalten, auch wenn sie im aktuellen Plan fehlen.
            foreach ($this->SubjectsWithGrades() as $old) {
                if (!in_array($old, $subjects, true)) {
                    $subjects[] = $old;
                }
            }
            return $subjects;
        } catch (Throwable $e) {
            return $this->SubjectsWithGrades();
        }
    }

    /** @return list<string> */
    private function SubjectsWithGrades(): array
    {
        $out = [];
        foreach ($this->Grades() as $g) {
            $s = trim((string)($g['subject'] ?? ''));
            if ($s !== '' && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        natcasesort($out);
        return array_values($out);
    }

    /** @return list<array<string,mixed>> */
    private function Grades(): array
    {
        $a = json_decode($this->ReadAttributeString('Grades'), true);
        return is_array($a) ? array_values(array_filter($a, 'is_array')) : [];
    }

    private function SaveGrades(array $grades): void
    {
        $this->WriteAttributeString('Grades', (string)json_encode(array_values($grades), JSON_UNESCAPED_UNICODE));
        $this->SyncVariables();
        $this->PushTile();
    }

    private function AddGrade(string $payload): void
    {
        $p = json_decode($payload, true);
        if (!is_array($p)) {
            return;
        }
        $subject = trim((string)($p['subject'] ?? ''));
        $value = $this->ParseGrade($p['value'] ?? null);
        if ($subject === '' || $value === null) {
            return;
        }
        try {
            $id = bin2hex(random_bytes(6));
        } catch (Throwable $e) {
            $id = str_replace('.', '', uniqid('g', true));
        }
        $grades = $this->Grades();
        $grades[] = ['id' => $id, 'subject' => $subject, 'value' => $value, 'source' => 'manual', 'createdAt' => time(), 'updatedAt' => time()];
        $this->SaveGrades($grades);
    }

    private function EditGrade(string $payload): void
    {
        $p = json_decode($payload, true);
        if (!is_array($p)) {
            return;
        }
        $id = trim((string)($p['id'] ?? ''));
        $value = $this->ParseGrade($p['value'] ?? null);
        if ($id === '' || $value === null) {
            return;
        }
        $grades = $this->Grades();
        foreach ($grades as &$g) {
            if ((string)($g['id'] ?? '') === $id) {
                $g['value'] = $value;
                $g['updatedAt'] = time();
                break;
            }
        }
        unset($g);
        $this->SaveGrades($grades);
    }

    private function DeleteGrade(string $payload): void
    {
        $p = json_decode($payload, true);
        if (!is_array($p)) {
            return;
        }
        $id = trim((string)($p['id'] ?? ''));
        if ($id === '') {
            return;
        }
        $grades = array_values(array_filter($this->Grades(), static fn(array $g): bool => (string)($g['id'] ?? '') !== $id));
        $this->SaveGrades($grades);
    }

    private function ParseGrade(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        if (!is_numeric($value)) {
            return null;
        }
        $n = (float)$value;
        return ($n >= 1.0 && $n <= 6.0) ? round($n, 2) : null;
    }

    /** @return array{timetable:bool,grades:bool,evidence:array<string,list<string>>} */
    private function DetectCapabilities(array $sources): array
    {
        $evidence = ['timetable' => [], 'grades' => []];
        $this->ScanCapabilityValue($sources, '', $evidence);
        return [
            'timetable' => $evidence['timetable'] !== [],
            'grades' => $evidence['grades'] !== [],
            'evidence' => $evidence,
            'checkedAt' => time(),
        ];
    }

    private function ScanCapabilityValue(mixed $value, string $path, array &$evidence): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $key = is_string($k) ? $k : (string)$k;
                $this->ScanCapabilityValue($v, $path === '' ? $key : ($path . '.' . $key), $evidence);
            }
            return;
        }
        $needle = mb_strtolower($path . ' ' . (is_scalar($value) ? (string)$value : ''));
        $positive = $value === true || (is_numeric($value) && (float)$value > 0) || (is_string($value) && trim($value) !== '' && !in_array(mb_strtolower(trim($value)), ['false', 'none', 'disabled', '0'], true));
        if (!$positive) {
            return;
        }
        if (preg_match('/timetable|stundenplan|lesson.?plan|schedule/i', $needle)) {
            $evidence['timetable'][] = $path;
        }
        if (preg_match('/grade|grading|mark(s)?\b|assessment|zensur|noten?\b/i', $needle)) {
            $evidence['grades'][] = $path;
        }
        $evidence['timetable'] = array_values(array_unique($evidence['timetable']));
        $evidence['grades'] = array_values(array_unique($evidence['grades']));
    }

    private function Capabilities(): array
    {
        $c = json_decode($this->ReadAttributeString('Capabilities'), true);
        return is_array($c) ? $c : [];
    }

    /** @return list<string> */
    private function CapabilityAlerts(): array
    {
        $cap = $this->Capabilities();
        $ack = json_decode($this->ReadAttributeString('CapabilitiesAcknowledged'), true);
        $ack = is_array($ack) ? $ack : [];
        $out = [];
        if (($cap['timetable'] ?? false) && !in_array('timetable', $ack, true)) {
            $out[] = 'SchoolFox liefert jetzt einen eindeutigen Hinweis auf eine Stundenplan-Funktion. Die manuelle SymDo-Vorlage bleibt aktiv, bis die automatische Übernahme gezielt eingerichtet wurde.';
        }
        if (($cap['grades'] ?? false) && !in_array('grades', $ack, true)) {
            $out[] = 'SchoolFox liefert jetzt einen eindeutigen Hinweis auf Noten/Bewertungen. Deine manuellen Noten bleiben unverändert; eine automatische Quelle wird erst nach Prüfung zugeschaltet.';
        }
        return $out;
    }

    private function DismissCapabilities(): void
    {
        $cap = $this->Capabilities();
        $ack = json_decode($this->ReadAttributeString('CapabilitiesAcknowledged'), true);
        $ack = is_array($ack) ? $ack : [];
        foreach (['timetable', 'grades'] as $k) {
            if (($cap[$k] ?? false) && !in_array($k, $ack, true)) {
                $ack[] = $k;
            }
        }
        $this->WriteAttributeString('CapabilitiesAcknowledged', (string)json_encode(array_values(array_unique($ack))));
        $this->PushTile();
    }

    private function BuildTileContent(): string
    {
        $info = json_decode($this->ReadAttributeString('SchoolInfo'), true);
        $info = is_array($info) ? $info : [];
        $child = trim($this->ReadPropertyString('ChildName'));
        if ($child === '') {
            $child = trim((string)($info['name'] ?? 'Tom'));
        }
        $class = trim((string)($info['class'] ?? ''));
        $title = $class !== '' ? ($child . ' – ' . $class) : $child;

        $html = '<div class="sfx-shell"><div class="sfx-head"><h2>Klassenseiten</h2><span class="sfx-badge">SchoolFox</span><span class="sfx-spacer"></span>';
        if ($this->ReadPropertyBoolean('ShowRefreshButton')) {
            $html .= '<button class="sfx-refresh" data-sfx-refresh>↻ Aktualisieren</button>';
        }
        $html .= '</div><div class="sfx-body">';

        foreach ($this->CapabilityAlerts() as $alert) {
            $html .= '<div class="sfx-alert"><div><b>Neue SchoolFox-Funktion erkannt</b><br>' . $this->H($alert) . '</div><button title="Hinweis ausblenden" data-sfx-dismiss>×</button></div>';
        }

        $html .= '<div class="sfx-child">' . $this->H($title) . '</div>';
        $html .= $this->GradesHtml();
        $html .= $this->MessagesHtml();
        $html .= '</div></div>';
        return $html;
    }

    private function GradesHtml(): string
    {
        $subjects = $this->TimetableSubjectsForChild();
        $grades = $this->Grades();
        $html = '<section class="sfx-card"><h3>Noten</h3>';
        if ($subjects === []) {
            return $html . '<div class="sfx-empty">Noch keine Fächer gefunden. Bitte Toms Stundenplan in „SymDo – Stundenplan“ eintragen und diese Instanz dort auswählen.</div></section>';
        }

        $html .= '<div class="sfx-grade-list">';
        foreach ($subjects as $subject) {
            $subjectGrades = array_values(array_filter($grades, static fn(array $g): bool => (string)($g['subject'] ?? '') === $subject));
            $html .= '<div class="sfx-subject"><div class="sfx-subject-name" title="' . $this->HAttr($subject) . '">' . $this->H($subject) . '</div>';
            if ($subjectGrades === []) {
                $html .= '<div class="sfx-grades"><span class="sfx-no-grade">–</span></div><div class="sfx-average">Ø –</div>';
            } else {
                $sum = 0.0;
                $html .= '<div class="sfx-grades">';
                foreach ($subjectGrades as $g) {
                    $value = (float)($g['value'] ?? 0);
                    $sum += $value;
                    $html .= '<button class="sfx-grade" title="Ändern oder löschen" data-id="' . $this->HAttr((string)($g['id'] ?? '')) . '" data-subject="' . $this->HAttr($subject) . '" data-value="' . $this->HAttr($this->GradeText($value)) . '">' . $this->H($this->GradeText($value)) . '</button>';
                }
                $html .= '</div><div class="sfx-average">Ø <b>' . number_format($sum / count($subjectGrades), 2, ',', '.') . '</b></div>';
            }
            $html .= '<button class="sfx-add" title="Note hinzufügen" data-subject="' . $this->HAttr($subject) . '">+</button></div>';
        }
        return $html . '</div></section>';
    }

    private function MessagesHtml(): string
    {
        $messages = json_decode($this->ReadAttributeString('Messages'), true);
        $messages = is_array($messages) ? array_values(array_filter($messages, 'is_array')) : [];
        $height = max(160, min(900, $this->ReadPropertyInteger('MessageScrollHeight')));
        $limit = max(1, min(50, $this->ReadPropertyInteger('MessageLimit')));
        $html = '<section class="sfx-card"><h3>SchoolFox-Mitteilungen <span style="font-size:12px;color:#777;font-weight:400">– neueste ' . $limit . '</span></h3>';
        if ($messages === []) {
            return $html . '<div class="sfx-empty">Noch keine Mitteilungen geladen.</div></section>';
        }
        $html .= '<div class="sfx-message-scroll" style="max-height:' . $height . 'px">';
        foreach (array_slice($messages, 0, $limit) as $m) {
            $topic = trim((string)($m['topic'] ?? '')) ?: 'Mitteilung';
            $sender = trim((string)($m['sender'] ?? ''));
            $date = $this->DateText((string)($m['updatedAt'] ?? ''));
            $attachments = is_array($m['attachments'] ?? null) ? $m['attachments'] : [];
            $suffix = $attachments !== [] ? ' 📎' : '';
            $html .= '<details class="sfx-message"><summary>' . $this->H($topic) . $suffix . '</summary>';
            $meta = trim(($date !== '' ? $date : '') . ($sender !== '' ? (($date !== '' ? ' · ' : '') . $sender) : ''));
            if ($meta !== '') {
                $html .= '<div class="sfx-meta">' . $this->H($meta) . '</div>';
            }
            $text = trim((string)($m['content'] ?? ''));
            if ($text !== '') {
                $html .= '<div class="sfx-text">' . nl2br($this->H($text)) . '</div>';
            }
            if ($attachments !== []) {
                $html .= '<div class="sfx-att"><b>Anhänge:</b> ';
                foreach ($attachments as $a) {
                    $html .= '<span>📎 ' . $this->H((string)$a) . '</span>';
                }
                $html .= '</div>';
            }
            $html .= '</details>';
        }
        return $html . '</div></section>';
    }

    private function PushTile(): void
    {
        try {
            $this->UpdateVisualizationValue($this->BuildTileContent());
        } catch (Throwable $e) {
            // Ältere Visualisierungen laden beim nächsten Öffnen neu.
        }
    }

    private function SyncVariables(): void
    {
        $m = $this->ReadAttributeString('Messages');
        $g = $this->ReadAttributeString('Grades');
        SetValueString($this->GetIDForIdent('MessagesJSON'), $m);
        SetValueString($this->GetIDForIdent('GradesJSON'), $g);
    }

    private function StatusText(): string
    {
        $id = $this->GetIDForIdent('Status');
        if ($id > 0) {
            $s = trim((string)GetValue($id));
            if ($s !== '') {
                return $s;
            }
        }
        $last = $this->ReadAttributeInteger('LastSuccessfulUpdate');
        return $last > 0 ? ('Letzter erfolgreicher Abruf: ' . date('d.m.Y H:i', $last)) : 'Noch nicht abgerufen.';
    }

    private function DetectedPupils(): array
    {
        $d = json_decode($this->ReadAttributeString('DetectedPupils'), true);
        return is_array($d) ? array_values(array_filter($d, 'is_array')) : [];
    }

    /** @return array{status:int,json:mixed,raw:string} */
    private function HttpJson(string $method, string $url, ?array $body, string $token): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new Exception('cURL konnte nicht gestartet werden.');
        }
        $headers = ['Accept: application/json', 'User-Agent: SymDo-SchoolFox/1.0'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($token !== '') {
            $headers[] = 'x-zumo-auth: ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string)json_encode($body ?? [], JSON_UNESCAPED_UNICODE));
        }
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new Exception('HTTP-Fehler: ' . $error);
        }
        $json = json_decode((string)$raw, true);
        return ['status' => $status, 'json' => $json, 'raw' => (string)$raw];
    }

    private function Url(string $base, string $path): string
    {
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    private function GradeText(float $v): string
    {
        return abs($v - round($v)) < 0.0001 ? (string)(int)round($v) : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private function DateText(string $iso): string
    {
        if (trim($iso) === '') {
            return '';
        }
        $ts = strtotime($iso);
        return $ts === false ? '' : date('d.m.Y H:i', $ts);
    }

    private function H(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function HAttr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
