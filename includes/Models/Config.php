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

function svb_get_upload_config_paths() {
    $uploads = wp_upload_dir();

    if (!empty($uploads['error']) || empty($uploads['basedir'])) {
        return [
            'error' => !empty($uploads['error']) ? $uploads['error'] : 'Upload directory unavailable',
        ];
    }

    $dir = trailingslashit($uploads['basedir']) . 'svb/';

    return [
        'dir'  => $dir,
        'file' => $dir . 'svb_config.json',
    ];
}

function svb_get_config_file_for_read() {
    $uploadPaths = svb_get_upload_config_paths();
    if (!isset($uploadPaths['error']) && isset($uploadPaths['file']) && file_exists($uploadPaths['file'])) {
        return $uploadPaths['file'];
    }

    $pluginFallback = SVB_PLUGIN_DIR . 'svb_config.json';
    if (file_exists($pluginFallback)) {
        return $pluginFallback;
    }

    return null;
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
                'child1'  => [ ['start'=>'00:54:20', 'end'=>'00:58:26', 'label'=>'Сцена 1'], ['start'=>'04:18:11', 'end'=>'04:21:22', 'label'=>'Сцена 2'] ],
                'child2'  => [ ['start'=>'02:17:14', 'end'=>'02:21:25', 'label'=>'Сцена 1'], ['start'=>'07:04:23', 'end'=>'07:11:13', 'label'=>'Сцена 2'] ],
                'parent1' => [ ['start'=>'06:35:03', 'end'=>'06:43:13', 'label'=>'Сцена 1'] ],
                'parent2' => [ ['start'=>'06:35:03', 'end'=>'06:43:13', 'label'=>'Сцена 1'] ],
            ],
            'audio_timings' => [
                'name'    => [ ['00:34:07','00:35:17'], ['01:42:17','01:43:27'], ['03:29:13','03:30:25'], ['05:50:20','05:51:30'] ],
                'age'     => [ ['03:36:16','03:38:22'] ],
                'hobby'   => [ ['02:32:00','02:36:27'] ],
                'praise'  => [ ['05:52:02','05:57:14'] ],
                'request' => [ ['06:14:21','06:22:23'] ],
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
                'name'    => [ ['00:16:03','00:17:17'], ['00:49:20','00:50:30'],['03:36:06','03:37:07'], ['04:01:05','04:02:15'], ['06:52:20','06:53:30'] ],
                'age'     => [ ['01:27:30','01:29:30'] ],
                'hobby'   => [ ['01:41:21','01:45:26'] ],
                'praise'  => [ ['04:03:09','04:09:20'] ],
                'request' => [ ['04:19:11','04:27:23'] ],
            ]
        ],
        'video3' => [
             'label' => '3.Казкові Санчата',
             'url'   => SVB_PLUGIN_URL . 'assets/template3.mp4',
             'file'  => SVB_PLUGIN_DIR . 'assets/template3.mp4',
             'image' => SVB_PLUGIN_URL . 'assets/poster3.png',
             'for_children' => [1],
             'scenes' => [
                 'child1'  => [ ['start'=>'01:34:05', 'end'=>'01:43:04', 'label'=>'Сцена 1'] ],
                 'child2'  => [ ['start'=>'05:05:11', 'end'=>'05:16:03', 'label'=>'Сцена 1'] ],
                 'parent1' => [ ['start'=>'03:21:28', 'end'=>'04:26:17', 'label'=>'Сцена 1'] ],
                 'parent2' => [ ['start'=>'03:21:28', 'end'=>'04:26:17', 'label'=>'Сцена 1'] ],
             ],
             'audio_timings' => [
                'name'    => [ ['00:52:20','00:53:30'], ['01:44:50','01:45:60'], ['04:56:20','04:57:30'], ['05:49:15','05:50:25'], ],
                'age'     => [ ['01:32:20','01:34:19'] ],
                'hobby'   => [ ['01:55:06','01:59:23'] ],
                'praise'  => [ ['04:58:10','05:04:15'] ],
                'request' => [ ['05:04:25','05:12:10'] ],
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
                    ['start'=>'06:59:03', 'end'=>'07:06:02', 'label'=>'Кінець']
                ],
                'child2'  => [
                    ['start'=>'03:02:03', 'end'=>'03:12:27', 'label'=>'Сцена 1'],
                    ['start'=>'09:04:13', 'end'=>'09:10:24', 'label'=>'Сцена 2']
                ],
                'parent1' => [ ['start'=>'04:08:29', 'end'=>'04:13:19', 'label'=>'Сцена 1'] ],
                'parent2' => [ ['start'=>'04:11:06', 'end'=>'04:15:26', 'label'=>'Сцена 1'] ],
            ],
            'audio_timings' => [
                'name'    => [ ['00:55:07','00:56:17'], ['01:37:03','01:38:013'], ['06:15:26','06:16:36'], ['08:23:27','08:24:47'] ],
                'age'     => [ ['06:17:29','06:20:27'] ],
                'hobby'   => [ ['03:22:18','03:26:22'] ],
                'praise'  => [ ['06:21:14','06:27:10'] ],
                'request' => [ ['06:27:25','06:33:31'] ],
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
                    ['start'=>'00:55:21', 'end'=>'01:00:01', 'label'=>'Сцена 1'],
                    ['start'=>'06:53:16', 'end'=>'07:00:06', 'label'=>'Сцена 2']
                ],
                'child2'  => [
                    ['start'=>'02:18:20', 'end'=>'02:23:01', 'label'=>'Сцена 1'],
                    ['start'=>'04:13:02', 'end'=>'04:16:12', 'label'=>'Сцена 2']
                ],
                // В файле было только 06:27:18, добавил +10 сек для конца
                'parent1' => [ ['start'=>'06:24:24', 'end'=>'06:33:04', 'label'=>'Сцена 1'] ],
                'parent2' => [ ['start'=>'06:24:24', 'end'=>'06:33:04', 'label'=>'Сцена 1'] ],
            ],
            'audio_timings' => [
                // Добавляем +60 сек к старту, как было в оригинале для запаса
                'name'    => [ ['00:33:28','00:36:21'], ['01:42:27','01:45:20'], ['03:30:19','03:33:19'], ['05:45:10','05:48:03'] ],
                'hobby'   => [ ['02:33:05','02:38:01'] ],
                'praise'  => [ ['05:48:25','05:54:07'] ],
                'request' => [ ['06:07:08','06:15:10'] ],
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
                    ['start'=>'01:30:21', 'end'=>'01:40:03', 'label'=>'Книга'],
                    ['start'=>'04:31:12', 'end'=>'04:37:03', 'label'=>'Сцена 2']
                ],
                'child2'  => [ ['start'=>'04:45:22', 'end'=>'04:52:21', 'label'=>'Сцена 1'] ],
                'parent1' => [ ['start'=>'02:43:12', 'end'=>'02:50:20', 'label'=>'Сцена 1'] ],
                'parent2' => [ ['start'=>'02:42:20', 'end'=>'02:49:27', 'label'=>'Сцена 1'] ],
            ],
            'audio_timings' => [
                'name'    => [ ['00:15:18','00:18:06'], ['00:48:15','00:51:09'], ['04:00:25','04:03:18'], ['06:52:22','06:55:15'] ],
                'hobby'   => [ ['01:42:13','01:46:18'] ],
                'praise'  => [ ['04:04:02','04:10:12'] ],
                'request' => [ ['04:20:03','04:28:15'] ],
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
                 'child1'  => [ ['start'=>'01:35:24', 'end'=>'01:44:23', 'label'=>'Сцена 1'] ],
                 'child2'  => [ ['start'=>'05:13:16', 'end'=>'05:20:12', 'label'=>'Сцена 1'] ],
                 'parent1' => [ ['start'=>'03:26:29', 'end'=>'03:31:18', 'label'=>'Сцена 1'] ],
                 'parent2' => [ ['start'=>'03:26:29', 'end'=>'03:31:18', 'label'=>'Сцена 1'] ],
             ],
             'audio_timings' => [
                'name'    => [ ['00:52:07','00:55:00'], ['01:46:20','01:49:13'], ['04:34:79','04:35:79'], ['05:00:00','05:02:23'], ['05:55:10','05:58:03'] ],
                'hobby'   => [ ['01:58:28','02:05:05'] ],
                'praise'  => [ ['05:03:12','05:09:17'] ],
                'request' => [ ['05:09:18','05:17:15'] ],
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
                    ['start'=>'06:59:23', 'end'=>'07:05:18', 'label'=>'Кінець']
                ],
                'child2'  => [
                    ['start'=>'03:05:19', 'end'=>'03:16:13', 'label'=>'Сцена 1'],
                    ['start'=>'09:04:19', 'end'=>'09:11:00', 'label'=>'Сцена 2']
                ],
                'parent1' => [ ['start'=>'04:12:15', 'end'=>'04:17:05', 'label'=>'Сцена 1'] ],
                'parent2' => [ ['start'=>'04:12:15', 'end'=>'04:17:05', 'label'=>'Сцена 1'] ],
            ], 
            'audio_timings' => [
                'name'    => [ ['00:55:08','00:58:01'], ['06:18:28','06:21:21'], ['08:23:05','08:25:28'] ],
                'hobby'   => [ ['03:25:29','03:30:04'] ],
                'praise'  => [ ['06:23:07','06:29:12'] ],
                'request' => [ ['06:30:03','06:36:08'] ],
                'age'     => [],
            ]
        ],
    ];

    // Если существует файл svb_config.json, накладываем изменения на default
    $config_path = svb_get_config_file_for_read();
    if ($config_path && file_exists($config_path)) {
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

    foreach (['age','hobby','praise','request'] as $cat) {
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
