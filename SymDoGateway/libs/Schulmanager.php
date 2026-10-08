<?php

declare(strict_types=1);

/**
 * Schulmanager Online -> SymDo Gateway.
 *
 * Reine Leseanbindung. Sie holt je Elternkonto die verknuepften Kinder,
 * Stundenplan/Vertretungen, Hausaufgaben, Klassenarbeiten und Elternbriefe.
 * Der Stundenplan geht ueber STPL_ImportSlots in die vorhandene
 * SymDo-Stundenplaninstanz. Hausaufgaben landen im vorhandenen Gateway-Bestand.
 * Elternbriefe koennen als neue Quelle durch die bestehende KI-Mailanalyse laufen.
 *
 * Stand: 07.10.2026. Die verwendeten Schulmanager-Endpunkte sind interne
 * Web-Endpunkte, keine offiziell zugesagte Eltern-API. Darum ist die gesamte
 * Anbindung strikt read-only und faellt bei unbekannten Antwortformen lieber aus,
 * als Daten zu erfinden oder zu loeschen.
 */
trait Schulmanager
{
    private const SM_BASE = 'https://login.schulmanager-online.de';
    private const SM_ACCOUNT_MAX = 3;
    private const SM_INTERVAL_DEFAULT = 30;      // Minuten
    private const SM_INTERVAL_MIN = 15;
    private const SM_INTERVAL_MAX = 1440;
    private const SM_FAIL_MAX = 3;
    private const SM_FAIL_PAUSE = 6 * 3600;
    private const SM_HTTP_TIMEOUT = 30;
    private const SM_BUNDLE_FALLBACK = '3505280ee7';
    private const SM_PLAN_DAYS = 14;
    private const SM_EXAM_DAYS = 56;
    private const SM_HOMEWORK_BACK_DAYS = 21;
    private const SM_HOMEWORK_FORWARD_DAYS = 60;
    private const SM_TEMPLATE_DAYS = 28;

    private function SchulmanagerCreate(): void
    {
        $this->RegisterPropertyBoolean('SchulmanagerEnabled', false);
        $this->RegisterPropertyInteger('SchulmanagerIntervalMinutes', self::SM_INTERVAL_DEFAULT);
        $this->RegisterPropertyBoolean('SchulmanagerHomework', true);
        $this->RegisterPropertyBoolean('SchulmanagerExams', true);
        $this->RegisterPropertyBoolean('SchulmanagerLetters', true);
        $this->RegisterPropertyBoolean('SchulmanagerLettersAI', false);
        $this->RegisterPropertyBoolean('SchulmanagerPush', true);

        for ($i = 1; $i <= self::SM_ACCOUNT_MAX; $i++) {
            $this->RegisterPropertyBoolean('SchulmanagerAccount' . $i . 'Enabled', false);
            $this->RegisterPropertyString('SchulmanagerAccount' . $i . 'Name', 'Konto ' . $i);
            $this->RegisterPropertyString('SchulmanagerAccount' . $i . 'User', '');
            $this->RegisterPropertyString('SchulmanagerAccount' . $i . 'Password', '');
        }

        /* Eine Zeile je Kind: Schulmanager-Kind -> SymDo-Mitglied -> Stundenplan. */
        $this->RegisterPropertyString('SchulmanagerStudents', '[]');

        /* Nur Konto-Kinder, die der jeweilige Login wirklich liefert. */
        $this->RegisterAttributeString('SchulmanagerAccountStudents', '[]');
        $this->RegisterAttributeString('SchulmanagerStatus', '{}');
        $this->RegisterAttributeString('SchulmanagerFails', '{}');
        $this->RegisterAttributeString('SchulmanagerLast', '{}');
        $this->RegisterAttributeString('SchulmanagerLettersSeen', '{}');
        $this->RegisterAttributeString('SchulmanagerBundleVersion', self::SM_BUNDLE_FALLBACK);
        $this->RegisterAttributeInteger('SchulmanagerBundleAt', 0);

        $this->RegisterTimer(
            'SchulmanagerScan',
            0,
            'IPS_RequestAction($_IPS[\'TARGET\'], \'SchulmanagerScan\', 0);'
        );
    }

    private function SchulmanagerApplyChanges(): void
    {
        $min = max(self::SM_INTERVAL_MIN, min(
            self::SM_INTERVAL_MAX,
            (int)$this->ReadPropertyInteger('SchulmanagerIntervalMinutes')
        ));
        $an = (bool)$this->ReadPropertyBoolean('SchulmanagerEnabled')
            && $this->SchulmanagerKonten() !== []
            && $this->SchulmanagerKinder() !== [];
        @$this->SetTimerInterval('SchulmanagerScan', $an ? $min * 60000 : 0);
    }

    private function SchulmanagerRequestAction(string $Ident, mixed $Value): bool
    {
        switch ($Ident) {
            case 'SchulmanagerScan':
                $this->SchulmanagerScanRun(false);
                return true;
            case 'SchulmanagerScanNow':
                $this->UpdateFormField('SchulmanagerStatusLabel', 'caption', $this->SchulmanagerScanRun(true));
                return true;
            case 'SchulmanagerScanApply':
                $this->UpdateFormField('SchulmanagerStatusLabel', 'caption', $this->SchulmanagerScanRun(false));
                return true;
            case 'SchulmanagerTest':
                $this->UpdateFormField('SchulmanagerStatusLabel', 'caption', $this->SchulmanagerTestverbindung());
                return true;
            case 'SchulmanagerFetchStudents':
                $this->SchulmanagerKontoSchuelerHolen();
                return true;
            case 'SchulmanagerTemplate':
                $this->UpdateFormField('SchulmanagerStatusLabel', 'caption', $this->SchulmanagerVorlageUebernehmen());
                return true;
        }
        return false;
    }

    /**
     * Formularblock unter Schule.
     */
    private function GetSchulmanagerPanel(): array
    {
        $stand = json_decode((string)@$this->ReadAttributeString('SchulmanagerStatus'), true);
        $zeile = is_array($stand) && trim((string)($stand['text'] ?? '')) !== ''
            ? 'Letzter Lauf ' . date('d.m.Y H:i', (int)($stand['t'] ?? 0)) . ': ' . (string)$stand['text']
            : 'Noch nicht ausgeführt.';

        $konten = [];
        for ($i = 1; $i <= self::SM_ACCOUNT_MAX; $i++) {
            $konten[] = [
                'type' => 'ExpansionPanel',
                'expanded' => $i === 1,
                'caption' => 'Konto ' . $i,
                'items' => [
                    ['type' => 'CheckBox', 'name' => 'SchulmanagerAccount' . $i . 'Enabled',
                     'caption' => 'Konto verwenden'],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'ValidationTextBox', 'name' => 'SchulmanagerAccount' . $i . 'Name',
                         'width' => '180px', 'caption' => 'Bezeichnung'],
                        ['type' => 'ValidationTextBox', 'name' => 'SchulmanagerAccount' . $i . 'User',
                         'width' => '260px', 'caption' => 'E-Mail / Benutzername'],
                        ['type' => 'PasswordTextBox', 'name' => 'SchulmanagerAccount' . $i . 'Password',
                         'width' => '240px', 'caption' => 'Passwort'],
                    ]],
                ],
            ];
        }

        return [
            'type' => 'ExpansionPanel',
            'caption' => 'Schulmanager Online (Stundenplan, Vertretungen, Hausaufgaben, Prüfungen, Elternbriefe)',
            'expanded' => false,
            'items' => [
                ['type' => 'Label', 'caption' =>
                    'Liest ausschließlich den eigenen Eltern-/Schülerzugang. SymDo schreibt nichts in Schulmanager zurück. '
                    . 'Die Web-Schnittstelle von Schulmanager ist nicht als öffentliche Eltern-API zugesagt; Änderungen bei Schulmanager können daher eine Anpassung des Moduls nötig machen.'],
                ['type' => 'CheckBox', 'name' => 'SchulmanagerEnabled', 'caption' => 'Schulmanager Online aktivieren'],
                ['type' => 'NumberSpinner', 'name' => 'SchulmanagerIntervalMinutes',
                 'minimum' => self::SM_INTERVAL_MIN, 'maximum' => self::SM_INTERVAL_MAX,
                 'suffix' => ' min', 'caption' => 'Automatisch abrufen alle'],
                ['type' => 'Label', 'caption' =>
                    'Empfehlung: 30 Minuten. 15 Minuten nur, wenn Vertretungen sehr kurzfristig kommen. Bei zwei Kindern unter demselben Elternkonto wird nur einmal angemeldet und danach beide Kinder gelesen.'],
                ...$konten,
                ['type' => 'Button', 'caption' => 'Kinder der Konten abrufen',
                 'onClick' => 'IPS_RequestAction($id, \'SchulmanagerFetchStudents\', 0);'],
                ['type' => 'List', 'name' => 'SchulmanagerStudents', 'rowCount' => 4,
                 'add' => true, 'delete' => true, 'caption' => 'Zuordnung der Kinder',
                 'columns' => $this->SchulmanagerStudentsSpalten()],
                ['type' => 'Label', 'caption' =>
                    'Je Kind: zuerst das Schulmanager-Kind wählen, dann das passende SymDo-Familienmitglied und dieselbe SymDo-Stundenplan-Instanz. Mehrere Kinder dürfen dieselbe Stundenplan-Instanz verwenden.'],
                ['type' => 'CheckBox', 'name' => 'SchulmanagerHomework', 'caption' => 'Hausaufgaben übernehmen'],
                ['type' => 'CheckBox', 'name' => 'SchulmanagerExams', 'caption' => 'Klassenarbeiten / Prüfungen übernehmen'],
                ['type' => 'CheckBox', 'name' => 'SchulmanagerLetters', 'caption' => 'Neue Elternbriefe erkennen'],
                ['type' => 'CheckBox', 'name' => 'SchulmanagerLettersAI',
                 'caption' => 'Neue Elternbriefe durch die vorhandene SymDo-KI auswerten und in den KI-Eingang geben'],
                ['type' => 'Label', 'caption' =>
                    'Beim ersten Abruf werden vorhandene Elternbriefe nur als Bestand gemerkt. Dadurch laufen nicht sofort alle alten Briefe durch die KI. Erst neue Briefe danach werden ausgewertet.'],
                ['type' => 'CheckBox', 'name' => 'SchulmanagerPush',
                 'caption' => 'Push bei neuen Vertretungen/Entfällen und neuen Elternbriefen'],
                ['type' => 'Label', 'caption' =>
                    'Prüfungen verwenden vorerst den vorhandenen SymDo-Prüfungsbestand. WebUntis und Schulmanager deshalb nicht gleichzeitig für dasselbe Kind als Prüfungsquelle aktivieren.'],
                ['type' => 'Label', 'name' => 'SchulmanagerStatusLabel', 'caption' => $zeile],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => 'Verbindung testen',
                     'onClick' => 'IPS_RequestAction($id, \'SchulmanagerTest\', 0);'],
                    ['type' => 'Button', 'caption' => 'Trockenlauf',
                     'onClick' => 'IPS_RequestAction($id, \'SchulmanagerScanNow\', 0);'],
                    ['type' => 'Button', 'caption' => 'Jetzt abrufen und übernehmen',
                     'onClick' => 'IPS_RequestAction($id, \'SchulmanagerScanApply\', 0);'],
                ]],
                ['type' => 'Button', 'caption' => 'Wochenvorlage aus Schulmanager übernehmen',
                 'confirm' => 'Die reguläre Wochenvorlage der zugeordneten Kinder wird aus den nächsten Wochen abgeleitet und in die gewählte Stundenplan-Instanz geschrieben. Fortfahren?',
                 'onClick' => 'IPS_RequestAction($id, \'SchulmanagerTemplate\', 0);'],
                ['type' => 'Label', 'caption' =>
                    'Die Wochenvorlage wird nur auf Knopfdruck geschrieben. Der normale 30-Minuten-Abruf schreibt ausschließlich datierte Tage darüber: Vertretung, Entfall, Raumwechsel und Veranstaltungen.'],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function SchulmanagerStudentsSpalten(): array
    {
        $remote = [];
        $gefunden = json_decode((string)@$this->ReadAttributeString('SchulmanagerAccountStudents'), true);
        foreach (is_array($gefunden) ? $gefunden : [] as $s) {
            if (!is_array($s) || trim((string)($s['key'] ?? '')) === '') {
                continue;
            }
            $konto = (int)($s['account'] ?? 0);
            $name = trim((string)($s['name'] ?? ''));
            $label = trim((string)($s['accountName'] ?? 'Konto ' . $konto));
            $remote[] = [
                'caption' => $label . ' · ' . ($name !== '' ? $name : ('ID ' . (int)($s['id'] ?? 0))),
                'value' => (string)$s['key'],
            ];
        }

        $mitglieder = [['caption' => '— keins —', 'value' => '']];
        foreach ($this->SchulmanagerMitglieder() as $id => $name) {
            $mitglieder[] = ['caption' => $name, 'value' => (string)$id];
        }

        return [
            ['caption' => 'Schulmanager-Kind', 'name' => 'remote', 'width' => '260px',
             'add' => '', 'edit' => ['type' => 'Select', 'options' => $remote]],
            ['caption' => 'SymDo-Mitglied', 'name' => 'userId', 'width' => '180px',
             'add' => '', 'edit' => ['type' => 'Select', 'options' => $mitglieder]],
            ['caption' => 'Stundenplan-Instanz', 'name' => 'stpl', 'width' => '240px',
             'add' => 0, 'edit' => ['type' => 'SelectInstance']],
        ];
    }

    /** @return list<array{nr:int,name:string,user:string,password:string}> */
    private function SchulmanagerKonten(): array
    {
        $out = [];
        for ($i = 1; $i <= self::SM_ACCOUNT_MAX; $i++) {
            if (!(bool)$this->ReadPropertyBoolean('SchulmanagerAccount' . $i . 'Enabled')) {
                continue;
            }
            $user = trim((string)$this->ReadPropertyString('SchulmanagerAccount' . $i . 'User'));
            $pass = (string)$this->ReadPropertyString('SchulmanagerAccount' . $i . 'Password');
            if ($user === '' || $pass === '') {
                continue;
            }
            $name = trim((string)$this->ReadPropertyString('SchulmanagerAccount' . $i . 'Name'));
            $out[] = ['nr' => $i, 'name' => $name !== '' ? $name : 'Konto ' . $i, 'user' => $user, 'password' => $pass];
        }
        return $out;
    }

    /** @return array<string,string> */
    private function SchulmanagerMitglieder(): array
    {
        $out = [];
        try {
            foreach ($this->LoadUsers() as $u) {
                $id = trim((string)($u['id'] ?? ''));
                $name = trim((string)($u['name'] ?? ''));
                if ($id !== '' && $name !== '') {
                    $out[$id] = $name;
                }
            }
        } catch (\Throwable $e) {
            $this->SendDebug('Schulmanager', 'Mitglieder nicht lesbar: ' . $e->getMessage(), 0);
        }
        return $out;
    }

    /**
     * @return list<array{remote:string,account:int,studentId:int,userId:string,name:string,stpl:int,child:string}>
     */
    private function SchulmanagerKinder(): array
    {
        $rows = json_decode((string)$this->ReadPropertyString('SchulmanagerStudents'), true);
        $members = $this->SchulmanagerMitglieder();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $remote = trim((string)($r['remote'] ?? ''));
            if (preg_match('/^(\d+):(\d+)$/', $remote, $m) !== 1) {
                continue;
            }
            $account = (int)$m[1];
            $studentId = (int)$m[2];
            $userId = trim((string)($r['userId'] ?? ''));
            $stpl = (int)($r['stpl'] ?? 0);
            $name = $userId !== '' ? (string)($members[$userId] ?? '') : '';
            if ($name === '') {
                $name = 'Schulmanager ' . $studentId;
            }
            $child = $this->SchulmanagerKindImPlan($stpl, $userId, $name);
            $out[] = [
                'remote' => $remote,
                'account' => $account,
                'studentId' => $studentId,
                'userId' => $userId,
                'name' => $name,
                'stpl' => $stpl,
                'child' => $child,
            ];
        }
        return $out;
    }

    private function SchulmanagerKindImPlan(int $stpl, string $userId, string $name): string
    {
        if ($stpl <= 0 || !IPS_InstanceExists($stpl)) {
            return '';
        }
        $children = json_decode((string)@IPS_GetProperty($stpl, 'Children'), true);
        if (!is_array($children)) {
            return '';
        }
        $nameHit = '';
        foreach ($children as $c) {
            if (!is_array($c)) {
                continue;
            }
            $n = trim((string)($c['name'] ?? ''));
            if ($n === '') {
                continue;
            }
            if ($userId !== '' && trim((string)($c['userId'] ?? '')) === $userId) {
                return $n;
            }
            if (mb_strtolower($n) === mb_strtolower($name)) {
                $nameHit = $n;
            }
        }
        return $nameHit;
    }

    /**
     * Kinder aller aktivierten Konten laden und nur diese Auswahl im Formular zeigen.
     */
    private function SchulmanagerKontoSchuelerHolen(): void
    {
        $alle = [];
        $teile = [];
        foreach ($this->SchulmanagerKonten() as $konto) {
            $nr = (int)$konto['nr'];
            if ($this->SchulmanagerGesperrt($nr)) {
                $teile[] = $konto['name'] . ': nach Fehlanmeldungen pausiert';
                continue;
            }
            $login = $this->SchulmanagerLogin($konto);
            if (($login['ok'] ?? false) !== true) {
                $this->SchulmanagerFehlerZaehlen($nr, (int)($login['status'] ?? 0));
                $teile[] = $konto['name'] . ': Login fehlgeschlagen (' . (string)($login['message'] ?? '?') . ')';
                continue;
            }
            $this->SchulmanagerFehlerReset($nr);
            $n = 0;
            foreach ((array)($login['students'] ?? []) as $student) {
                if (!is_array($student) || (int)($student['id'] ?? 0) <= 0) {
                    continue;
                }
                $id = (int)$student['id'];
                $name = trim((string)($student['firstname'] ?? '') . ' ' . (string)($student['lastname'] ?? ''));
                if ($name === '') {
                    $name = trim((string)($student['name'] ?? ''));
                }
                $alle[] = [
                    'key' => $nr . ':' . $id,
                    'account' => $nr,
                    'accountName' => (string)$konto['name'],
                    'id' => $id,
                    'name' => $name,
                    'classId' => $this->SchulmanagerStudentClassId($student),
                ];
                $n++;
            }
            $teile[] = $konto['name'] . ': ' . $n . ' Kind(er)';
        }

        @$this->WriteAttributeString('SchulmanagerAccountStudents', (string)json_encode($alle, JSON_UNESCAPED_UNICODE));
        $this->UpdateFormField('SchulmanagerStudents', 'columns',
            (string)json_encode($this->SchulmanagerStudentsSpalten(), JSON_UNESCAPED_UNICODE));
        $text = $alle === [] ? 'Keine Kinder gefunden. ' . implode(' | ', $teile)
                            : count($alle) . ' Kind(er) abgerufen. ' . implode(' | ', $teile);
        $this->UpdateFormField('SchulmanagerStatusLabel', 'caption', $text);
    }

    private function SchulmanagerTestverbindung(): string
    {
        $konten = $this->SchulmanagerKonten();
        if ($konten === []) {
            return 'Kein verwendbares Schulmanager-Konto eingetragen.';
        }
        $teile = [];
        foreach ($konten as $konto) {
            $nr = (int)$konto['nr'];
            /* Der Test ist der bewusste Eingriff des Nutzers: einen alten Riegel loesen. */
            $this->SchulmanagerFehlerReset($nr);
            $login = $this->SchulmanagerLogin($konto);
            if (($login['ok'] ?? false) !== true) {
                $this->SchulmanagerFehlerZaehlen($nr, (int)($login['status'] ?? 0));
                $teile[] = $konto['name'] . ': FEHLER – ' . (string)($login['message'] ?? '?');
                continue;
            }
            $this->SchulmanagerFehlerReset($nr);
            $students = (array)($login['students'] ?? []);
            $inst = (int)($login['institutionId'] ?? 0);
            $teile[] = $konto['name'] . ': OK, ' . count($students) . ' Kind(er)'
                . ($inst > 0 ? ', Institution ' . $inst : '');
        }
        return implode(' | ', $teile);
    }

    private function SchulmanagerScanRun(bool $trocken = false): string
    {
        if (!(bool)$this->ReadPropertyBoolean('SchulmanagerEnabled')) {
            return 'Schulmanager Online ist ausgeschaltet.';
        }
        $kinder = $this->SchulmanagerKinder();
        if ($kinder === []) {
            return 'Noch kein Schulmanager-Kind zugeordnet.';
        }

        $konten = [];
        foreach ($this->SchulmanagerKonten() as $k) {
            $konten[(int)$k['nr']] = $k;
        }
        if ($konten === []) {
            return 'Kein verwendbares Schulmanager-Konto eingetragen.';
        }

        $byAccount = [];
        foreach ($kinder as $kind) {
            $byAccount[(int)$kind['account']][] = $kind;
        }

        $teile = [];
        foreach ($byAccount as $nr => $mapKinder) {
            if (!isset($konten[$nr])) {
                $teile[] = 'Konto ' . $nr . ': nicht aktiv';
                continue;
            }
            if ($this->SchulmanagerGesperrt((int)$nr)) {
                $teile[] = $konten[$nr]['name'] . ': nach Fehlanmeldungen pausiert';
                continue;
            }
            $login = $this->SchulmanagerLogin($konten[$nr]);
            if (($login['ok'] ?? false) !== true) {
                $this->SchulmanagerFehlerZaehlen((int)$nr, (int)($login['status'] ?? 0));
                $teile[] = $konten[$nr]['name'] . ': Login fehlgeschlagen – ' . (string)($login['message'] ?? '?');
                continue;
            }
            $this->SchulmanagerFehlerReset((int)$nr);

            $students = [];
            foreach ((array)($login['students'] ?? []) as $s) {
                if (is_array($s) && (int)($s['id'] ?? 0) > 0) {
                    $students[(int)$s['id']] = $s;
                }
            }

            foreach ($mapKinder as $kind) {
                $sid = (int)$kind['studentId'];
                if (!isset($students[$sid])) {
                    $teile[] = $kind['name'] . ': Kind nicht mehr am Konto gefunden';
                    continue;
                }
                if ((int)$kind['stpl'] <= 0 || !IPS_InstanceExists((int)$kind['stpl'])) {
                    $teile[] = $kind['name'] . ': keine gültige Stundenplan-Instanz';
                    continue;
                }
                if ((string)$kind['child'] === '') {
                    $teile[] = $kind['name'] . ': Familienmitglied ist in der Stundenplan-Instanz nicht verknüpft';
                    continue;
                }

                $ernte = $this->SchulmanagerKindErnten($login, $students[$sid], $kind);
                if (($ernte['ok'] ?? false) !== true) {
                    $teile[] = $kind['name'] . ': ' . (string)($ernte['message'] ?? 'Abruf fehlgeschlagen');
                    continue;
                }
                $teile[] = $this->SchulmanagerKindEinpflegen($kind, $ernte, $trocken);
            }

            if ((bool)$this->ReadPropertyBoolean('SchulmanagerLetters')) {
                $letterText = $this->SchulmanagerBriefeEinpflegen((int)$nr, $login, $mapKinder, $trocken);
                if ($letterText !== '') {
                    $teile[] = $konten[$nr]['name'] . ': ' . $letterText;
                }
            }
        }

        $text = implode(' | ', array_values(array_filter($teile, static fn(string $x): bool => trim($x) !== '')));
        if ($text === '') {
            $text = 'Nichts zu tun.';
        }
        if (!$trocken) {
            $this->SchulmanagerStatusSchreiben($text);
        }
        return $text;
    }

    /**
     * @param array<string,mixed> $login
     * @param array<string,mixed> $student
     * @param array<string,mixed> $kind
     * @return array<string,mixed>
     */
    private function SchulmanagerKindErnten(array $login, array $student, array $kind): array
    {
        $token = (string)($login['token'] ?? '');
        $bundle = (string)($login['bundle'] ?? self::SM_BUNDLE_FALLBACK);
        if ($token === '') {
            return ['ok' => false, 'message' => 'kein Token'];
        }

        $heute = new \DateTimeImmutable('today');
        $planBis = $heute->modify('+' . (self::SM_PLAN_DAYS - 1) . ' days');
        $examBis = $heute->modify('+' . self::SM_EXAM_DAYS . ' days');
        $sid = (int)($student['id'] ?? 0);
        $classId = $this->SchulmanagerStudentClassId($student);

        $hoursResp = $this->SchulmanagerApi($token, $bundle, 'schedules', 'get-class-hours',
            $classId > 0 ? ['classId' => $classId] : []);
        if (($hoursResp['ok'] ?? false) !== true) {
            return ['ok' => false, 'message' => 'Stundenzeiten: ' . (string)($hoursResp['message'] ?? '?')];
        }
        $hours = $this->SchulmanagerClassHoursMap((array)($hoursResp['data'] ?? []));

        $examRows = [];
        $exams = [];
        if ((bool)$this->ReadPropertyBoolean('SchulmanagerExams')) {
            $examResp = $this->SchulmanagerApi($token, $bundle, 'exams', 'get-exams', [
                'student' => ['id' => $sid],
                'start' => $heute->format('Y-m-d'),
                'end' => $examBis->format('Y-m-d'),
            ]);
            if (($examResp['ok'] ?? false) === true) {
                $examRows = array_values(array_filter((array)($examResp['data'] ?? []), 'is_array'));
                $exams = $this->SchulmanagerPruefungenNormieren($examRows, $hours);
            }
        }

        $planResp = $this->SchulmanagerApi($token, $bundle, 'schedules', 'get-actual-lessons', [
            'student' => ['id' => $sid],
            'start' => $heute->format('Y-m-d'),
            'end' => $planBis->format('Y-m-d'),
        ]);
        if (($planResp['ok'] ?? false) !== true) {
            return ['ok' => false, 'message' => 'Stundenplan: ' . (string)($planResp['message'] ?? '?')];
        }

        $planRows = array_values(array_filter((array)($planResp['data'] ?? []), 'is_array'));
        [$days, $changes] = $this->SchulmanagerPlanAbbilden($planRows, $hours, $examRows);

        $homework = null;
        if ((bool)$this->ReadPropertyBoolean('SchulmanagerHomework')) {
            $hwResp = $this->SchulmanagerApi($token, $bundle, 'classbook', 'get-homework', [
                'student' => ['id' => $sid],
            ]);
            if (($hwResp['ok'] ?? false) === true) {
                $homework = $this->SchulmanagerHausaufgabenNormieren((array)($hwResp['data'] ?? []));
            }
        }

        return [
            'ok' => true,
            'days' => $days,
            'changes' => $changes,
            'lessons' => count($planRows),
            'homework' => $homework,
            'exams' => $exams,
            'examCount' => count($exams),
        ];
    }

    /**
     * @param array<string,mixed> $kind
     * @param array<string,mixed> $ernte
     */
    private function SchulmanagerKindEinpflegen(array $kind, array $ernte, bool $trocken): string
    {
        $lessonCount = (int)($ernte['lessons'] ?? 0);
        $changes = (array)($ernte['changes'] ?? []);
        $homework = $ernte['homework'] ?? null;
        $exams = is_array($ernte['exams'] ?? null) ? $ernte['exams'] : [];

        if ($trocken) {
            return sprintf('%s: %d Stunde(n), %d Änderung(en), %d Prüfung(en)%s — Trockenlauf',
                (string)$kind['name'], $lessonCount, count($changes), count($exams),
                is_array($homework) ? ', ' . count($homework) . ' Hausaufgabe(n)' : '');
        }

        $days = (array)($ernte['days'] ?? []);
        $written = $days !== [] ? $this->SchulmanagerEinspielen($kind, $days, 'Schulmanager Online') : 0;

        $hwText = '';
        if (is_array($homework)) {
            $from = date('Y-m-d', strtotime('-' . self::SM_HOMEWORK_BACK_DAYS . ' days'));
            $to = date('Y-m-d', strtotime('+' . self::SM_HOMEWORK_FORWARD_DAYS . ' days'));
            $e = $this->HomeworkImportieren((string)$kind['userId'], $homework, $from, $to, 'schulmanager');
            $hwText = (($e['ok'] ?? false) === true)
                ? sprintf(', Hausaufgaben %d (%d neu, %d geändert, %d entfernt)',
                    count($homework), (int)($e['neu'] ?? 0), (int)($e['geaendert'] ?? 0), (int)($e['entfernt'] ?? 0))
                : ', Hausaufgaben nicht übernommen (' . (string)($e['fehler'] ?? '?') . ')';
        }

        $examText = '';
        if ($exams !== [] && method_exists($this, 'UntisPruefungenEinpflegen')) {
            /* Der vorhandene Prüfungsbestand speist Web-App, Kachel und Briefing.
               Bis er quellenneutral ist, darf für dasselbe Kind nur EINE der beiden
               Schulquellen Prüfungen liefern (Hinweis im Formular). */
            $examText = ', ' . $this->UntisPruefungenEinpflegen([
                'userId' => (string)$kind['userId'],
                'name' => (string)$kind['name'],
            ], $exams);
        }

        $push = $this->SchulmanagerAenderungenMelden($kind, $changes);
        return sprintf('%s: %d Stunde(n), %d Tag(e) geschrieben, %d Änderung(en)%s%s%s',
            (string)$kind['name'], $lessonCount, $written, count($changes),
            $hwText, $examText, $push > 0 ? ', Push ' . $push : '');
    }

    /** @param array<string,mixed> $kind */
    private function SchulmanagerEinspielen(array $kind, array $days, string $source): int
    {
        if (!function_exists('STPL_ImportSlots')) {
            $this->SendDebug('Schulmanager', 'STPL_ImportSlots fehlt — Kernel-Neustart nötig', 0);
            return 0;
        }
        $payload = (string)json_encode([
            'child' => (string)$kind['child'],
            'source' => $source,
            'days' => (object)$days,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            $answer = json_decode((string)@STPL_ImportSlots((int)$kind['stpl'], $payload), true);
        } catch (\Throwable $e) {
            $this->LogMessage('SymDo Schulmanager: Stundenplan-Einspielen warf — ' . $e->getMessage(), KL_ERROR);
            return 0;
        }
        if (($answer['ok'] ?? false) !== true) {
            $this->LogMessage('SymDo Schulmanager: Stundenplan abgelehnt — '
                . (string)($answer['error']['message'] ?? '?'), KL_ERROR);
            return 0;
        }
        return count((array)($answer['tage'] ?? []));
    }

    /**
     * Einmalige Wochenvorlage aus vier Wochen ableiten. Nur per Knopfdruck.
     */
    private function SchulmanagerVorlageUebernehmen(): string
    {
        if (!(bool)$this->ReadPropertyBoolean('SchulmanagerEnabled')) {
            return 'Schulmanager Online ist ausgeschaltet.';
        }
        $kinder = $this->SchulmanagerKinder();
        if ($kinder === []) {
            return 'Noch kein Schulmanager-Kind zugeordnet.';
        }
        $konten = [];
        foreach ($this->SchulmanagerKonten() as $k) {
            $konten[(int)$k['nr']] = $k;
        }
        $groups = [];
        foreach ($kinder as $k) {
            $groups[(int)$k['account']][] = $k;
        }
        $teile = [];
        foreach ($groups as $nr => $mapKinder) {
            if (!isset($konten[$nr]) || $this->SchulmanagerGesperrt((int)$nr)) {
                continue;
            }
            $login = $this->SchulmanagerLogin($konten[$nr]);
            if (($login['ok'] ?? false) !== true) {
                $teile[] = $konten[$nr]['name'] . ': Login fehlgeschlagen';
                continue;
            }
            $students = [];
            foreach ((array)($login['students'] ?? []) as $s) {
                if (is_array($s) && (int)($s['id'] ?? 0) > 0) {
                    $students[(int)$s['id']] = $s;
                }
            }
            foreach ($mapKinder as $kind) {
                $sid = (int)$kind['studentId'];
                if (!isset($students[$sid]) || (string)$kind['child'] === '') {
                    continue;
                }
                $student = $students[$sid];
                $classId = $this->SchulmanagerStudentClassId($student);
                $hoursResp = $this->SchulmanagerApi((string)$login['token'], (string)$login['bundle'],
                    'schedules', 'get-class-hours', $classId > 0 ? ['classId' => $classId] : []);
                if (($hoursResp['ok'] ?? false) !== true) {
                    $teile[] = $kind['name'] . ': Stundenzeiten fehlen';
                    continue;
                }
                $hours = $this->SchulmanagerClassHoursMap((array)($hoursResp['data'] ?? []));
                $start = new \DateTimeImmutable('today');
                $end = $start->modify('+' . self::SM_TEMPLATE_DAYS . ' days');
                $planResp = $this->SchulmanagerApi((string)$login['token'], (string)$login['bundle'],
                    'schedules', 'get-actual-lessons', [
                        'student' => ['id' => $sid],
                        'start' => $start->format('Y-m-d'),
                        'end' => $end->format('Y-m-d'),
                    ]);
                if (($planResp['ok'] ?? false) !== true) {
                    $teile[] = $kind['name'] . ': Plan nicht lesbar';
                    continue;
                }
                $days = $this->SchulmanagerVorlageBauen((array)($planResp['data'] ?? []), $hours);
                $written = $days !== [] ? $this->SchulmanagerEinspielen($kind, $days, 'Schulmanager Vorlage') : 0;
                $teile[] = $kind['name'] . ': Vorlage ' . $written . ' Wochentag(e)';
            }
        }
        $text = $teile === [] ? 'Keine Vorlage geschrieben.' : implode(' | ', $teile);
        $this->SchulmanagerStatusSchreiben($text);
        return $text;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,array<string,mixed>> $hours
     * @return array<int,list<array<string,mixed>>>
     */
    private function SchulmanagerVorlageBauen(array $rows, array $hours): array
    {
        /* Kandidaten je Wochentag + Stunden-Nummer. Die haeufigste Kombination
           gewinnt; so macht eine einzelne Vertretung nicht die Wochenvorlage kaputt. */
        $cand = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $date = trim((string)($r['date'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }
            $weekday = (int)date('N', (int)strtotime($date . ' 12:00:00'));
            if ($weekday < 1 || $weekday > 6) {
                continue;
            }
            $type = (string)($r['type'] ?? '');
            $lesson = null;
            if ($type === 'regularLesson') {
                $lesson = is_array($r['actualLesson'] ?? null) ? $r['actualLesson'] : null;
            } elseif (in_array($type, ['changedLesson', 'cancelledLesson'], true)) {
                $orig = array_values(array_filter((array)($r['originalLessons'] ?? []), 'is_array'));
                $lesson = $orig[0] ?? null;
            }
            if (!is_array($lesson)) {
                continue;
            }
            [$start, $end] = $this->SchulmanagerZeitFuerEintrag($r, $hours);
            if ($start === '' || $end === '') {
                continue;
            }
            $subject = $this->SchulmanagerSubject($lesson);
            if ($subject === '') {
                continue;
            }
            $hourNo = trim((string)($r['classHour']['number'] ?? ''));
            $key = $weekday . '|' . $hourNo;
            $slot = [
                'subject' => $subject,
                'start' => $start,
                'end' => $end,
                'room' => $this->SchulmanagerRoom($lesson),
                'teacher' => $this->SchulmanagerTeachers($lesson['teachers'] ?? []),
                'status' => 'normal',
            ];
            $signature = json_encode($slot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $cand[$key][$signature] = ($cand[$key][$signature] ?? 0) + 1;
        }

        $days = [];
        foreach ($cand as $key => $variants) {
            arsort($variants);
            $json = (string)array_key_first($variants);
            $slot = json_decode($json, true);
            if (!is_array($slot)) {
                continue;
            }
            [$weekday] = array_map('intval', explode('|', $key, 2));
            $days[$weekday][] = $slot;
        }
        foreach ($days as &$slots) {
            usort($slots, static fn(array $a, array $b): int => strcmp((string)$a['start'], (string)$b['start']));
        }
        unset($slots);
        ksort($days);
        return $days;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,array<string,mixed>> $hours
     * @param list<array<string,mixed>> $examRows
     * @return array{0:array<string,list<array<string,mixed>>>,1:list<array<string,mixed>>}
     */
    private function SchulmanagerPlanAbbilden(array $rows, array $hours, array $examRows): array
    {
        $examMap = [];
        foreach ($examRows as $e) {
            if (!is_array($e)) {
                continue;
            }
            $date = trim((string)($e['date'] ?? ''));
            $ch = is_array($e['startClassHour'] ?? null) ? $e['startClassHour'] : [];
            $id = (int)($ch['id'] ?? 0);
            $num = trim((string)($ch['number'] ?? ''));
            $subject = is_array($e['subject'] ?? null)
                ? trim((string)($e['subject']['name'] ?? ($e['subject']['abbreviation'] ?? '')))
                : trim((string)($e['subject'] ?? ''));
            $type = is_array($e['type'] ?? null) ? trim((string)($e['type']['name'] ?? '')) : trim((string)($e['type'] ?? ''));
            $title = $type !== '' ? $type : ($subject !== '' ? $subject : 'Prüfung');
            if ($date !== '') {
                if ($id > 0) {
                    $examMap[$date . '|id:' . $id] = ['title' => $title, 'subject' => $subject];
                }
                if ($num !== '') {
                    $examMap[$date . '|nr:' . $num] = ['title' => $title, 'subject' => $subject];
                }
            }
        }

        $days = [];
        $changes = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $date = trim((string)($r['date'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }
            [$start, $end] = $this->SchulmanagerZeitFuerEintrag($r, $hours);
            if ($start === '' || $end === '') {
                continue;
            }
            $type = trim((string)($r['type'] ?? ''));
            $actual = is_array($r['actualLesson'] ?? null) ? $r['actualLesson'] : null;
            $orig = array_values(array_filter((array)($r['originalLessons'] ?? []), 'is_array'));
            $original = $orig[0] ?? null;
            $event = is_array($r['event'] ?? null) ? $r['event'] : null;

            $status = 'normal';
            $lesson = $actual;
            $subject = '';
            $room = '';
            $teacher = '';
            $insteadOf = '';
            if ($type === 'cancelledLesson') {
                $status = 'entfall';
                $lesson = is_array($original) ? $original : $actual;
            } elseif ($type === 'changedLesson') {
                $status = 'vertretung';
                $lesson = $actual ?? $original;
                if (is_array($original)) {
                    $insteadOf = $this->SchulmanagerTeachers($original['teachers'] ?? []);
                }
            } elseif ($type === 'event') {
                $status = 'termin';
                $subject = trim((string)($event['text'] ?? ($r['comment'] ?? 'Veranstaltung')));
                $subject = $subject !== '' ? $subject : 'Veranstaltung';
                $rooms = array_values(array_filter((array)($event['rooms'] ?? []), 'is_array'));
                $room = $rooms !== [] ? trim((string)($rooms[0]['name'] ?? '')) : '';
                $teacher = $this->SchulmanagerTeachers($event['teachers'] ?? []);
            }

            if ($subject === '' && is_array($lesson)) {
                $subject = $this->SchulmanagerSubject($lesson);
                $room = $this->SchulmanagerRoom($lesson);
                $teacher = $this->SchulmanagerTeachers($lesson['teachers'] ?? []);
            }
            if ($subject === '') {
                $subject = $status === 'entfall' ? 'Entfall' : 'Unterricht';
            }

            $slot = [
                'subject' => $subject,
                'start' => $start,
                'end' => $end,
                'room' => $room,
                'teacher' => $teacher,
                'status' => $status,
                'insteadOf' => $insteadOf,
            ];
            $comment = trim((string)($r['comment'] ?? (is_array($actual) ? ($actual['comment'] ?? '') : '')));
            if ($comment !== '') {
                $slot['notes'] = mb_substr($comment, 0, 500);
            }

            $ch = is_array($r['classHour'] ?? null) ? $r['classHour'] : [];
            $eid = (int)($ch['id'] ?? 0);
            $enum = trim((string)($ch['number'] ?? ''));
            $exam = ($eid > 0 ? ($examMap[$date . '|id:' . $eid] ?? null) : null)
                ?? ($enum !== '' ? ($examMap[$date . '|nr:' . $enum] ?? null) : null);
            if (is_array($exam)) {
                $slot['exam'] = true;
                $slot['examTitle'] = mb_substr((string)($exam['title'] ?? 'Prüfung'), 0, 80);
            }

            $days[$date][] = $slot;
            if (in_array($status, ['vertretung', 'entfall', 'termin'], true)) {
                $changes[] = [
                    'key' => $date . '|' . $start . '|' . $status . '|' . mb_strtolower($subject) . '|' . $room . '|' . $teacher,
                    'date' => $date,
                    'start' => $start,
                    'status' => $status,
                    'subject' => $subject,
                    'room' => $room,
                ];
            }
        }
        foreach ($days as &$slots) {
            usort($slots, static fn(array $a, array $b): int => strcmp((string)$a['start'], (string)$b['start']));
        }
        unset($slots);
        ksort($days);
        return [$days, $changes];
    }

    /** @param list<array<string,mixed>> $rows */
    private function SchulmanagerHausaufgabenNormieren(array $rows): array
    {
        $from = date('Y-m-d', strtotime('-' . self::SM_HOMEWORK_BACK_DAYS . ' days'));
        $to = date('Y-m-d', strtotime('+' . self::SM_HOMEWORK_FORWARD_DAYS . ' days'));
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $due = trim((string)($r['date'] ?? ($r['dueDate'] ?? '')));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) !== 1 || $due < $from || $due > $to) {
                continue;
            }
            $subject = $r['subject'] ?? '';
            if (is_array($subject)) {
                $subject = (string)($subject['name'] ?? ($subject['abbreviation'] ?? ''));
            }
            $subject = trim((string)$subject);
            $note = trim((string)($r['homework'] ?? ($r['text'] ?? '')));
            if ($subject === '') {
                continue;
            }
            $id = (int)($r['id'] ?? 0);
            if ($id <= 0) {
                $id = crc32($due . '|' . mb_strtolower($subject) . '|' . $note) & 0x7fffffff;
                if ($id === 0) {
                    $id = 1;
                }
            }
            $out[] = [
                'srcId' => $id,
                'subject' => $subject,
                'due' => $due,
                'note' => mb_substr($note, 0, 500),
                'done' => ($r['completed'] ?? ($r['done'] ?? false)) === true,
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,array<string,mixed>> $hours
     * @return list<array<string,mixed>>
     */
    private function SchulmanagerPruefungenNormieren(array $rows, array $hours): array
    {
        $raw = [];
        foreach ($rows as $e) {
            if (!is_array($e)) {
                continue;
            }
            $date = trim((string)($e['date'] ?? ''));
            $ch = is_array($e['startClassHour'] ?? null) ? $e['startClassHour'] : [];
            $start = substr(trim((string)($ch['from'] ?? '')), 0, 5);
            $end = substr(trim((string)($ch['until'] ?? '')), 0, 5);
            if (($start === '' || $end === '') && $ch !== []) {
                [$start, $end] = $this->SchulmanagerZeitFuerClassHour($ch, $date, $hours);
            }
            $subject = $e['subject'] ?? '';
            $subject = is_array($subject)
                ? trim((string)($subject['name'] ?? ($subject['abbreviation'] ?? '')))
                : trim((string)$subject);
            $type = $e['type'] ?? '';
            $typeName = is_array($type) ? trim((string)($type['name'] ?? '')) : trim((string)$type);
            if ($date === '' || $start === '' || $end === '' || $subject === '') {
                continue;
            }
            $raw[] = [
                'ids' => [(int)($e['id'] ?? 0)],
                'date' => $date,
                'start' => $start,
                'end' => $end,
                'subject' => $subject,
                'title' => $typeName !== '' ? $typeName : $subject,
                'room' => '',
                'teacher' => '',
                'status' => 'normal',
                'topic' => trim((string)($e['comment'] ?? '')),
                'examType' => $typeName,
            ];
        }
        if (class_exists('UntisPruefungCalc')) {
            return UntisPruefungCalc::Zusammenfassen($raw);
        }
        return $raw;
    }

    /**
     * Neue/veraenderte Vertretungen nur einmal melden. Erster Lauf legt die Basis still.
     * @param list<array<string,mixed>> $changes
     */
    private function SchulmanagerAenderungenMelden(array $kind, array $changes): int
    {
        $store = json_decode((string)@$this->ReadAttributeString('SchulmanagerLast'), true);
        $store = is_array($store) ? $store : [];
        $key = (string)$kind['remote'];
        $newKeys = array_values(array_unique(array_map(static fn(array $x): string => (string)($x['key'] ?? ''), $changes)));
        $old = array_values(array_map('strval', (array)($store[$key] ?? [])));
        $had = array_key_exists($key, $store);
        $fresh = array_values(array_diff($newKeys, $old));
        $store[$key] = $newKeys;
        @$this->WriteAttributeString('SchulmanagerLast', (string)json_encode($store, JSON_UNESCAPED_UNICODE));

        if (!$had || $fresh === [] || !(bool)$this->ReadPropertyBoolean('SchulmanagerPush')) {
            return 0;
        }
        $lines = [];
        foreach ($changes as $c) {
            if (!in_array((string)($c['key'] ?? ''), $fresh, true)) {
                continue;
            }
            $status = (string)($c['status'] ?? '');
            $word = $status === 'entfall' ? 'Entfall' : ($status === 'vertretung' ? 'Vertretung' : 'Termin');
            $lines[] = date('d.m.', (int)strtotime((string)$c['date'] . ' 12:00')) . ' '
                . (string)$c['start'] . ' ' . $word . ': ' . (string)$c['subject']
                . ((string)($c['room'] ?? '') !== '' ? ' · ' . (string)$c['room'] : '');
        }
        if ($lines === []) {
            return 0;
        }
        $more = count($lines) - 4;
        $lines = array_slice($lines, 0, 4);
        if ($more > 0) {
            $lines[] = 'und ' . $more . ' weitere';
        }
        try {
            $userId = trim((string)($kind['userId'] ?? ''));
            $target = $userId !== '' && $this->PushSubscriptions($userId) !== [] ? $userId : '';
            $this->PushBroadcast('Schule ' . (string)$kind['name'], implode("\n", $lines), $target, 'dashboard');
        } catch (\Throwable $e) {
            $this->SendDebug('Schulmanager', 'Push fehlgeschlagen: ' . $e->getMessage(), 0);
            return 0;
        }
        return count($fresh);
    }

    /**
     * Elternbriefe: erster Lauf nur Baseline; danach neue Briefe optional zur KI.
     * @param list<array<string,mixed>> $mapKinder
     */
    private function SchulmanagerBriefeEinpflegen(int $accountNr, array $login, array $mapKinder, bool $trocken): string
    {
        $resp = $this->SchulmanagerApi((string)$login['token'], (string)$login['bundle'], 'letters', 'get-letters', []);
        if (($resp['ok'] ?? false) !== true) {
            return 'Elternbriefe nicht lesbar';
        }
        $letters = array_values(array_filter((array)($resp['data'] ?? []), 'is_array'));
        $seenAll = json_decode((string)@$this->ReadAttributeString('SchulmanagerLettersSeen'), true);
        $seenAll = is_array($seenAll) ? $seenAll : [];
        $accountKey = (string)$accountNr;
        $initialized = array_key_exists($accountKey, $seenAll);
        $seen = array_values(array_map('intval', (array)($seenAll[$accountKey] ?? [])));

        if (!$initialized) {
            if (!$trocken) {
                $seenAll[$accountKey] = array_values(array_unique(array_filter(array_map(
                    static fn(array $l): int => (int)($l['id'] ?? 0), $letters), static fn(int $x): bool => $x > 0)));
                @$this->WriteAttributeString('SchulmanagerLettersSeen', (string)json_encode($seenAll, JSON_UNESCAPED_UNICODE));
            }
            return count($letters) . ' vorhandene Elternbriefe als Ausgangsbestand gemerkt';
        }

        $new = array_values(array_filter($letters,
            static fn(array $l): bool => (int)($l['id'] ?? 0) > 0 && !in_array((int)$l['id'], $seen, true)));
        if ($new === []) {
            return count($letters) . ' Elternbriefe, nichts Neues';
        }
        usort($new, static fn(array $a, array $b): int => strcmp(
            (string)($a['sentDate'] ?? ($a['createdAt'] ?? '')),
            (string)($b['sentDate'] ?? ($b['createdAt'] ?? ''))
        ));

        if ($trocken) {
            return count($letters) . ' Elternbriefe, ' . count($new) . ' neu — Trockenlauf';
        }

        $mapped = [];
        foreach ($mapKinder as $m) {
            $mapped[(int)($m['studentId'] ?? 0)] = (string)($m['userId'] ?? '');
        }
        $processed = 0;
        foreach ($new as $letter) {
            $id = (int)$letter['id'];
            $title = trim((string)($letter['title'] ?? ($letter['subject'] ?? 'Elternbrief')));
            $detail = $this->SchulmanagerBriefDetail(
                (string)$login['token'], (string)$login['bundle'], $id, array_keys($mapped));
            if (($detail['ok'] ?? false) !== true) {
                continue; // spaeter erneut versuchen
            }
            $d = (array)($detail['data'] ?? []);
            $userId = '';
            $hits = [];
            foreach ((array)($d['studentStatuses'] ?? ($letter['studentStatuses'] ?? [])) as $st) {
                if (!is_array($st)) {
                    continue;
                }
                $sid = (int)($st['studentId'] ?? ($st['student']['id'] ?? 0));
                if ($sid > 0 && isset($mapped[$sid]) && $mapped[$sid] !== '') {
                    $hits[$mapped[$sid]] = true;
                }
            }
            if (count($hits) === 1) {
                $userId = (string)array_key_first($hits);
            }

            $text = trim(strip_tags((string)($d['text'] ?? ($d['content'] ?? ''))));
            $attachments = $this->SchulmanagerBriefAnhaenge((array)($d['attachments'] ?? []));
            $aiOk = true;
            if ((bool)$this->ReadPropertyBoolean('SchulmanagerLettersAI') && $text !== '') {
                if (method_exists($this, 'MailDayLimitReached') && $this->MailDayLimitReached()) {
                    break; // nicht als gesehen markieren; morgen erneut
                }
                $kopf = [
                    'Subject' => $title,
                    'SenderAddress' => 'schulmanager-online.de',
                    'SenderName' => 'Schulmanager Online',
                    'Recipient' => '',
                    'Date' => (string)($d['sentDate'] ?? ($letter['sentDate'] ?? '')),
                ];
                try {
                    $aiOk = $this->MailAnalyseRecord(
                        'schulmanager:' . $accountNr . ':' . $id,
                        $kopf,
                        $text,
                        $attachments,
                        $userId,
                        'Schulmanager'
                    );
                } catch (\Throwable $e) {
                    $aiOk = false;
                    $this->SendDebug('Schulmanager', 'Elternbrief-KI: ' . $e->getMessage(), 0);
                }
                /* Pro Durchlauf hoechstens EIN kostenpflichtiger Brief. */
                if (!$aiOk) {
                    break;
                }
            }

            if ((bool)$this->ReadPropertyBoolean('SchulmanagerPush')) {
                try {
                    $target = $userId !== '' && $this->PushSubscriptions($userId) !== [] ? $userId : '';
                    $this->PushBroadcast('Neuer Elternbrief', $title, $target, 'dashboard');
                } catch (\Throwable $e) {
                    $this->SendDebug('Schulmanager', 'Elternbrief-Push: ' . $e->getMessage(), 0);
                }
            }
            $seen[] = $id;
            $processed++;
            if ((bool)$this->ReadPropertyBoolean('SchulmanagerLettersAI')) {
                break;
            }
        }
        $seenAll[$accountKey] = array_values(array_unique($seen));
        @$this->WriteAttributeString('SchulmanagerLettersSeen', (string)json_encode($seenAll, JSON_UNESCAPED_UNICODE));
        return count($letters) . ' Elternbriefe, ' . $processed . ' neu verarbeitet';
    }

    /** @return array{ok:bool,data?:array,message?:string} */
    private function SchulmanagerBriefDetail(string $token, string $bundle, int $letterId, array $studentIds): array
    {
        $include = [
            [
                'association' => 'attachments',
                'required' => false,
                'attributes' => ['id', 'filename', 'file', 'contentType', 'inline', 'letterId'],
            ],
        ];
        $studentIds = array_values(array_filter(array_map('intval', $studentIds), static fn(int $x): bool => $x > 0));
        if ($studentIds !== []) {
            $include[] = [
                'association' => 'studentStatuses',
                'required' => false,
                'where' => ['studentId' => ['$in' => $studentIds]],
                'include' => [['association' => 'student', 'required' => true]],
            ];
        }
        return $this->SchulmanagerApi($token, $bundle, 'letters', 'poqa', [
            'action' => [
                'model' => 'modules/letters/letter',
                'action' => 'findByPk',
                'parameters' => [$letterId, ['include' => $include]],
            ],
            'uiState' => 'main.modules.letters.view.details',
        ]);
    }

    /** @return list<array{kind:string,base64:string,name:string}> */
    private function SchulmanagerBriefAnhaenge(array $attachments): array
    {
        $out = [];
        foreach ($attachments as $a) {
            if (!is_array($a)) {
                continue;
            }
            $file = trim((string)($a['file'] ?? ''));
            if ($file === '') {
                continue;
            }
            /* Manche Antworten liefern data:...; die Mailanalyse erwartet nur base64. */
            if (preg_match('~^data:[^;]+;base64,(.+)$~s', $file, $m) === 1) {
                $file = (string)$m[1];
            }
            $type = mb_strtolower(trim((string)($a['contentType'] ?? '')));
            $kind = str_contains($type, 'pdf') ? 'pdf' : (str_starts_with($type, 'image/') ? 'image' : '');
            if ($kind === '') {
                continue;
            }
            $out[] = [
                'kind' => $kind,
                'base64' => $file,
                'name' => trim((string)($a['filename'] ?? 'Anhang')),
            ];
        }
        return $out;
    }

    /**
     * Login: Schulmanager verlangt neben dem Kennwort den PBKDF2-Hash des Kennworts
     * mit dem zuvor geholten Salt. Das Kennwort bleibt wie bei WebUntis als
     * Symcon-Eigenschaft in der Gateway-Konfiguration und wird nie geloggt.
     *
     * @param array{nr:int,name:string,user:string,password:string} $konto
     * @return array<string,mixed>
     */
    private function SchulmanagerLogin(array $konto): array
    {
        $user = (string)$konto['user'];
        $password = (string)$konto['password'];
        $saltResp = $this->SchulmanagerHttp(self::SM_BASE . '/api/get-salt', [
            'emailOrUsername' => $user,
            'mobileApp' => false,
        ]);
        if (($saltResp['ok'] ?? false) !== true) {
            return ['ok' => false, 'status' => (int)($saltResp['status'] ?? 0), 'message' => 'Salt HTTP ' . (int)($saltResp['status'] ?? 0)];
        }
        $saltData = $saltResp['json'] ?? null;
        $salt = is_string($saltData) ? $saltData : (is_array($saltData) ? (string)($saltData['salt'] ?? '') : '');
        if ($salt === '') {
            return ['ok' => false, 'status' => 0, 'message' => 'Salt fehlt'];
        }
        $binary = hash_pbkdf2('sha512', $password, $salt, 99999, 512, true);
        $hash = bin2hex($binary);
        $loginResp = $this->SchulmanagerHttp(self::SM_BASE . '/api/login', [
            'emailOrUsername' => $user,
            'password' => $password,
            'hash' => $hash,
            'mobileApp' => false,
        ]);
        if (($loginResp['ok'] ?? false) !== true) {
            return ['ok' => false, 'status' => (int)($loginResp['status'] ?? 0), 'message' => 'Login HTTP ' . (int)($loginResp['status'] ?? 0)];
        }
        $data = is_array($loginResp['json'] ?? null) ? $loginResp['json'] : [];
        $token = trim((string)($data['jwt'] ?? ($data['token'] ?? '')));
        if ($token === '') {
            return ['ok' => false, 'status' => 401, 'message' => 'kein Token'];
        }
        $u = is_array($data['user'] ?? null) ? $data['user'] : [];
        $students = [];
        foreach ((array)($u['associatedParents'] ?? []) as $link) {
            if (is_array($link) && is_array($link['student'] ?? null)) {
                $students[] = $link['student'];
            }
        }
        if ($students === [] && is_array($u['associatedStudent'] ?? null)) {
            $students[] = $u['associatedStudent'];
        }
        return [
            'ok' => true,
            'status' => 200,
            'token' => $token,
            'user' => $u,
            'students' => $students,
            'institutionId' => (int)($u['institutionId'] ?? 0),
            'bundle' => $this->SchulmanagerBundleVersion(),
        ];
    }

    /** @return array{ok:bool,status:int,json:mixed,raw:string} */
    private function SchulmanagerHttp(string $url, ?array $payload = null, string $token = ''): array
    {
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json, text/plain, */*',
            'User-Agent: Mozilla/5.0 SymDo-Schulmanager/1.0',
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => self::SM_HTTP_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $text = $raw === false ? '' : (string)$raw;
        $json = $text !== '' ? json_decode($text, true) : null;
        return [
            'ok' => $raw !== false && $status >= 200 && $status < 300,
            'status' => $status,
            'json' => $json,
            'raw' => $text,
        ] + ($err !== '' ? ['error' => $err] : []);
    }

    /** @return array{ok:bool,data?:mixed,message?:string,status?:int} */
    private function SchulmanagerApi(string $token, string $bundle, string $module, string $endpoint, array $parameters = []): array
    {
        $resp = $this->SchulmanagerHttp(self::SM_BASE . '/api/calls', [
            'bundleVersion' => $bundle,
            'requests' => [[
                'moduleName' => $module,
                'endpointName' => $endpoint,
                'parameters' => (object)$parameters,
            ]],
        ], $token);
        if (($resp['ok'] ?? false) !== true) {
            return ['ok' => false, 'status' => (int)($resp['status'] ?? 0), 'message' => 'HTTP ' . (int)($resp['status'] ?? 0)];
        }
        $body = is_array($resp['json'] ?? null) ? $resp['json'] : [];
        $results = $body['results'] ?? ($body['responses'] ?? []);
        if (!is_array($results) || $results === []) {
            return ['ok' => false, 'status' => 0, 'message' => 'keine API-Antwort'];
        }
        $r = $results[0];
        if (!is_array($r)) {
            return ['ok' => false, 'status' => 0, 'message' => 'unbekannte Antwortform'];
        }
        $status = $r['status'] ?? 200;
        if ($status === 'error' || (is_numeric($status) && (int)$status >= 400)) {
            return ['ok' => false, 'status' => is_numeric($status) ? (int)$status : 0,
                'message' => (string)($r['message'] ?? ('API ' . (string)$status))];
        }
        return ['ok' => true, 'status' => is_numeric($status) ? (int)$status : 200, 'data' => $r['data'] ?? $r];
    }

    private function SchulmanagerBundleVersion(): string
    {
        $old = trim((string)@$this->ReadAttributeString('SchulmanagerBundleVersion'));
        $at = (int)@$this->ReadAttributeInteger('SchulmanagerBundleAt');
        if ($old !== '' && $at > 0 && (time() - $at) < 86400) {
            return $old;
        }
        $found = '';
        $root = $this->SchulmanagerHttp(self::SM_BASE, null, '');
        if (($root['ok'] ?? false) === true) {
            preg_match_all('~src="(/[^"]*\.js[^"]*)"~i', (string)($root['raw'] ?? ''), $m);
            foreach (array_slice((array)($m[1] ?? []), 0, 12) as $path) {
                $js = $this->SchulmanagerHttp(self::SM_BASE . (string)$path, null, '');
                if (($js['ok'] ?? false) !== true) {
                    continue;
                }
                if (preg_match('~bundleVersion["\s:]+["\']([a-f0-9]{8,})["\']~i', (string)($js['raw'] ?? ''), $hit) === 1) {
                    $found = (string)$hit[1];
                    break;
                }
            }
        }
        if ($found === '') {
            $found = $old !== '' ? $old : self::SM_BUNDLE_FALLBACK;
        }
        @$this->WriteAttributeString('SchulmanagerBundleVersion', $found);
        @$this->WriteAttributeInteger('SchulmanagerBundleAt', time());
        return $found;
    }

    private function SchulmanagerStudentClassId(array $student): int
    {
        $id = (int)($student['classId'] ?? 0);
        if ($id <= 0 && is_array($student['class'] ?? null)) {
            $id = (int)($student['class']['id'] ?? 0);
        }
        return $id;
    }

    /** @return array<string,array<string,mixed>> */
    private function SchulmanagerClassHoursMap(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $id = (int)($r['id'] ?? 0);
            $nr = trim((string)($r['number'] ?? ''));
            if ($id > 0) {
                $out['id:' . $id] = $r;
            }
            if ($nr !== '') {
                $out['nr:' . $nr] = $r;
            }
        }
        return $out;
    }

    /** @return array{0:string,1:string} */
    private function SchulmanagerZeitFuerEintrag(array $entry, array $hours): array
    {
        $ch = is_array($entry['classHour'] ?? null) ? $entry['classHour'] : [];
        return $this->SchulmanagerZeitFuerClassHour($ch, (string)($entry['date'] ?? ''), $hours);
    }

    /** @return array{0:string,1:string} */
    private function SchulmanagerZeitFuerClassHour(array $ch, string $date, array $hours): array
    {
        $id = (int)($ch['id'] ?? 0);
        $nr = trim((string)($ch['number'] ?? ''));
        $h = ($id > 0 ? ($hours['id:' . $id] ?? null) : null) ?? ($nr !== '' ? ($hours['nr:' . $nr] ?? null) : null);
        $h = is_array($h) ? $h : $ch;
        $from = trim((string)($h['from'] ?? ($h['startTime'] ?? '')));
        $until = trim((string)($h['until'] ?? ($h['endTime'] ?? '')));
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $idx = (int)date('N', (int)strtotime($date . ' 12:00:00')) - 1;
            if (is_array($h['fromByDay'] ?? null) && isset($h['fromByDay'][$idx])) {
                $from = trim((string)$h['fromByDay'][$idx]);
            }
            if (is_array($h['untilByDay'] ?? null) && isset($h['untilByDay'][$idx])) {
                $until = trim((string)$h['untilByDay'][$idx]);
            }
        }
        $from = substr($from, 0, 5);
        $until = substr($until, 0, 5);
        if (preg_match('/^\d{2}:\d{2}$/', $from) !== 1 || preg_match('/^\d{2}:\d{2}$/', $until) !== 1) {
            return ['', ''];
        }
        return [$from, $until];
    }

    private function SchulmanagerSubject(array $lesson): string
    {
        $s = $lesson['subject'] ?? '';
        if (is_array($s)) {
            return trim((string)($s['name'] ?? ($s['abbreviation'] ?? '')));
        }
        return trim((string)$s);
    }

    private function SchulmanagerRoom(array $lesson): string
    {
        $r = $lesson['room'] ?? '';
        if (is_array($r)) {
            return trim((string)($r['name'] ?? ''));
        }
        return trim((string)$r);
    }

    private function SchulmanagerTeachers(mixed $teachers): string
    {
        $out = [];
        foreach (is_array($teachers) ? $teachers : [] as $t) {
            if (!is_array($t)) {
                continue;
            }
            $name = trim((string)($t['abbreviation'] ?? ''));
            if ($name === '') {
                $name = trim((string)($t['lastname'] ?? ''));
            }
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return implode(', ', array_values(array_unique($out)));
    }

    private function SchulmanagerGesperrt(int $account): bool
    {
        $all = json_decode((string)@$this->ReadAttributeString('SchulmanagerFails'), true);
        $all = is_array($all) ? $all : [];
        $x = is_array($all[(string)$account] ?? null) ? $all[(string)$account] : ['count' => 0, 'at' => 0];
        if ((int)($x['count'] ?? 0) < self::SM_FAIL_MAX) {
            return false;
        }
        $at = (int)($x['at'] ?? 0);
        if ($at > 0 && (time() - $at) >= self::SM_FAIL_PAUSE) {
            $this->SchulmanagerFehlerReset($account);
            return false;
        }
        return true;
    }

    private function SchulmanagerFehlerZaehlen(int $account, int $status): void
    {
        /* Nur echte Login-Antworten zaehlen; Netzfehler duerfen kein Konto pausieren. */
        if (!in_array($status, [400, 401, 403], true)) {
            return;
        }
        $all = json_decode((string)@$this->ReadAttributeString('SchulmanagerFails'), true);
        $all = is_array($all) ? $all : [];
        $x = is_array($all[(string)$account] ?? null) ? $all[(string)$account] : ['count' => 0, 'at' => 0];
        $x['count'] = (int)($x['count'] ?? 0) + 1;
        $x['at'] = time();
        $all[(string)$account] = $x;
        @$this->WriteAttributeString('SchulmanagerFails', (string)json_encode($all));
        if ((int)$x['count'] >= self::SM_FAIL_MAX) {
            $this->LogMessage('SymDo Schulmanager: Konto ' . $account
                . ' nach ' . self::SM_FAIL_MAX . ' fehlgeschlagenen Anmeldungen für 6 Stunden pausiert.', KL_ERROR);
        }
    }

    private function SchulmanagerFehlerReset(int $account): void
    {
        $all = json_decode((string)@$this->ReadAttributeString('SchulmanagerFails'), true);
        $all = is_array($all) ? $all : [];
        $all[(string)$account] = ['count' => 0, 'at' => 0];
        @$this->WriteAttributeString('SchulmanagerFails', (string)json_encode($all));
    }

    private function SchulmanagerStatusSchreiben(string $text): void
    {
        @$this->WriteAttributeString('SchulmanagerStatus', (string)json_encode(
            ['t' => time(), 'text' => $text], JSON_UNESCAPED_UNICODE));
        $this->SendDebug('Schulmanager', $text, 0);
    }
}
