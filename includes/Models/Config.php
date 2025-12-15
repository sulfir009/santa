<?php

function svb_ts_to_seconds($ts) {
    $ts = trim((string)$ts);
    if ($ts === '') return 0.0;

    // Якщо це просто число (наприклад "123.45")
    if (is_numeric($ts) && strpos($ts, ':') === false) {
        return (float)$ts;
    }

    $ts_clean = str_replace(',', '.', $ts);
    $parts = explode(':', $ts_clean);
    $count = count($parts);

    // Формат HH:MM:SS.ms або MM:SS.ms або MM:SS:ms
    if ($count === 3) {
        // Варіант 1: HH:MM:SS.mmm (стандарт)
        if (strpos($parts[2], '.') !== false) {
            $h = (int)$parts[0];
            $m = (int)$parts[1];
            $s = (float)$parts[2];
            return ($h * 3600) + ($m * 60) + $s;
        }
        // Варіант 2: MM:SS:ms (де ms це кадри або мілісекунди після двокрапки)
        else {
            $m  = (int)$parts[0];
            $s  = (int)$parts[1];
            $frac = $parts[2];

            // Якщо 00:00:500 -> це 500мс -> 0.5с
            // Якщо 00:00:24  -> це 24 кадри? Ні, в вашому JS це соті/тисячні.
            // Що уникнути плутанини, вважаємо це завжди часткою 1000, якщо 3 цифри

            $val = (float)$frac;
            if (strlen($frac) === 3) {
                return ($m * 60) + $s + ($val / 1000);
            }
            // Якщо 2 цифри (старий формат), то це соті
            if (strlen($frac) === 2) {
                return ($m * 60) + $s + ($val / 100);
            }
            // Фолбек
            return ($m * 60) + $s + ($val / 1000);
        }
    }
    // Формат MM:SS (або MM:SS.ms, якщо в SS є крапка)
    elseif ($count === 2) {
        $m = (int)$parts[0];
        $s = (float)$parts[1]; // тут float підхопить .ms
        return ($m * 60) + $s;
    }

    return (float)$ts_clean;
}

function svb_get_definitions() {
    $defaults = [
        // ==============================================
        // ВІДЕО ДЛЯ 1 ДИТИНИ (Video 1-4)
        // ==============================================
        'video1' => [
            'label' => '1.Фабрика Іграшок',
            'url'   => SVB_PLUGIN_URL . 'assets/template1.mp4',
            'file'  => SVB_PLUGIN_DIR . 'assets/template1.mp4',
            'image' => SVB_PLUGIN_URL . 'assets/poster1.png',
            'for_children' => [1],
            'scenes' => [
                'child1'  => [ ['start'=>'00:54:667', 'end'=>'00:58:866', 'label'=>'Сцена 1'], ['start'=>'04:18:367', 'end'=>'04:21:733', 'label'=>'Сцена 2'] ],
                'child2'  => [ ['start'=>'02:17:140', 'end'=>'02:21:860', 'label'=>'Сцена 1'], ['start'=>'07:04:767', 'end'=>'07:11:466', 'label'=>'Сцена 2'] ],
                'parent1' => [ ['start'=>'06:35:100', 'end'=>'06:43:466', 'label'=>'Сцена 1'] ],
                'parent2' => [ ['start'=>'06:35:100', 'end'=>'06:43:466', 'label'=>'Сцена 1'] ],
            ],
            'audio_timings' => [
                'name'    => [ ['00:34:15','00:35:15'], ['01:42:18','01:43:18'], ['03:29:15','03:30:15'], ['05:50:19','05:51:19'] ],
                'age'     => [ ['03:37:16','03:38:16'] ],
                'facts'   => [ ['02:25:16','02:28:27'] ],
                'hobby'   => [ ['02:32:00','02:36:27'] ],
                'praise'  => [ ['05:54:10','05:57:15'] ],
                'request' => [ ['06:19:04','06:22:27'] ],
            ]
        ],
        'video2' => [
            'label' => '2.Чарівна Кухня',
            'url'   => SVB_PLUGIN_URL . 'assets/template2.mp4',
            'file'  => SVB_PLUGIN_DIR . 'assets/template2.mp4',
            'image' => SVB_PLUGIN_URL . 'assets/poster2.png',
            'for_children' => [1],
            'scenes' => [
                'child1'  => [ ['start'=>'01:29:29', 'end'=>'01:39:11', 'label'=>'Книга'], ['start'=>'04:45:00', 'end'=>'04:51:29', 'label'=>'Танці'] ],
                'child2'  => [ ['start'=>'04:30:20', 'end'=>'04:37:03', 'label'=>'Сцена 1'] ],
                'parent1' => [ ['start'=>'02:42:20', 'end'=>'02:49:27', 'label'=>'Сцена 1'] ],
                'parent2' => [ ['start'=>'02:42:20', 'end'=>'02:49:27', 'label'=>'Сцена 1'] ],
            ],
             'audio_timings' => [
                'name'    => [ ['00:15:29','00:16:29'], ['00:49:17','00:50:17'],['03:36:06','03:37:07'], ['04:02:03','04:03:03'], ['06:52:23','06:53:23'] ],
                'age'     => [ ['01:28:22','01:29:22'] ],
                'facts'   => [ ['01:37:28','01:38:28'] ],
                'hobby'   => [ ['01:41:22','01:42:22'] ],
                'praise'  => [ ['04:05:27','04:06:27'] ],
                'request' => [ ['04:23:17','04:24:17'] ],
            ]
        ],
        'video3' => [
             'label' => '3.Казкові Санчата',
             'url'   => SVB_PLUGIN_URL . 'assets/template3.mp4',
             'file'  => SVB_PLUGIN_DIR . 'assets/template3.mp4',
             'image' => SVB_PLUGIN_URL . 'assets/poster3.png',
             'for_children' => [1],
             'scenes' => [
                 'child1'  => [ ['start'=>'01:33:20', 'end'=>'01:43:04', 'label'=>'Сцена 1'] ],
                 'child2'  => [ ['start'=>'05:07:18', 'end'=>'05:12:15', 'label'=>'Сцена 1'] ],
                 'parent1' => [ ['start'=>'03:21:28', 'end'=>'04:26:17', 'label'=>'Сцена 1'] ],
                 'parent2' => [ ['start'=>'03:21:28', 'end'=>'04:26:17', 'label'=>'Сцена 1'] ],
             ],
             'audio_timings' => [
                'name'    => [ ['00:53:00','00:54:00'], ['01:44:28','01:45:28'], ['04:32:16','04:33:16'], ['04:57:04','04:58:04'], ['05:45:27','05:46:27'] ],
                'age'     => [ ['01:33:20','01:34:20'] ],
                'facts'   => [ ['01:51:13','01:52:13'] ],
                'hobby'   => [ ['01:55:01','01:56:01'] ],
                'praise'  => [ ['08:52:05','08:53:05'] ],
                'request' => [ ['05:05:23','05:06:23'] ],
             ]
        ],
        'video4' => [
            'label' => '4.Містечко Чудес',
            'url'   => SVB_PLUGIN_URL . 'assets/template4.mp4',
            'file'  => SVB_PLUGIN_DIR . 'assets/template4.mp4',
            'image' => SVB_PLUGIN_URL . 'assets/poster4.png',
            'for_children' => [1],
            'scenes' => [
                'child1'  => [
                    ['start'=>'00:27:09', 'end'=>'00:29:04', 'label'=>'Початок'],
                    ['start'=>'07:01:10', 'end'=>'07:08:09', 'label'=>'Кінець']
                ],
                'child2'  => [
                    ['start'=>'03:02:03', 'end'=>'03:12:27', 'label'=>'Сцена 1'],
                    ['start'=>'09:07:20', 'end'=>'09:13:02', 'label'=>'Сцена 2']
                ],
                'parent1' => [ ['start'=>'04:11:06', 'end'=>'04:15:26', 'label'=>'Сцена 1'] ],
                'parent2' => [ ['start'=>'04:11:06', 'end'=>'04:15:26', 'label'=>'Сцена 1'] ],
            ],
            'audio_timings' => [
                'name'    => [ ['00:55:04','00:56:04'], ['01:36:27','01:37:27'], ['04:47:29','04:48:29'], ['06:17:25','06:18:25'], ['08:25:26','08:26:26'] ],
                'age'     => [ ['06:21:02','06:22:02'] ],
                'facts'   => [ ['03:18:05','03:19:05'] ],
                'hobby'   => [ ['03:25:04','03:26:04'] ],
                'praise'  => [ ['07:26:00','07:27:00'] ],
                'request' => [ ['06:34:28','06:35:28'] ],
            ]
        ],

        // ==============================================
        // ВІДЕО ДЛЯ ГРУП (2-3 ДІТЕЙ) (Video 5-8)
        // Тайминги взяты из файлов Santa_X_Group.txt
        // ==============================================


        'video5' => [
            'label' => '1.Фабрика Іграшок',
            'url'   => SVB_PLUGIN_URL . 'assets/template1_multi.mp4',
            'file'  => SVB_PLUGIN_DIR . 'assets/template1_multi.mp4',
            'image' => SVB_PLUGIN_URL . 'assets/poster1.png',
            'for_children' => [2, 3, 4],
            'scenes' => [
                'child1'  => [
                    ['start'=>'00:54:20', 'end'=>'00:58:25', 'label'=>'Сцена 1'],
                    ['start'=>'06:57:08', 'end'=>'07:03:28', 'label'=>'Сцена 2']
                ],
                'child2'  => [
                    ['start'=>'02:17:14', 'end'=>'02:21:25', 'label'=>'Сцена 1'],
                    ['start'=>'04:14:05', 'end'=>'04:17:15', 'label'=>'Сцена 2']
                ],
                // В файле было только 06:27:18, добавил +10 сек для конца
                'parent1' => [ ['start'=>'06:27:18', 'end'=>'06:37:18', 'label'=>'Сцена 1'] ],
                'parent2' => [ ['start'=>'06:27:18', 'end'=>'06:37:18', 'label'=>'Сцена 1'] ],
            ],
            'audio_timings' => [
                // Добавляем +60 сек к старту, как было в оригинале для запаса
                'name'    => [ ['00:33:17','00:34:17'], ['01:41:13','01:42:13'], ['03:32:22','03:33:22'], ['05:46:01','05:47:01'] ],
                'facts'   => [ ['02:25:16','02:26:16'] ],
                'hobby'   => [ ['02:32:00','02:33:00'] ],
                'praise'  => [ ['05:51:14','05:52:14'] ],
                'request' => [ ['06:11:19','06:12:19'] ],
                'age'     => [], // В файле "Вік:" пусто
            ]
        ],

        'video6' => [
            'label' => '2.Чарівна Кухня',
            'url'   => SVB_PLUGIN_URL . 'assets/template2_multi.mp4',
            'file'  => SVB_PLUGIN_DIR . 'assets/template2_multi.mp4',
            'image' => SVB_PLUGIN_URL . 'assets/poster2.png',
            'for_children' => [2, 3, 4],
            'scenes' => [
                'child1'  => [
                    ['start'=>'01:29:29', 'end'=>'01:39:11', 'label'=>'Книга'],
                    ['start'=>'04:30:20', 'end'=>'04:37:03', 'label'=>'Сцена 2']
                ],
                'child2'  => [ ['start'=>'04:45:00', 'end'=>'04:51:29', 'label'=>'Сцена 1'] ],
                'parent1' => [ ['start'=>'02:42:20', 'end'=>'02:49:27', 'label'=>'Сцена 1'] ],
                'parent2' => [ ['start'=>'02:42:20', 'end'=>'02:49:27', 'label'=>'Сцена 1'] ],
            ],
            'audio_timings' => [
                'name'    => [ ['00:15:01','00:16:01'], ['00:48:06','00:49:06'], ['04:00:18','04:01:18'], ['06:51:24','06:52:24'] ],
                'facts'   => [ ['01:37:26','01:38:26'] ],
                'hobby'   => [ ['01:41:22','01:42:22'] ],
                'praise'  => [ ['04:05:27','04:06:27'] ],
                'request' => [ ['04:23:17','04:24:17'] ],
                'age'     => [],
            ]
        ],

        'video7' => [
             'label' => '3.Казкові Санчата',
             'url'   => SVB_PLUGIN_URL . 'assets/template3_multi.mp4',
             'file'  => SVB_PLUGIN_DIR . 'assets/template3_multi.mp4',
             'image' => SVB_PLUGIN_URL . 'assets/poster3.png',
             'for_children' => [2, 3, 4],
             'scenes' => [
                 'child1'  => [ ['start'=>'01:33:20', 'end'=>'01:43:04', 'label'=>'Сцена 1'] ],
                 'child2'  => [ ['start'=>'05:13:16', 'end'=>'05:20:12', 'label'=>'Сцена 1'] ],
                 'parent1' => [ ['start'=>'03:23:21', 'end'=>'03:28:10', 'label'=>'Сцена 1'] ],
                 'parent2' => [ ['start'=>'03:23:21', 'end'=>'03:28:10', 'label'=>'Сцена 1'] ],
             ],
             'audio_timings' => [
                'name'    => [ ['00:52:19','00:53:19'], ['01:44:13','01:45:13'], ['04:34:09','04:35:09'], ['05:01:08','05:02:08'], ['05:53:24','05:54:24'] ],
                'facts'   => [ ['01:53:06','01:54:06'] ],
                'hobby'   => [ ['01:56:24','01:57:24'] ],
                'praise'  => [ ['05:07:17','05:08:17'] ],
                'request' => [ ['05:12:01','05:13:01'] ],
                'age'     => [],
             ]
        ],

        'video8' => [
            'label' => '4.Містечко Чудес',
            'url'   => SVB_PLUGIN_URL . 'assets/template4_multi.mp4',
            'file'  => SVB_PLUGIN_DIR . 'assets/template4_multi.mp4',
            'image' => SVB_PLUGIN_URL . 'assets/poster4.png',
            'for_children' => [2, 3, 4],
            'scenes' => [
                'child1'  => [
                    ['start'=>'00:27:09', 'end'=>'00:29:05', 'label'=>'Початок'],
                    ['start'=>'06:54:01', 'end'=>'07:01:01', 'label'=>'Кінець']
                ],
                'child2'  => [
                    ['start'=>'03:02:03', 'end'=>'03:12:28', 'label'=>'Сцена 1'],
                    ['start'=>'08:59:28', 'end'=>'09:06:11', 'label'=>'Сцена 2']
                ],
                'parent1' => [ ['start'=>'04:11:06', 'end'=>'04:15:27', 'label'=>'Сцена 1'] ],
                'parent2' => [ ['start'=>'04:11:06', 'end'=>'04:15:27', 'label'=>'Сцена 1'] ],
            ],
            'audio_timings' => [
                'name'    => [ ['00:54:19','00:55:19'], ['01:38:19','01:39:19'], ['04:43:16','04:44:16'], ['06:12:18','06:13:18'], ['08:24:10','08:25:10'] ],
                'facts'   => [ ['03:18:05','03:19:05'] ],
                'hobby'   => [ ['03:25:04','03:26:04'] ],
                'praise'  => [ ['07:02:18','07:03:18'] ],
                'request' => [ ['06:27:00','06:28:00'] ],
                'age'     => [],
            ]
        ],
    ];

    // Если существует файл svb_config.json, накладываем изменения на default
    $config_path = SVB_PLUGIN_DIR . 'svb_config.json';
    if (file_exists($config_path)) {
        $json = file_get_contents($config_path);
        $saved = json_decode($json, true);
        if (is_array($saved)) {
            foreach ($saved as $vid => $cfg) {
                if (isset($defaults[$vid]['scenes']) && isset($cfg['scenes'])) {
                    $defaults[$vid]['scenes'] = $cfg['scenes'];
                }
            }
        }
    }

    return $defaults;
}

function svb_scan_audio_catalog() {
    $out = ['name' => [ 'boy'=>[], 'girl'=>[], 'root'=>[] ]];

// Замени блок $load_list = function($dir, $url) { ... }; на этот:

    $load_list = function($dir, $url) {
        $items = []; $index = [];
        if (file_exists($dir.'index.json')) {
            $raw = @file_get_contents($dir.'index.json');
            $j = json_decode($raw, true);
            if (is_array($j)) $index = $j;
        }
        $files = glob($dir . '*.{mp3,MP3,wav,WAV,m4a,M4A,ogg,OGG}', GLOB_BRACE) ?: [];
        foreach ($files as $f) {
            $file  = basename($f);
            $label = null;
            $type  = 'single'; // Значение по умолчанию

            if ($index) {
                foreach ($index as $row) {
                    if (!empty($row['file']) && $row['file'] === $file && isset($row['label'])) {
                        $label = $row['label'];
                        // --- НОВЕ: Зчитуємо тип (group/single) ---
                        if (isset($row['type'])) {
                            $type = $row['type'];
                        }
                        break;
                    }
                }
            }

            if ($label === null) {
                // Если файла нет в index.json, используем имя файла как название
                $label = pathinfo($file, PATHINFO_FILENAME);
            }

            // Додаємо type у масив, що йде на фронт
            $items[] = ['file' => $file, 'url' => $url.$file, 'label' => $label, 'type' => $type];
        }
        return $items;
    };

    $out['name']['boy']  = is_dir(SVB_PLUGIN_DIR.'audio/name/boy/')  ? $load_list(SVB_PLUGIN_DIR.'audio/name/boy/',  SVB_PLUGIN_URL.'audio/name/boy/')  : [];
    $out['name']['girl'] = is_dir(SVB_PLUGIN_DIR.'audio/name/girl/') ? $load_list(SVB_PLUGIN_DIR.'audio/name/girl/', SVB_PLUGIN_URL.'audio/name/girl/') : [];
    $out['name']['root'] = [];

    foreach (['age','facts','hobby','praise','request'] as $cat) {
        $dir = SVB_PLUGIN_DIR."audio/{$cat}/";
        $url = SVB_PLUGIN_URL."audio/{$cat}/";
        $out[$cat] = is_dir($dir) ? $load_list($dir,$url) : [];
    }

    $aliases = ['boy'=>[], 'girl'=>[], 'root'=>[]];
    $aliasFile = SVB_PLUGIN_DIR.'audio/name/aliases.json';
    if (file_exists($aliasFile)) {
        $j = json_decode(@file_get_contents($aliasFile), true);
        if (is_array($j)) {
            $aliases['boy']  = $j['boy']  ?? [];
            $aliases['girl'] = $j['girl'] ?? [];
            $aliases['root'] = $j['root'] ?? [];
        }
    }
    $out['_name_aliases'] = $aliases;

    $ageBucketsFile = SVB_PLUGIN_DIR.'audio/age/buckets.json';
    $out['_age_buckets'] = [];
    if (file_exists($ageBucketsFile)) {
        $j = json_decode(@file_get_contents($ageBucketsFile), true);
        if (is_array($j)) $out['_age_buckets'] = $j;
    }
    // Сортуємо файли віку числово (Пункт 2: сортувати випадаючий список)
    if (!empty($out['age'])) {
        usort($out['age'], function($a, $b) {
            return (int)$a['label'] <=> (int)$b['label'];
        });
    }
    return $out;
}
