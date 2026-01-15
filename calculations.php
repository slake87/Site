<?php
// Початок сесії та перевірка автентифікації
session_start();
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header('Location: login.php');
    exit;
}

// Налаштування підключення до бази даних
$serverName = "WIN-C0REURL4NB2\\MSSQLSERVER01";
$database = "form_data";
$error = '';
$success = '';
$projects = [];
$materials = [];

try {
    $conn = new PDO(
        "sqlsrv:Server=$serverName;Database=$database;TrustServerCertificate=1;Encrypt=1",
        null,
        null
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Отримання проєктів користувача
    $stmt = $conn->prepare("SELECT project_id, name FROM dbo.Projects WHERE user_id = :user_id");
    $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Отримання матеріалів з нормами
    $stmt = $conn->query("
        SELECT m.material_id, m.name, m.category, m.base_price, m.unit, 
               n.unit_consumption, n.waste_factor
        FROM dbo.Materials m
        LEFT JOIN dbo.MaterialNorms n ON m.material_id = n.material_id AND n.construction_type = m.category
        ORDER BY m.category, m.name
    ");
    $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Обробка відправки форми для збереження розрахунків
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_calculation'])) {
        $projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        $notes = $_POST['notes'] ?? '';
        $totalCost = (float)$_POST['total_cost'];
        $estimateName = $_POST['estimate_name'] ?? 'Новий кошторис';
        $exportFormat = $_POST['export_format'] ?? 'xlsx';
        $date = date('Y-m-d');
        
        $conn->beginTransaction();
        try {
            // Генерація нового calc_id
            $maxIdStmt = $conn->prepare("SELECT MAX(calc_id) AS max_id FROM dbo.Calculations");
            $maxIdStmt->execute();
            $maxIdRow = $maxIdStmt->fetch(PDO::FETCH_ASSOC);
            $newCalcId = $maxIdRow && $maxIdRow['max_id'] !== null ? ((int)$maxIdRow['max_id'] + 1) : 1;
            
            // Вставка в таблицю Calculations
            $insertCalcSql = "INSERT INTO dbo.Calculations (calc_id, project_id, date, total_cost, notes, created_at) 
                          VALUES (:calc_id, :project_id, :date, :total_cost, :notes, GETDATE())";
            $insertCalcStmt = $conn->prepare($insertCalcSql);
            $insertCalcStmt->bindParam(':calc_id', $newCalcId, PDO::PARAM_INT);
            $insertCalcStmt->bindParam(':project_id', $projectId, PDO::PARAM_INT);
            $insertCalcStmt->bindParam(':date', $date);
            $insertCalcStmt->bindParam(':total_cost', $totalCost);
            $insertCalcStmt->bindParam(':notes', $notes);
            $insertCalcStmt->execute();
            
            // Генерація нового estimate_id
            $maxEstStmt = $conn->prepare("SELECT MAX(estimate_id) AS max_id FROM dbo.Estimates");
            $maxEstStmt->execute();
            $maxEstRow = $maxEstStmt->fetch(PDO::FETCH_ASSOC);
            $newEstId = $maxEstRow && $maxEstRow['max_id'] !== null ? ((int)$maxEstRow['max_id'] + 1) : 1;
            
            // Вставка в таблицю Estimates
            $insertEstSql = "INSERT INTO dbo.Estimates (
                            estimate_id, calc_id, name, total_cost, 
                            status, export_format, created_at
                         ) VALUES (
                            :estimate_id, :calc_id, :name, :total_cost, 
                            'active', :export_format, GETDATE()
                         )";
            $insertEstStmt = $conn->prepare($insertEstSql);
            $insertEstStmt->bindParam(':estimate_id', $newEstId, PDO::PARAM_INT);
            $insertEstStmt->bindParam(':calc_id', $newCalcId, PDO::PARAM_INT);
            $insertEstStmt->bindParam(':name', $estimateName);
            $insertEstStmt->bindParam(':total_cost', $totalCost);
            $insertEstStmt->bindParam(':export_format', $exportFormat);
            $insertEstStmt->execute();
            
            // Збереження деталей матеріалів
            $materialDetails = [
                'roof' => ['material_id' => $_POST['roof_material_id'] ?? null, 'quantity' => $_POST['roof_required_quantity'] ?? 0],
                'walls' => ['material_id' => $_POST['walls_material_id'] ?? null, 'quantity' => $_POST['walls_required_quantity'] ?? 0],
                'floor' => ['material_id' => $_POST['floor_material_id'] ?? null, 'quantity' => $_POST['floor_required_quantity'] ?? 0]
            ];
            foreach ($materialDetails as $type => $detail) {
                if ($detail['material_id'] && $detail['quantity'] > 0) {
                    $maxRecordStmt = $conn->prepare("SELECT MAX(record_id) AS max_id FROM dbo.ProjectMaterials");
                    $maxRecordStmt->execute();
                    $maxRecordRow = $maxRecordStmt->fetch(PDO::FETCH_ASSOC);
                    $newRecordId = $maxRecordRow && $maxRecordRow['max_id'] !== null ? ((int)$maxRecordRow['max_id'] + 1) : 1;
                    
                    $insertMatSql = "INSERT INTO dbo.ProjectMaterials (record_id, project_id, material_id, quantity, created_at) 
                                 VALUES (:record_id, :project_id, :material_id, :quantity, GETDATE())";
                    $insertMatStmt = $conn->prepare($insertMatSql);
                    $insertMatStmt->bindParam(':record_id', $newRecordId, PDO::PARAM_INT);
                    $insertMatStmt->bindParam(':project_id', $projectId, PDO::PARAM_INT);
                    $insertMatStmt->bindParam(':material_id', $detail['material_id'], PDO::PARAM_INT);
                    $insertMatStmt->bindParam(':quantity', $detail['quantity'], PDO::PARAM_STR);
                    $insertMatStmt->execute();
                }
            }
            
            $conn->commit();
            $success = "Розрахунок та кошторис успішно збережено!";
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Помилка при збереженні: " . $e->getMessage();
        }
    }
} catch (PDOException $e) {
    $error = "Помилка бази даних: " . $e->getMessage();
}

// Групування матеріалів по категоріях для випадаючих списків
$roofMaterials = array_filter($materials, fn($m) => $m['category'] === 'Покрівля');
$wallMaterials = array_filter($materials, fn($m) => $m['category'] === 'Стінові матеріали');
$floorMaterials = array_filter($materials, fn($m) => $m['category'] === 'Покриття підлоги');
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Розрахунки | Будівельний калькулятор</title>
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="styles/style6.css">
    <style>
        .project-selector {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: #f5f7fa;
            border-radius: 8px;
        }
        .project-selector label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: bold;
        }
        .project-selector select {
            width: 100%;
            padding: 0.8rem;
            font-size: 1rem;
            border: 1px solid #ddd;
            border-radius: 6px;
        }
        .notes-container {
            margin-top: 1.5rem;
        }
        .notes-container textarea {
            width: 95%;
            min-height: 100px;
            padding: 0.8rem;
            font-size: 1rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            resize: vertical;
        }
        .material-select {
            margin-top: 0.5rem;
        }
        .material-select select {
            width: 100%;
            padding: 0.5rem;
            font-size: 1rem;
            border: 1px solid #ddd;
            border-radius: 6px;
        }
        .detailed-calc {
            margin-top: 1rem;
            font-size: 0.9rem;
            color: #555;
            padding: 8px;
            background: #f9f9f9;
            border-radius: 4px;
        }
        .no-materials {
            color: #e74c3c;
            font-style: italic;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <header class="dashboard-header">
        <nav class="nav-menu">
            <ul class="nav-list">
                <li><a href="estimates.php" class="nav-link">Проєкти</a></li>
                <li><a href="materials.php" class="nav-link">Матеріали</a></li>
                <li><a href="calculations.php" class="nav-link active">Розрахунки</a></li>
                <li><a href="suppliers.php" class="nav-link">Постачальники</a></li>
            </ul>
        </nav>
        <div class="user-panel">
            <button class="profile" onclick="window.location.href='profile.php'">Мій профіль</button>
            <span class="username">Привіт, <?= htmlspecialchars($_SESSION['username'] ?? 'Користувач') ?>!</span>
            <button class="logout-btn" onclick="window.location.href='logout.php'">Вихід</button>
        </div>
    </header>

    <main class="dashboard-main">
        <div class="section-header">
            <h2 class="section-title">Розрахунок вартості матеріалів</h2>
        </div>
        
        <?php if ($error): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success-message"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <div class="calculation-container">
            <div class="input-section">
                <div class="project-selector">
                    <label for="project-select">Оберіть проєкт:</label>
                    <select id="project-select" name="project_id">
                        <?php foreach ($projects as $project): ?>
                            <option value="<?= $project['project_id'] ?>">
                                <?= htmlspecialchars($project['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Блок розрахунку покрівлі -->
                <div class="calculation-block">
                    <h3 class="block-title"><span>🏠</span> Покрівля (дах)</h3>
                    <div class="input-group">
                        <label for="roof-area">Площа покрівлі (м²):</label>
                        <input type="number" id="roof-area" name="roof_area" min="0" step="0.1" value="">
                    </div>
                    <div class="material-select">
                        <label for="roof-material">Матеріал:</label>
                        <select id="roof-material" name="roof_material_id">
                            <option value="">-- Виберіть матеріал --</option>
                            <?php if (!empty($roofMaterials)): ?>
                                <?php foreach ($roofMaterials as $material): ?>
                                    <option value="<?= $material['material_id'] ?>">
                                        <?= htmlspecialchars($material['name']) ?> (<?= number_format($material['base_price'], 2) ?> грн/<?= htmlspecialchars($material['unit']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option disabled>Немає доступних матеріалів</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="detailed-calc" id="roof-detailed"></div>
                </div>
                
                <!-- Блок розрахунку стін -->
                <div class="calculation-block">
                    <h3 class="block-title"><span>🧱</span> Стіни (штукатурка)</h3>
                    <div class="input-group">
                        <label for="walls-area">Площа стін (м²):</label>
                        <input type="number" id="walls-area" name="walls_area" min="0" step="0.1" value="">
                    </div>
                    <div class="material-select">
                        <label for="walls-material">Матеріал:</label>
                        <select id="walls-material" name="walls_material_id">
                            <option value="">-- Виберіть матеріал --</option>
                            <?php if (!empty($wallMaterials)): ?>
                                <?php foreach ($wallMaterials as $material): ?>
                                    <option value="<?= $material['material_id'] ?>">
                                        <?= htmlspecialchars($material['name']) ?> (<?= number_format($material['base_price'], 2) ?> грн/<?= htmlspecialchars($material['unit']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option disabled>Немає доступних матеріалів</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="detailed-calc" id="walls-detailed"></div>
                </div>
                
                <!-- Блок розрахунку підлоги -->
                <div class="calculation-block">
                    <h3 class="block-title"><span>🔳</span> Підлога</h3>
                    <div class="input-group">
                        <label for="floor-area">Площа підлоги (м²):</label>
                        <input type="number" id="floor-area" name="floor_area" min="0" step="0.1" value="">
                    </div>
                    <div class="material-select">
                        <label for="floor-material">Матеріал:</label>
                        <select id="floor-material" name="floor_material_id">
                            <option value="">-- Виберіть матеріал --</option>
                            <?php if (!empty($floorMaterials)): ?>
                                <?php foreach ($floorMaterials as $material): ?>
                                    <option value="<?= $material['material_id'] ?>">
                                        <?= htmlspecialchars($material['name']) ?> (<?= number_format($material['base_price'], 2) ?> грн/<?= htmlspecialchars($material['unit']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option disabled>Немає доступних матеріалів</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="detailed-calc" id="floor-detailed"></div>
                </div>
                
                <!-- Нотатки -->
                <div class="notes-container">
                    <label for="calculation-notes">Нотатки:</label>
                    <textarea id="calculation-notes" name="notes" placeholder="Додайте нотатки до розрахунку..."></textarea>
                </div>
            </div>
            
            <!-- Секція результатів -->
            <div class="result-section">
                <h3 class="block-title">Результати розрахунку</h3>
                <div class="result-item">
                    <span>Покрівля (дах):</span>
                    <span class="cost-value" id="roof-result">0.00 грн</span>
                </div>
                <div class="result-item">
                    <span>Стіни (штукатурка):</span>
                    <span class="cost-value" id="walls-result">0.00 грн</span>
                </div>
                <div class="result-item">
                    <span>Підлога:</span>
                    <span class="cost-value" id="floor-result">0.00 грн</span>
                </div>
                <div class="result-item total">
                    <span>Загальна вартість:</span>
                    <span class="cost-value" id="total-result">0.00 грн</span>
                </div>
                
                <!-- Візуалізація витрат -->
                <div class="visualization">
                    <h4 class="visualization-title">Візуалізація витрат</h4>
                    <div class="cost-bars">
                        <div class="cost-bar" id="roof-bar" style="height: 120px;">
                            <div class="cost-bar-value"></div>
                            <div class="cost-bar-label">Покрівля</div>
                        </div>
                        <div class="cost-bar" id="walls-bar" style="height: 200px;">
                            <div class="cost-bar-value"></div>
                            <div class="cost-bar-label">Стіни</div>
                        </div>
                        <div class="cost-bar" id="floor-bar" style="height: 109px;">
                            <div class="cost-bar-value"></div>
                            <div class="cost-bar-label">Підлога</div>
                        </div>
                    </div>
                </div>
                
                <!-- Кнопки збереження та експорту -->
                <div class="save-container">
                    <form id="save-form" method="post" style="display: none;">
                        <input type="hidden" name="total_cost" id="save-total-cost">
                        <input type="hidden" name="project_id" id="save-project-id">
                        <input type="hidden" name="notes" id="save-notes">
                        <input type="hidden" name="estimate_name" id="save-estimate-name">
                        <input type="hidden" name="export_format" id="save-export-format" value="xlsx">
                        <input type="hidden" name="roof_material_id" id="save-roof-material-id">
                        <input type="hidden" name="walls_material_id" id="save-walls-material-id">
                        <input type="hidden" name="floor_material_id" id="save-floor-material-id">
                        <input type="hidden" name="roof_required_quantity" id="save-roof-required-quantity">
                        <input type="hidden" name="walls_required_quantity" id="save-walls-required-quantity">
                        <input type="hidden" name="floor_required_quantity" id="save-floor-required-quantity">
                        <input type="hidden" name="save_calculation" value="1">
                    </form>
                    <button class="save-btn" id="save-calculation">
                        <span>💾</span> Зберегти розрахунок
                    </button>
                    <button class="export-btn" id="export-excel">
                        <span>📊</span> Експорт в Excel
                    </button>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Модальне вікно для збереження -->
    <div class="modal" id="save-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Зберегти розрахунок</h3>
                <button class="close-btn" id="close-modal">&times;</button>
            </div>
            <div class="modal-input-group">
                <label for="estimate-name">Назва розрахунку:</label>
                <input type="text" id="estimate-name" placeholder="Наприклад: Котедж 'Сонячний'">
            </div>
            <div class="modal-input-group">
                <label for="estimate-date">Дата:</label>
                <input type="date" id="estimate-date" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="modal-buttons">
                <button class="save-btn" id="confirm-save">Підтвердити збереження</button>
            </div>
        </div>
    </div>
    
    <!-- JavaScript для інтерактивності -->
    <script>
        // Дані матеріалів з PHP
        var materials = <?= json_encode($materials) ?>;
        
        // Форматування валюти
        function formatCurrency(value) {
            return value.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$& ') + ' грн';
        }
        
        // Форматування чисел
        function formatNumber(value) {
            return value.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$& ');
        }
        
        // Розрахунок витрат згідно математичної моделі
        function calculateCosts() {
            const rArea = parseFloat(document.getElementById('roof-area').value) || 0;
            const wArea = parseFloat(document.getElementById('walls-area').value) || 0;
            const fArea = parseFloat(document.getElementById('floor-area').value) || 0;
            
            const rMaterialId = document.getElementById('roof-material').value;
            const wMaterialId = document.getElementById('walls-material').value;
            const fMaterialId = document.getElementById('floor-material').value;
            
            let roofCost = 0, wallsCost = 0, floorCost = 0;
            let roofDetail = '', wallsDetail = '', floorDetail = '';
            let roofRequiredQuantity = 0, wallsRequiredQuantity = 0, floorRequiredQuantity = 0;
            
            // Розрахунок для покрівлі: q_j = (b_i * n_i(j)) * (1 + k_j)
            if (rArea > 0 && rMaterialId) {
                const rMaterial = materials.find(m => m.material_id == rMaterialId);
                if (rMaterial) {
                    const unitConsumption = rMaterial.unit_consumption || 1;
                    const wasteFactor = rMaterial.waste_factor || 0;
                    // Формула з математичної моделі
                    roofRequiredQuantity = rArea * unitConsumption * (1 + wasteFactor/100);
                    roofCost = roofRequiredQuantity * rMaterial.base_price;
                    roofDetail = `
                        <div>Норма витрати: ${unitConsumption} ${rMaterial.unit}/м²</div>
                        <div>Коефіцієнт запасу: ${wasteFactor}%</div>
                        <div>Потрібно: ${formatNumber(roofRequiredQuantity)} ${rMaterial.unit}</div>
                        <div>Вартість: ${formatCurrency(roofCost)}</div>
                    `;
                }
            }
            
            // Розрахунок для стін
            if (wArea > 0 && wMaterialId) {
                const wMaterial = materials.find(m => m.material_id == wMaterialId);
                if (wMaterial) {
                    const unitConsumption = wMaterial.unit_consumption || 1;
                    const wasteFactor = wMaterial.waste_factor || 0;
                    wallsRequiredQuantity = wArea * unitConsumption * (1 + wasteFactor/100);
                    wallsCost = wallsRequiredQuantity * wMaterial.base_price;
                    wallsDetail = `
                        <div>Норма витрати: ${unitConsumption} ${wMaterial.unit}/м²</div>
                        <div>Коефіцієнт запасу: ${wasteFactor}%</div>
                        <div>Потрібно: ${formatNumber(wallsRequiredQuantity)} ${wMaterial.unit}</div>
                        <div>Вартість: ${formatCurrency(wallsCost)}</div>
                    `;
                }
            }
            
            // Розрахунок для підлоги
            if (fArea > 0 && fMaterialId) {
                const fMaterial = materials.find(m => m.material_id == fMaterialId);
                if (fMaterial) {
                    const unitConsumption = fMaterial.unit_consumption || 1;
                    const wasteFactor = fMaterial.waste_factor || 0;
                    floorRequiredQuantity = fArea * unitConsumption * (1 + wasteFactor/100);
                    floorCost = floorRequiredQuantity * fMaterial.base_price;
                    floorDetail = `
                        <div>Норма витрати: ${unitConsumption} ${fMaterial.unit}/м²</div>
                        <div>Коефіцієнт запасу: ${wasteFactor}%</div>
                        <div>Потрібно: ${formatNumber(floorRequiredQuantity)} ${fMaterial.unit}</div>
                        <div>Вартість: ${formatCurrency(floorCost)}</div>
                    `;
                }
            }
            
            const totalCost = roofCost + wallsCost + floorCost;
            
            document.getElementById('roof-result').textContent = formatCurrency(roofCost);
            document.getElementById('walls-result').textContent = formatCurrency(wallsCost);
            document.getElementById('floor-result').textContent = formatCurrency(floorCost);
            document.getElementById('total-result').textContent = formatCurrency(totalCost);
            
            document.getElementById('roof-detailed').innerHTML = roofDetail;
            document.getElementById('walls-detailed').innerHTML = wallsDetail;
            document.getElementById('floor-detailed').innerHTML = floorDetail;
            
            updateVisualization(roofCost, wallsCost, floorCost, totalCost);
            
            return {
                roof: { cost: roofCost, requiredQuantity: roofRequiredQuantity, material_id: rMaterialId, area: rArea },
                walls: { cost: wallsCost, requiredQuantity: wallsRequiredQuantity, material_id: wMaterialId, area: wArea },
                floor: { cost: floorCost, requiredQuantity: floorRequiredQuantity, material_id: fMaterialId, area: fArea },
                total: totalCost
            };
        }
        
        // Оновлення візуалізації
        function updateVisualization(roof, walls, floor, total) {
            const maxValue = Math.max(roof, walls, floor, 1);
            const scale = 200 / maxValue;
            
            document.getElementById('roof-bar').style.height = (roof * scale) + 'px';
            document.getElementById('walls-bar').style.height = (walls * scale) + 'px';
            document.getElementById('floor-bar').style.height = (floor * scale) + 'px';
            
            document.getElementById('roof-bar').querySelector('.cost-bar-value').textContent = formatNumber(roof);
            document.getElementById('walls-bar').querySelector('.cost-bar-value').textContent = formatNumber(walls);
            document.getElementById('floor-bar').querySelector('.cost-bar-value').textContent = formatNumber(floor);
        }
        
        // Експорт в Excel
        function exportToExcel() {
            const data = calculateCosts();
            const projectSelect = document.getElementById('project-select');
            const projectName = projectSelect.options[projectSelect.selectedIndex].text;
            
            // Отримання назв матеріалів та одиниць виміру
            const getMaterialInfo = (materialId) => {
                if (!materialId) return { name: '', unit: '' };
                const material = materials.find(m => m.material_id == materialId);
                return material 
                    ? { name: material.name, unit: material.unit } 
                    : { name: '', unit: '' };
            };

            const roofInfo = getMaterialInfo(data.roof.material_id);
            const wallsInfo = getMaterialInfo(data.walls.material_id);
            const floorInfo = getMaterialInfo(data.floor.material_id);

            const wb = XLSX.utils.book_new();
            const wsData = [
                ["КОШТОРИС БУДІВЕЛЬНИХ МАТЕРІАЛІВ"],
                ["Проєкт:", projectName],
                ["Дата створення:", new Date().toLocaleDateString()],
                ["Нотатки:", document.getElementById('calculation-notes').value],
                [],
                ["Тип робіт", "Матеріал", "Площа (м²)", "Кількість", "Одиниця", "Вартість (грн)"],
                [
                    "Покрівля (дах)", 
                    roofInfo.name, 
                    data.roof.area || 0,
                    data.roof.requiredQuantity || 0,
                    roofInfo.unit,
                    data.roof.cost || 0
                ],
                [
                    "Стіни (штукатурка)", 
                    wallsInfo.name, 
                    data.walls.area || 0,
                    data.walls.requiredQuantity || 0,
                    wallsInfo.unit,
                    data.walls.cost || 0
                ],
                [
                    "Підлога", 
                    floorInfo.name, 
                    data.floor.area || 0,
                    data.floor.requiredQuantity || 0,
                    floorInfo.unit,
                    data.floor.cost || 0
                ],
                [],
                ["Загальна вартість", "", "", "", "", data.total || 0]
            ];
            const ws = XLSX.utils.aoa_to_sheet(wsData);
            
            // Форматування чисел
            const formatNum = (num) => typeof num === 'number' ? num.toFixed(2) : num;
            ['D', 'E', 'F'].forEach(col => {
                for (let i = 6; i <= 8; i++) {
                    const cell = ws[XLSX.utils.encode_cell({c: col.charCodeAt(0)-65, r: i})];
                    if (cell) cell.t = 'n', cell.z = '#,##0.00';
                }
            });
            
            XLSX.utils.book_append_sheet(wb, ws, "Кошторис");
            
            const fileName = `кошторис_${projectName.replace(/[^\wа-яА-ЯіІїЇєЄ]/gi, '_')}_${new Date().toISOString().slice(0,10)}.xlsx`;
            XLSX.writeFile(wb, fileName);
        }
        
        // Обробники подій
        const inputs = document.querySelectorAll('input[type="number"], select');
        inputs.forEach(input => {
            input.addEventListener('input', calculateCosts);
        });
        
        document.getElementById('save-calculation').addEventListener('click', function() {
            document.getElementById('save-modal').style.display = 'flex';
        });
        
        document.getElementById('export-excel').addEventListener('click', function() {
            exportToExcel();
        });
        
        document.getElementById('close-modal').addEventListener('click', function() {
            document.getElementById('save-modal').style.display = 'none';
        });
        
        document.getElementById('confirm-save').addEventListener('click', function() {
            const data = calculateCosts();
            const projectSelect = document.getElementById('project-select');
            const estimateName = document.getElementById('estimate-name').value || 'Без назви';
            
            document.getElementById('save-total-cost').value = data.total;
            document.getElementById('save-project-id').value = projectSelect.value;
            document.getElementById('save-notes').value = document.getElementById('calculation-notes').value;
            document.getElementById('save-estimate-name').value = estimateName;
            document.getElementById('save-roof-material-id').value = data.roof.material_id || '';
            document.getElementById('save-walls-material-id').value = data.walls.material_id || '';
            document.getElementById('save-floor-material-id').value = data.floor.material_id || '';
            document.getElementById('save-roof-required-quantity').value = data.roof.requiredQuantity.toFixed(2);
            document.getElementById('save-walls-required-quantity').value = data.walls.requiredQuantity.toFixed(2);
            document.getElementById('save-floor-required-quantity').value = data.floor.requiredQuantity.toFixed(2);
            
            document.getElementById('save-form').submit();
        });
        
        window.addEventListener('click', function(e) {
            if (e.target === document.getElementById('save-modal')) {
                document.getElementById('save-modal').style.display = 'none';
            }
        });
        
        // Початковий розрахунок
        calculateCosts();
    </script>
</body>
</html>