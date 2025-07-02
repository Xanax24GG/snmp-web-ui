<?php
// profile.php — полуавтоматический генератор SNMP-профиля

declare(strict_types=1);

function snmp_get_quiet(string $ip, string $oid): string {
    $cmd = escapeshellcmd("snmpget -v2c -c public -Ovq -t1 -r1 $ip $oid 2>/dev/null");
    return trim((string) shell_exec($cmd));
}

function detect_profile(string $descr, string $oid): string {
    $descr = strtolower($descr);
    if (str_contains($descr, 'zyxel') || str_contains($oid, '890')) return 'Zyxel-ES';
    if (str_contains($descr, 'tp-link')) return 'TP-Link';
    if (str_contains($descr, 'd-link') || str_contains($descr, 'dgs')) return 'D-Link';
    if (str_contains($descr, 'cisco')) return 'Cisco';
    return 'Generic-QBridge';
}

function save_profile_json(string $filename, array $info): void {
    $json = json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($filename, $json);
}

function save_profile_php(string $filename, array $info): void {
    $php = "<?php\nreturn " . var_export($info, true) . ";\n";
    file_put_contents($filename, $php);
}

// TEST-профиль
function create_test_cache(string $path): void {
    $data = [
        'info' => [
            'sysDescr' => 'ZyXEL ES-2024A SNMP Simulator',
            'sysUpTime' => '123456',
            'sysName' => 'demo-switch',
            'sysLocation' => 'Test-Lab',
            'sysObjectID' => 'SNMPv2-SMI::enterprises.890.1.5.8'
        ],
        'ports' => []
    ];
    for ($i = 1; $i <= 8; $i++) {
        $data['ports'][$i] = [
            'index' => $i,
            'descr' => "Port-$i",
            'status' => $i % 2 === 0 ? 'UP' : 'DOWN',
            'class' => $i % 2 === 0 ? 'up' : 'down',
            'speed' => '100 Mbps',
            'vlans' => 'VLAN ' . (10 + $i),
            'macs' => $i % 2 === 0 ? [sprintf("00:11:22:33:44:%02X", $i)] : []
        ];
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$ip = $_GET['ip'] ?? '';
$save = $_GET['save'] ?? '';
$info = null;
$error = '';
$success = '';

if ($save === 'test') {
    $ip = '127.0.0.1'; // Принудительно устанавливаем IP для тестового профиля
    $cacheFile = __DIR__ . "/cache/127.0.0.1.json";
    create_test_cache($cacheFile);
    $success = 'Тестовый профиль 127.0.0.1 создан';
    $descr = 'test';
}

if ($ip !== '') {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $error = "Неверный IP-адрес: $ip";
    } else {
        if ($descr != 'test') {
            $descr = snmp_get_quiet($ip, '1.3.6.1.2.1.1.1.0');
        }
        $sysObjectID = snmp_get_quiet($ip, '1.3.6.1.2.1.1.2.0');
        $sysName = snmp_get_quiet($ip, '1.3.6.1.2.1.1.5.0');

        if ($descr === '') {
            $error = "SNMP недоступен или отключён на устройстве $ip";
        } else {
            $profile = detect_profile($descr, $sysObjectID);
            $info = [
                'ip' => $ip,
                'sysName' => $sysName ?: '(не указано)',
                'sysDescr' => $descr,
                'sysObjectID' => $sysObjectID,
                'profile' => $profile,
                'timestamp' => date('c')
            ];

            if ($save === 'json') {
                save_profile_json(__DIR__ . "/profiles/profile_{$profile}.json", $info);
                $success = "Профиль сохранён как JSON: profile_{$profile}.json";
            } elseif ($save === 'php') {
                save_profile_php(__DIR__ . "/profiles/profile_{$profile}.php", $info);
                $success = "Профиль сохранён как PHP: profile_{$profile}.php";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Определение профиля коммутатора</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .card {
            transform: none !important;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08) !important;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1>Определение профиля коммутатора</h1>
                <a href="poll.php?ip=<?= urlencode($ip ?? '127.0.0.1') ?>">
                    <button class="secondary">← Назад к карточке</button>
                </a>
            </div>

            <!-- Основная форма для проверки устройства -->
            <form method="get" class="form-grid">
                <div class="form-group">
                    <label for="ip">IP-адрес устройства:</label>
                    <input type="text" id="ip" name="ip" value="<?= htmlspecialchars($ip) ?>" 
                           placeholder="192.168.1.1">
                </div>
                <div class="form-actions">
                    <button type="submit">🔍 Проверить устройство</button>
                </div>
            </form>

            <!-- Отдельная форма для тестового профиля -->
            <form method="get" class="form-grid" style="margin-top: 15px;">
                <input type="hidden" name="save" value="test">
                <div class="form-actions">
                    <button type="submit" class="secondary">🔧 Создать тестовый профиль</button>
                </div>
            </form>

            <?php if ($error): ?>
                <div class="alert error">
                    <div class="alert-icon">❌</div>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php elseif ($success): ?>
                <div class="alert success">
                    <div class="alert-icon">✅</div>
                    <div><?= htmlspecialchars($success) ?></div>
                </div>
            <?php elseif ($info): ?>
                <div class="card" style="margin-top: 20px; background: #f0fdf4;">
                    <h3 style="margin-top: 0;">Результаты обнаружения</h3>
                    <div class="info-grid">
                        <div><strong>Устройство:</strong> <?= htmlspecialchars($info['sysName']) ?></div>
                        <div><strong>Описание:</strong> <?= htmlspecialchars($info['sysDescr']) ?></div>
                        <div><strong>ObjectID:</strong> <code><?= htmlspecialchars($info['sysObjectID']) ?></code></div>
                        <div><strong>Профиль:</strong> <span class="badge"><?= htmlspecialchars($info['profile']) ?></span></div>
                    </div>

                    <div class="button-group" style="margin-top: 20px;">
                        <a href="poll.php?ip=<?= htmlspecialchars($info['ip']) ?>">
                            <button>📊 Открыть карточку устройства</button>
                        </a>
                        <a href="?ip=<?= htmlspecialchars($info['ip']) ?>&save=json">
                            <button class="secondary">💾 Сохранить как JSON</button>
                        </a>
                        <a href="?ip=<?= htmlspecialchars($info['ip']) ?>&save=php">
                            <button class="secondary">💾 Сохранить как PHP</button>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
