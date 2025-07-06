<?php
declare(strict_types=1);

// ======== КОНФИГУРАЦИЯ ========
$DEFAULT_COMMUNITY = 'cricket';
$DEFAULT_SNMP_VERSION = '2c';
$CACHE_DIR = __DIR__ . '/cache';
$CACHE_TTL = 300; // 5 минут в секундах
$SNMP_TIMEOUT = 1; // Таймаут в секундах
$SNMP_RETRIES = 1; // Количество повторов

// ======== ОСНОВНОЙ КОД ========
try {
    // Получение и валидация параметров
    $raw_ip = $_GET['ip'] ?? '';
    error_log("Получен IP: [$raw_ip]");
    $ip = validate_ip($raw_ip ?: '127.0.0.1');
    $community = $_GET['community'] ?? $DEFAULT_COMMUNITY;
    $snmp_version = $_GET['version'] ?? $DEFAULT_SNMP_VERSION;
    
    // Проверка кэша
    $cacheFile = "$CACHE_DIR/$ip.json";
    $data = [];
    $source = 'live';
    $cacheData = null;

    if (file_exists($cacheFile)) {
        $raw = file_get_contents($cacheFile);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $cacheData = $decoded;
            $data = $cacheData;
            $source = 'cache';
        }
    }

    if (!$cacheData) {
        $data = fetch_snmp_data($ip, $community, $snmp_version);
        save_cache($cacheFile, $data);
        $source = 'live';
    }
    
    // Вывод данных
    render_html($data, $ip, $source, $community);
    
    } catch (Throwable $e) {
        handle_error($e);
    }

// ======== ФУНКЦИИ ========

/** Валидация IP-адреса */
function validate_ip(string $ip): string {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        throw new InvalidArgumentException("Неверный IP-адрес: $ip");
    }
    return $ip;
}

/** Выполнение SNMP GET запроса */
function snmp_get(string $host, string $community, string $version, string $oid): string {
    $cmd = sprintf(
        'snmpget -v%s -c%s -r%d -t%d -Oqv %s %s 2>&1',
        escapeshellarg($version),
        escapeshellarg($community),
        $GLOBALS['SNMP_RETRIES'],
        $GLOBALS['SNMP_TIMEOUT'],
        escapeshellarg($host),
        escapeshellarg($oid)
    );
    
    $result = trim(shell_exec($cmd));
    if ($result === '' || str_contains($result, 'Timeout') || str_contains($result, 'No Response')) {
        return '—';
    }
    return htmlspecialchars($result);
}

/** Выполнение SNMP WALK запроса */
function snmp_walk(string $host, string $community, string $version, string $oid): array {
    $cmd = sprintf(
        'snmpwalk -v%s -c%s -Oqv %s %s 2>&1',
        escapeshellarg($version),
        escapeshellarg($community),
        escapeshellarg($host),
        escapeshellarg($oid)
    );
    
    $result = trim(shell_exec($cmd));
    if ($result === '' || str_contains($result, 'Timeout') || str_contains($result, 'No Response')) {
        return '—';
    }
    return htmlspecialchars($result);
}

/** Загрузка данных с устройства */
function fetch_snmp_data(string $ip, string $community, string $version): array {
    $host = $ip;
    
    // Системная информация
    $info = [
        'sysDescr'    => snmp_get($host, $community, $version, '.1.3.6.1.2.1.1.1.0'),
        'sysUpTime'   => snmp_get($host, $community, $version, '.1.3.6.1.2.1.1.3.0'),
        'sysName'     => snmp_get($host, $community, $version, '.1.3.6.1.2.1.1.5.0'),
        'sysLocation' => snmp_get($host, $community, $version, '.1.3.6.1.2.1.1.6.0'),
        'sysObjectID' => snmp_get($host, $community, $version, '.1.3.6.1.2.1.1.2.0'),
    ];
    // Если все значения пустые, значит SNMP не отвечает
    if (array_filter($info, fn($v) => $v !== '—') === []) {
        throw new RuntimeException("SNMP-запросы не дали результата. Проверьте IP, community и доступность устройства.");
    }

    if (empty($info['sysDescr']) || $info['sysDescr'] === '—') {
	    throw new RuntimeException("Устройство $ip не отвечает по SNMP (community: $community).");
    }

    // Количество портов
    $portCount = (int) preg_replace('/\D+/', '', 
        snmp_get($host, $community, $version, '.1.3.6.1.2.1.2.1.0')
    );
    
    // Данные портов
    $ports = [];
    for ($i = 1; $i <= $portCount; $i++) {
        $status = snmp_get($host, $community, $version, ".1.3.6.1.2.1.2.2.1.8.$i");
	$raw_speed = snmp_get($host, $community, $version, ".1.3.6.1.2.1.2.2.1.5.$i");
	$mbps = is_numeric($raw_speed) ? round((int)$raw_speed / 1_000_000) . ' Mbps' : '—';
	$ports[$i] = [
            'index'  => $i,
            'descr'  => snmp_get($host, $community, $version, ".1.3.6.1.2.1.2.2.1.2.$i"),
            'status' => strpos($status, '1') !== false ? 'UP' : 'DOWN',
            'class'  => strpos($status, '1') !== false ? 'up' : 'down',
            'speed'  => $mbps,
            'vlans'  => '—',
            'macs'   => []
        ];
    }
    
    // VLAN информация
    try {
        $vlanData = snmp_walk($host, $community, $version, '.1.3.6.1.2.1.17.7.1.4.3.1.2');
        foreach ($vlanData as $line) {
            if (preg_match('/\.(\d+)\s+=\s+STRING:\s*"([0-9A-F\s]+)"/i', $line, $matches)) {
                $portIndex = (int)$matches[1];
                if (isset($ports[$portIndex])) {
                    $ports[$portIndex]['vlans'] = 'VLAN:' . trim($matches[2]);
                }
            }
        }
    } catch (Throwable $e) {
        error_log("VLAN error: " . $e->getMessage());
    }
    
    // MAC-адреса
    try {
        $macData = snmp_walk($host, $community, $version, '.1.3.6.1.2.1.17.4.3.1.1');
        foreach ($macData as $line) {
            if (preg_match('/\.(\d+)\s+([0-9A-F:]{17})/i', $line, $matches)) {
                $portIndex = (int)$matches[1];
                if (isset($ports[$portIndex])) {
                    $ports[$portIndex]['macs'][] = strtoupper($matches[2]);
                }
            }
        }
    } catch (Throwable $e) {
        error_log("MAC error: " . $e->getMessage());
    }
    
    return [
        'info' => $info,
        'ports' => $ports
    ];
}

/** Управление кэшированием */
function load_cache(string $cacheFile, int $ttl): ?array {
    if (!file_exists($cacheFile)) return null;
    if (time() - filemtime($cacheFile) > $ttl) return null;
    
    $data = json_decode(file_get_contents($cacheFile), true);
    error_log('CACHE JSON: ' . print_r($data, true));
    return is_array($data) ? $data : null;
}

function save_cache(string $cacheFile, array $data): void {
    if (!is_dir(dirname($cacheFile))) {
        mkdir(dirname($cacheFile), 0775, true);
    }
    file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT));
}

/** Вывод HTML */
function render_html(array $data, string $ip, string $source, string $community): void {
    $info = $data['info'];
    $ports = $data['ports'];
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Карточка устройства</title>
        <link rel="stylesheet" href="/switch/style.css">
        <style>
            .card {
                background: #fff;
                border-radius: 12px;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
                padding: 25px;
                margin-bottom: 25px;
            }
            .port-card {
                background: white;
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 15px;
            }
        </style>
    </head>
    <body>
        <div style="margin: 0 auto; max-width: 1200px; display: flex; justify-content: space-between; padding: 1rem;">
            <div>
                <a href="list.php">
                    <button style="background: #fef3c7; color: #92400e; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 0.9em;">
                        📋 Все устройства
                    </button>
                </a>
            </div>
            <div>
                <a href="profile.php">
                    <button style="background: #e0f2fe; color: #0369a1; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 0.9em;">
                        ➕ Создать профиль
                    </button>
                </a>
            </div>
        </div>
        <div class="container">
            <!-- Карточка системной информации -->
            <div class="card">
                <div class="header">
                    <div>
                        <h1 style="margin: 0;"><?= htmlspecialchars($info['sysName']) ?></h1>
                        <p style="margin: 5px 0 0; color: #64748b;">IP: <?= htmlspecialchars($ip) ?></p>
			            <p style="margin: 5px 0 0; color: #64748b;">Community: <?= htmlspecialchars($community) ?></p>
                    </div>
                    <div class="source-tag">Источник: <?= $source === 'cache' ? 'Кэш' : 'Live данные' ?></div>
                </div>
                
                <div class="info-grid">
                    <div><strong>Описание:</strong> <?= htmlspecialchars($info['sysDescr']) ?></div>
                    <div><strong>Аптайм:</strong> <?= htmlspecialchars($info['sysUpTime']) ?></div>
                    <div><strong>Локация:</strong> <?= htmlspecialchars($info['sysLocation']) ?></div>
                    <div><strong>ObjectID:</strong> <code><?= htmlspecialchars($info['sysObjectID']) ?></code></div>
                </div>
            </div>
            
            <!-- Карточка с портами -->
            <div class="card">
                <div class="header">
                    <h2 style="margin: 0;">Сетевые порты</h2>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <span style="display: flex; align-items: center;">
                            <span style="display: inline-block; width: 12px; height: 12px; background: #dcfce7; border-radius: 50%; margin-right: 6px;"></span>
                            Активные: <?= count(array_filter($ports, fn($p) => $p['status'] === 'UP')) ?>
                        </span>
                        <span style="display: flex; align-items: center;">
                            <span style="display: inline-block; width: 12px; height: 12px; background: #fee2e2; border-radius: 50%; margin-right: 6px;"></span>
                            Неактивные: <?= count(array_filter($ports, fn($p) => $p['status'] === 'DOWN')) ?>
                        </span>
                    </div>
                </div>
                
                <div class="port-grid">
                    <?php foreach ($ports as $port): ?>
                        <div class="port-card <?= $port['class'] ?>">
                            <div class="port-header">
                                <div class="port-title">Порт <?= $port['index'] ?></div>
                                <div class="port-status <?= $port['class'] ?>"><?= $port['status'] ?></div>
                            </div>
                            
                            <div class="port-content">
                                <div class="port-info">
                                    <div>
                                        <strong>Описание:</strong>
                                        <span><?= htmlspecialchars($port['descr']) ?></span>
                                    </div>
                                    <div>
                                        <strong>Скорость:</strong>
                                        <span><?= htmlspecialchars($port['speed']) ?></span>
                                    </div>
                                    <div>
                                        <strong>VLAN:</strong>
                                        <span><?= htmlspecialchars($port['vlans']) ?></span>
                                    </div>
                                </div>
                                
                                <?php if (!empty($port['macs'])): ?>
                                    <div style="margin-top: 15px;">
                                        <strong>MAC-адреса:</strong>
                                        <div class="mac-list">
                                            <?php foreach ($port['macs'] as $mac): ?>
                                                <div><?= htmlspecialchars($mac) ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div style="margin-top: 10px; color: #94a3b8;">
                                        Нет MAC-адресов
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}

/** Обработка ошибок */
function handle_error(Throwable $e): void {
    error_log("[SNMP ERROR] {$e->getMessage()} from {$e->getFile()}:{$e->getLine()}");
    $status = $e instanceof InvalidArgumentException ? 400 : 500;
    http_response_code($status);

    $errorTitle = $status === 400 ? "Ошибка запроса" : "Ошибка сервера";
    $errorMessage = htmlspecialchars($e->getMessage());

    echo <<<HTML
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <title>Ошибка</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
        <div class="container">
            <div class="card" style="background: #fff0f0; border-left: 4px solid #ff5252;">
                <h2 style="margin-top: 0;">{$errorTitle}</h2>
                <div style="padding: 15px; background: #fff8f8; border-radius: 8px;">
                    <p style="margin: 0;">{$errorMessage}</p>
                </div>
                <div style="margin-top: 20px; font-size: 0.85em; color: #777;">
                    [{$e->getFile()}:{$e->getLine()}]
                </div>
            </div>
        </div>
    </body>
    </html>
    HTML;
}
