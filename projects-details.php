<?php
session_start();

// Перевірка автентифікації
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header(header: 'Location: login.php');
    exit;
}

$error = '';
$success = '';
$project = [];

// Отримання ID проєкту з URL
$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Підключення до бази даних
$serverName = "WIN-C0REURL4NB2\\MSSQLSERVER01";
$database = "form_data";

try {
    $conn = new PDO(
        dsn: "sqlsrv:Server=$serverName;Database=$database;TrustServerCertificate=1;Encrypt=1",
        username: null,
        password: null
    );
    $conn->setAttribute(attribute: PDO::ATTR_ERRMODE, value: PDO::ERRMODE_EXCEPTION);
    
    // Отримання даних проєкту
    $stmt = $conn->prepare(query: "
        SELECT * 
        FROM dbo.Projects 
        WHERE project_id = :project_id 
        AND user_id = :user_id
    ");
    $stmt->bindParam(param: ':project_id', var: $projectId, type: PDO::PARAM_INT);
    $stmt->bindParam(param: ':user_id', var: $_SESSION['user_id'], type: PDO::PARAM_INT);
    $stmt->execute();
    $project = $stmt->fetch(mode: PDO::FETCH_ASSOC);
    
    if (!$project) {
        header(header: 'Location: estimates.php');
        exit;
    }
    
    // Обробка оновлення даних
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $projectName = $_POST['project-name'];
        $projectType = $_POST['project-type'];
        $square = $_POST['square'];
        $floors = $_POST['floors'] ?? null;
        $foundation = $_POST['foundation'] ?? null;
        $floor = $_POST['floor'] ?? null;
        $walls = $_POST['walls'] ?? null;
        $roof = $_POST['roof'] ?? null;
        $status = $_POST['status'] ?? null;
        $startDate = $_POST['start_date'] ?? null;
        $endDate = $_POST['end_date'] ?? null;
        
        // Валідація дат (додано згідно з логікою create-project.php)
        if ($status === 'Completed' && (empty($startDate) || empty($endDate))) {
            $error = "Для завершеного проєкту потрібно вказати дату початку та завершення";
        } elseif (($status === 'Active' || $status === 'Pending') && empty($startDate)) {
            $error = "Для початку або процесу робіт потрібно вказати дату початку";
        } else {
            $updateSql = "
                UPDATE dbo.Projects 
                SET 
                    name = :name, 
                    type = :type, 
                    square = :square, 
                    floors = :floors, 
                    foundation = :foundation, 
                    floor = :floor, 
                    walls = :walls, 
                    roof = :roof, 
                    status = :status,
                    start_date = :start_date,
                    end_date = :end_date
                WHERE project_id = :project_id
                AND user_id = :user_id
            ";
            
            $updateStmt = $conn->prepare(query: $updateSql);
            $updateStmt->bindParam(param: ':name', var: $projectName);
            $updateStmt->bindParam(param: ':type', var: $projectType);
            $updateStmt->bindParam(param: ':square', var: $square);
            $updateStmt->bindParam(param: ':floors', var: $floors);
            $updateStmt->bindParam(param: ':foundation', var: $foundation);
            $updateStmt->bindParam(param: ':floor', var: $floor);
            $updateStmt->bindParam(param: ':walls', var: $walls);
            $updateStmt->bindParam(param: ':roof', var: $roof);
            $updateStmt->bindParam(param: ':status', var: $status);
            $updateStmt->bindParam(param: ':start_date', var: $startDate);
            $updateStmt->bindParam(param: ':end_date', var: $endDate);
            $updateStmt->bindParam(param: ':project_id', var: $projectId, type: PDO::PARAM_INT);
            $updateStmt->bindParam(param: ':user_id', var: $_SESSION['user_id'], type: PDO::PARAM_INT);
            
            if ($updateStmt->execute()) {
                $success = "Проєкт успішно оновлено!";
                // Оновити дані проєкту для відображення
                $stmt->execute();
                $project = $stmt->fetch(mode: PDO::FETCH_ASSOC);
            } else {
                $error = "Помилка при оновленні проєкту: " . implode(separator: " ", array: $updateStmt->errorInfo());
            }
        }
    }
} catch (PDOException $e) {
    $error = "Помилка бази даних: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Деталі проєкту | Будівельний калькулятор</title>
    <link rel="stylesheet" href="styles/style4.css">
    <style>
        .project-details-container {
            max-width: 800px;
            margin: 2rem auto;
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .project-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .back-link {
            color: #0951eb;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        .project-title {
            font-size: 1.8rem;
            color: #2c3e50;
            margin: 0;
        }
        
        .project-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        
        .meta-item {
            background: #f5f7fa;
            padding: 0.8rem;
            border-radius: 6px;
            min-width: 120px;
        }
        
        .meta-label {
            font-size: 0.9rem;
            color: #666;
        }
        
        .meta-value {
            font-weight: bold;
            margin-top: 0.3rem;
        }
        
        .status-active {
            color: #2ecc71;
        }
        
        .status-pending {
            color: #f39c12;
        }
        
        .status-completed {
            color: #3498db;
        }
        
        .section-title {
            font-size: 1.3rem;
            color: #2c3e50;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #eee;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: #2c3e50;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.8rem;
            font-size: 1rem;
            border: 1px solid #ddd;
            border-radius: 6px;
        }
        
        .form-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .save-btn {
            background: #27ae60;
            color: white;
            padding: 0.7rem 1.5rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
        }
        
        .save-btn:hover {
            background: #219653;
        }
        
        /* Новий CSS для полів дат */
        .date-fields {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .date-field {
            display: none;
            flex: 1;
        }
        
        .date-field.visible {
            display: block;
        }
        
        .required-field::after {
            content: " *";
            color: red;
        }
    </style>
</head>
<body>
    <div class="project-details-container">
        <div class="project-header">
            <a href="estimates.php" class="back-link">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
                </svg>
                Назад до проєктів
            </a>
            <h1 class="project-title"><?= htmlspecialchars(string: $project['name']) ?></h1>
        </div>
        
        <?php if ($error): ?>
            <div class="error-message"><?= htmlspecialchars(string: $error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-message"><?= htmlspecialchars(string: $success) ?></div>
        <?php endif; ?>
        
        <div class="project-meta">
            <div class="meta-item">
                <div class="meta-label">Статус</div>
                <div class="meta-value status-<?= strtolower(string: $project['status']) ?>">
                    <?= htmlspecialchars(string: $project['status']) ?>
                </div>
            </div>
            <div class="meta-item">
                <div class="meta-label">Площа</div>
                <div class="meta-value"><?= $project['square'] ?> м²</div>
            </div>
            <div class="meta-item">
                <div class="meta-label">Поверхи</div>
                <div class="meta-value"><?= $project['floors'] ?? '—' ?></div>
            </div>
            <div class="meta-item">
                <div class="meta-label">Створено</div>
                <div class="meta-value"><?= date(format: 'd.m.Y', timestamp: strtotime(datetime: $project['created_at'])) ?></div>
            </div>
        </div>
        
        <form method="post" id="project-form">
            <h2 class="section-title">Основна інформація</h2>
            
            <div class="form-group">
                <label for="project-name" class="required-field">Назва проєкту:</label>
                <input type="text" id="project-name" name="project-name" required
                       value="<?= htmlspecialchars(string: $project['name']) ?>">
            </div>
            
            <div class="form-group">
                <label for="project-type" class="required-field">Тип проєкту:</label>
                <select id="project-type" name="project-type" required>
                    <option value="Житловий будинок" <?= $project['type'] === 'Житловий будинок' ? 'selected' : '' ?>>Житловий будинок</option>
                    <option value="Котедж" <?= $project['type'] === 'Котедж' ? 'selected' : '' ?>>Котедж</option>
                    <option value="Дачний будинок" <?= $project['type'] === 'Дачний будинок' ? 'selected' : '' ?>>Дачний будинок</option>
                    <option value="Гараж" <?= $project['type'] === 'Гараж' ? 'selected' : '' ?>>Гараж</option>
                    <option value="Інше" <?= $project['type'] === 'Інше' ? 'selected' : '' ?>>Інше</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="status" class="required-field">Статус проєкту:</label>
                <select id="status" name="status" required onchange="toggleDateFields()">
                    <option value="Active" <?= $project['status'] === 'Active' ? 'selected' : '' ?>>Почато</option>
                    <option value="Pending" <?= $project['status'] === 'Pending' ? 'selected' : '' ?>>В процесі</option>
                    <option value="Completed" <?= $project['status'] === 'Completed' ? 'selected' : '' ?>>Завершено</option>
                </select>
            </div>
            
            <h2 class="section-title">Характеристики</h2>
            
            <div class="form-group">
                <label for="square" class="required-field">Площа (м²):</label>
                <input type="number" id="square" name="square" required min="1"
                       value="<?= $project['square'] ?>">
            </div>
            
            <div class="form-group">
                <label for="floors" class="required-field">Кількість поверхів:</label>
                <input type="number" id="floors" name="floors" min="1" required
                       value="<?= $project['floors'] ?>">
            </div>
            
            <div class="form-group">
                <label for="foundation">Тип фундаменту:</label>
                <select id="foundation" name="foundation">
                    <option value="">-- Оберіть тип --</option>
                    <option value="Стрічковий" <?= $project['foundation'] === 'Стрічковий' ? 'selected' : '' ?>>Стрічковий</option>
                    <option value="Цілий" <?= $project['foundation'] === 'Цілий' ? 'selected' : '' ?>>Цілий</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="floor">Підлога:</label>
                <select id="floor" name="floor">
                    <option value="">-- Оберіть тип --</option>
                    <option value="Цемент" <?= $project['floor'] === 'Цемент' ? 'selected' : '' ?>>Цемент</option>
                     <option value="Покриття плитою" <?= $project['floor'] === 'Покриття плитою' ? 'selected' : '' ?>>Покриття плитою</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="walls">Матеріал стін:</label>
                <select id="walls" name="walls">
                    <option value="">-- Оберіть тип --</option>
                    <option value="Цегла" <?= $project['walls'] === 'Цегла' ? 'selected' : '' ?>>Цегла</option>
                    <option value="Газоблок" <?= $project['walls'] === 'Газоблок' ? 'selected' : '' ?>>Газобетон</option>
                    <option value="Бетон" <?= $project['walls'] === 'Бетон' ? 'selected' : '' ?>>Бетон</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="roof">Тип даху:</label>
                <select id="roof" name="roof">
                    <option value="">-- Оберіть тип --</option>
                    <option value="Плоский" <?= $project['roof'] === 'Плоский' ? 'selected' : '' ?>>Плоский</option>
                    <option value="Двосхилий" <?= $project['roof'] === 'Двосхилий' ? 'selected' : '' ?>>Двосхилий</option>
                    <option value="Напів Вальмовий" <?= $project['roof'] === 'Напів Вальмовий' ? 'selected' : '' ?>>Напів Вальмовий</option>
                </select>
            </div>
            
            <h2 class="section-title">Дати</h2>
            
            <div class="form-row date-fields">
                <div class="form-group date-field visible" id="start-date-field">
                    <label for="start_date" class="required-field">Дата початку</label>
                    <input type="date" id="start_date" name="start_date"
                           value="<?= $project['start_date'] && $project['start_date'] !== '1970-01-01' 
                                ? htmlspecialchars(string: $project['start_date']) 
                                : '' ?>">
                </div>
                
                <div class="form-group date-field" id="end-date-field">
                    <label for="end_date">Дата завершення</label>
                    <input type="date" id="end_date" name="end_date"
                           value="<?= $project['end_date'] && $project['end_date'] !== '1970-01-01' 
                                ? htmlspecialchars(string: $project['end_date']) 
                                : '' ?>">
                </div>
            </div>
            
            <div class="form-buttons">
                <button type="submit" class="save-btn">Зберегти зміни</button>
            </div>
        </form>
    </div>

    <script>
        function toggleDateFields() {
            const status = document.getElementById('status').value;
            const startDateField = document.getElementById('start-date-field');
            const endDateField = document.getElementById('end-date-field');
            
            // Поле дати початку завжди видиме
            startDateField.classList.add('visible');
            
            if (status === 'Completed') {
                endDateField.classList.add('visible');
                // Робимо поле обов'язковим для завершених проектів
                document.getElementById('end_date').required = true;
                document.querySelector('#end-date-field label').classList.add('required-field');
            } else {
                endDateField.classList.remove('visible');
                // Робимо поле необов'язковим для інших статусів
                document.getElementById('end_date').required = false;
                document.querySelector('#end-date-field label').classList.remove('required-field');
            }
            
            // Для початку та процесу робіт поле дати початку обов'язкове
            if (status === 'Active' || status === 'Pending') {
                document.getElementById('start_date').required = true;
                document.querySelector('#start-date-field label').classList.add('required-field');
            } else {
                // Якщо статус не вимагає дати початку, робимо необов'язковим
                document.getElementById('start_date').required = false;
                document.querySelector('#start-date-field label').classList.remove('required-field');
            }
        }
        
        // Ініціалізація при завантаженні
        document.addEventListener('DOMContentLoaded', function() {
            toggleDateFields();
        });
    </script>
</body>
</html>