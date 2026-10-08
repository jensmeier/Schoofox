<?php

declare(strict_types=1);

/**
 * Schulmanager Online -> SymDo Stundenplan.
 *
 * Eigenständiges IP-Symcon-Modul. Schulmanager wird ausschließlich gelesen.
 * Der Stundenplan wird über die öffentliche SymDo-Funktion STPL_ImportSlots()
 * in eine bestehende SymDo-Stundenplaninstanz geschrieben.
 *
 * Hausaufgaben, Prüfungen und Elternbriefe werden zusätzlich im Modul als
 * Status/JSON bereitgestellt. Dadurch bleibt dieses Modul unabhängig vom
 * internen Aufbau des SymDo-Gateways und kann als eigene Bibliothek installiert
 * und aktualisiert werden.
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
        $this->RegisterPropertyString('Mappings', '[]');

        $this->RegisterPropertyBoolean('ReadHomework', true);
        $this->RegisterPropertyBoolean('ReadExams', true);
        $this->RegisterPropertyBoolean('ReadLetters', true);

        $this->RegisterAttributeString('DetectedStudents', '[]');
        $this->RegisterAttributeString('StatusData', '{}');
        $this->RegisterAttributeString('BundleVersion', self::BUNDLE_FALLBACK);
        $this->RegisterAttributeInteger('BundleVersionAt', 0);
        $this->RegisterAttributeInteger('LoginFails', 0);
        $this->RegisterAttributeInteger('LoginFailAt', 0);

        $this->RegisterVariableString('Status', 'Status');
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', '~UnixTimestamp');
        $this->RegisterVariableString('Overview', 'Schulmanager Übersicht', '~HTMLBox');
        $this->RegisterVariableString('HomeworkJSON', 'Hausaufgaben JSON');
        $this->RegisterVariableString('ExamsJSON', 'Prüfungen JSON');
        $this->RegisterVariableString('LettersJSON', 'Elternbriefe JSON');

        $this->RegisterTimer(
            'UpdateTimer',
            0,
            'IPS_RequestAction($_IPS[\'TARGET\'], \'UpdateNow\', 0);'
        );
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

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
        }

        throw new Exception('Unbekannte Aktion: ' . (string)$Ident);
    }

    public function GetConfigurationForm(): string
    {
        $status = $this->StatusText();
        $students = $this->DetectedStudents();

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
                        'rowCount' => max(3, count($students)),
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
                'caption' => 'Daten',
                'expanded' => true,
                'items' => [
                    ['type' => 'CheckBox', 'name' => 'ReadHomework', 'caption' => 'Hausaufgaben lesen'],
                    ['type' => 'CheckBox', 'name' => 'ReadExams', 'caption' => 'Klassenarbeiten / Prüfungen lesen'],
                    ['type' => 'CheckBox', 'name' => 'ReadLetters', 'caption' => 'Elternbriefe lesen'],
                    [
                        'type' => 'Label',
                        'caption' => 'Stundenplan, Vertretungen, Entfall und Veranstaltungen werden direkt in SymDo importiert. Hausaufgaben, Prüfungen und Elternbriefe werden in dieser eigenständigen Version als Modul-Daten und Übersicht bereitgestellt.'
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
                        'items' => $exams
                    ];
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
                    $letters = is_array($l) ? $l : [];
                } catch (Throwable $e) {
                    $letters = [];
                }
            }

            $this->StoreData($homeworkAll, $examsAll, $letters);

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
            'User-Agent: Mozilla/5.0 IP-Symcon Schulmanager-SymDo/1.0'
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

    private function StoreData(array $homework, array $exams, array $letters): void
    {
        $homeworkJson = json_encode($homework, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $examsJson = json_encode($exams, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lettersJson = json_encode($letters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->SetStringIfChanged('HomeworkJSON', $homeworkJson === false ? '{}' : $homeworkJson);
        $this->SetStringIfChanged('ExamsJSON', $examsJson === false ? '{}' : $examsJson);
        $this->SetStringIfChanged('LettersJSON', $lettersJson === false ? '[]' : $lettersJson);
        $this->SetStringIfChanged('Overview', $this->BuildOverview($homework, $exams, $letters));
    }

    private function BuildOverview(array $homework, array $exams, array $letters): string
    {
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<div style="font-family:Arial,sans-serif;line-height:1.35">';
        $html .= '<h2 style="margin:0 0 12px 0">Schulmanager Online</h2>';

        foreach ($homework as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = $esc((string)($entry['name'] ?? 'Kind'));
            $items = is_array($entry['items'] ?? null) ? $entry['items'] : [];
            $html .= '<h3 style="margin:14px 0 6px 0">Hausaufgaben — ' . $name . '</h3>';
            if ($items === []) {
                $html .= '<div>Keine Daten.</div>';
            } else {
                $items = array_slice(array_reverse($items), 0, 8);
                $html .= '<ul style="margin-top:4px">';
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $date = $esc((string)($item['date'] ?? ''));
                    $subject = $esc((string)($item['subject'] ?? ''));
                    $text = $esc((string)($item['homework'] ?? ''));
                    $html .= '<li><b>' . $date . ' ' . $subject . '</b> — ' . $text . '</li>';
                }
                $html .= '</ul>';
            }
        }

        foreach ($exams as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = $esc((string)($entry['name'] ?? 'Kind'));
            $items = is_array($entry['items'] ?? null) ? $entry['items'] : [];
            $html .= '<h3 style="margin:14px 0 6px 0">Prüfungen — ' . $name . '</h3>';
            if ($items === []) {
                $html .= '<div>Keine anstehenden Prüfungen im Abrufzeitraum.</div>';
            } else {
                $html .= '<ul style="margin-top:4px">';
                foreach (array_slice($items, 0, 8) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $date = $esc((string)($item['date'] ?? ''));
                    $subject = $esc((string)($item['subject']['name'] ?? ''));
                    $type = $esc((string)($item['type']['name'] ?? 'Prüfung'));
                    $html .= '<li><b>' . $date . ' ' . $subject . '</b> — ' . $type . '</li>';
                }
                $html .= '</ul>';
            }
        }

        $html .= '<h3 style="margin:14px 0 6px 0">Elternbriefe</h3>';
        if ($letters === []) {
            $html .= '<div>Keine Daten.</div>';
        } else {
            $html .= '<ul style="margin-top:4px">';
            foreach (array_slice($letters, 0, 10) as $letter) {
                if (!is_array($letter)) {
                    continue;
                }
                $dateRaw = (string)($letter['sentDate'] ?? ($letter['createdAt'] ?? ''));
                $date = $dateRaw !== '' ? date('d.m.Y H:i', strtotime($dateRaw)) : '';
                $title = $esc((string)($letter['title'] ?? 'Elternbrief'));
                $html .= '<li><b>' . $esc($date) . '</b> — ' . $title . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '</div>';
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
