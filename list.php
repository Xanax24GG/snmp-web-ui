<?php
// list.php — список устройств из кэша (карточками)

$cacheDir = __DIR__ . '/cache';
$files = glob("$cacheDir/*.json");
$devices = [];

foreach ($files as $file) {
    $ip = basename($file, '.json');
    $json = json_decode(file_get_contents($file), true);
    if (is_array($json)) {
        $devices[] = [
            'ip' => $ip,
            'name' => $json['info']['sysName'] ?? '—',
            'descr' => $json['info']['sysDescr'] ?? '—',
            'uptime' => $json['info']['sysUpTime'] ?? '—',
            'ports' => is_array($json['ports']) ? count($json['ports']) : 0,
            'profile' => $json['info']['profile'] ?? '—',
        ];
    }
}

// Удаление
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    $target = basename($_POST['delete']);
    $path = "$cacheDir/$target.json";
    if (file_exists($path)) unlink($path);
    header("Location: list.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Список устройств</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .device-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .device-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            padding: 20px;
            display: flex;
            flex-direction: column;
        }
        .device-card h3 {
            margin: 0 0 10px;
            color: #1e293b;
            font-size: 1.2em;
        }
        .device-info {
            font-size: 0.95em;
            color: #475569;
            margin-bottom: 15px;
        }
        .device-info div {
            margin-bottom: 5px;
            display: flex;
        }
        .device-info strong {
            min-width: 80px;
            color: #64748b;
        }
        .device-actions {
            display: flex;
            gap: 10px;
            margin-top: auto;
        }
        .device-actions button {
            flex: 1;
            padding: 8px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
        }
        .btn-open {
            background: #dbeafe;
            color: #1d4ed8;
        }
        .btn-delete {
            background: #fee2e2;
            color: #991b1b;
        }
        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card no-hover-effect">
            <div class="header-bar">
                <h1>Сохранённые устройства (<?= count($devices) ?>)</h1>
                <a href="poll.php">
                    <button class="secondary">← Назад к карточке</button>
                </a>
            </div>

            <?php if (empty($devices)): ?>
                <p style="text-align: center; color: #64748b;">Нет сохранённых устройств</p>
            <?php else: ?>
                <div class="device-grid">
                    <?php foreach ($devices as $dev): ?>
                    <div class="device-card no-hover-effect">
                        <h3><?= htmlspecialchars($dev['name']) ?></h3>
                        <div class="device-info">
                            <div><strong>IP:</strong> <code><?= $dev['ip'] ?></code></div>
                            <div><strong>Описание:</strong> <?= htmlspecialchars($dev['descr']) ?></div>
                            <div><strong>Аптайм:</strong> <?= htmlspecialchars($dev['uptime']) ?></div>
                            <div><strong>Портов:</strong> <?= $dev['ports'] ?></div>
                            <div><strong>Профиль:</strong> <code><?= $dev['profile'] ?></code></div>
                        </div>
                        <div class="device-actions">
                            <a href="poll.php?ip=<?= $dev['ip'] ?>">
                                <button class="btn-open">🔍 Открыть</button>
                            </a>
                            <form method="post" onsubmit="return confirm('Удалить профиль <?= $dev['ip'] ?>?');">
                                <input type="hidden" name="delete" value="<?= $dev['ip'] ?>">
                                <button type="submit" class="btn-delete">🗑 Удалить</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
