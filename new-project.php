<?php
session_start();

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

$serverName = "WIN-C0REURL4NB2\\MSSQLSERVER01";
$database = "form_data";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = new PDO(
            "sqlsrv:Server=$serverName;Database=$database;TrustServerCertificate=1;Encrypt=1",
            null,
            null
        );
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Отримання максимального існуючого project_id
        $stmt = $conn->query("SELECT MAX(project_id) AS max_id FROM dbo.Projects");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $newProjectId = $result['max_id'] ? $result['max_id'] + 1 : 1;
        
        // Отримання даних з форми
        $projectName = $_POST['project-name'];
        $projectType = $_POST['project-type'];
        $square = $_POST['square'];
        $floors = $_POST['floors'] ?? null;
        $foundation = $_POST['foundation'] ?? null;
        $floor = $_POST['floor'] ?? null;
        $walls = $_POST['walls'] ?? null;
        $roof = $_POST['roof'] ?? null;
        $status = $_POST['status'] ?? 'Active';
        $startDate = $_POST['start_date'] ?? null;
        $endDate = $_POST['end_date'] ?? null;
        
        // Перевірка обов'язкових полів
        if (empty($projectName)) {
            $error = "Назва проєкту обов'язкова!";
        } elseif (empty($projectType)) {
            $error = "Тип проєкту обов'язковий!";
        } elseif (empty($square) || $square <= 0) {
            $error = "Площа повинна бути більше 0!";
        } elseif ($status === 'Completed' && (empty($startDate) || empty($endDate))) {
            $error = "Для завершеного проєкту потрібно вказати дату початку та завершення";
        } elseif (($status === 'Active' || $status === 'Pending') && empty($startDate)) {
            $error = "Для початку або процесу робіт потрібно вказати дату початку";
        } else {
            $insertSql = "INSERT INTO dbo.Projects 
                (project_id, user_id, name, type, square, floors, foundation, floor, walls, roof, status, start_date, end_date, created_at) 
                VALUES 
                (:project_id, :user_id, :name, :type, :square, :floors, :foundation, :floor, :walls, :roof, :status, :start_date, :end_date, GETDATE())";
            
            $stmt = $conn->prepare($insertSql);
            $stmt->bindParam(':project_id', $newProjectId, PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
            $stmt->bindParam(':name', $projectName);
            $stmt->bindParam(':type', $projectType);
            $stmt->bindParam(':square', $square);
            $stmt->bindParam(':floors', $floors);
            $stmt->bindParam(':foundation', $foundation);
            $stmt->bindParam(':floor', $floor);
            $stmt->bindParam(':walls', $walls);
            $stmt->bindParam(':roof', $roof);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':start_date', $startDate);
            $stmt->bindParam(':end_date', $endDate);
            
            if ($stmt->execute()) {
                $_SESSION['project_created'] = true;
                header("Location: projects-details.php?id=$newProjectId");
                exit;
            } else {
                $error = "Помилка при створенні проєкту: " . implode(" ", $stmt->errorInfo());
            }
        }
    } catch (PDOException $e) {
        $error = "Помилка бази даних: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Новий проєкт | Будівельний калькулятор</title>
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
        
        .form-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
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
        
        .cancel-btn {
            background: #6c757d;
            color: white;
            padding: 0.7rem 1.5rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
        }
        
        .cancel-btn:hover {
            background: #5a6268;
        }
        
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
        
        .error-message {
            background-color: #ffdddd;
            border-left: 4px solid #f44336;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
            color: #d32f2f;
        }
        
        .success-message {
            background-color: #ddffdd;
            border-left: 4px solid #4CAF50;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
            color: #388e3c;
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
            <h1 class="project-title">Новий проєкт</h1>
        </div>
        
        <?php if ($error): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-message"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <form method="post" id="project-form">
            <h2 class="section-title">Основна інформація</h2>
            
            <div class="form-group">
                <label for="project-name" class="required-field">Назва проєкту:</label>
                <input type="text" id="project-name" name="project-name" required>
            </div>
            
            <div class="form-group">
                <label for="project-type" class="required-field">Тип проєкту:</label>
                <select id="project-type" name="project-type" required>
                    <option value="">-- Оберіть тип --</option>
                    <option value="Житловий будинок">Житловий будинок</option>
                    <option value="Котедж">Котедж</option>
                    <option value="Дачний будинок">Дачний будинок</option>
                    <option value="Гараж">Гараж</option>
                    <option value="Інше">Інше</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="status" class="required-field">Статус проєкту:</label>
                <select id="status" name="status" required onchange="toggleDateFields()">
                    <option value="Active" selected>Почато</option>
                    <option value="Pending">В процесі</option>
                    <option value="Completed">Завершено</option>
                </select>
            </div>
            
            <h2 class="section-title">Характеристики</h2>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="square" class="required-field">Площа (м²):</label>
                    <input type="number" id="square" name="square" min="1" required>
                </div>
                
                <div class="form-group">
                    <label for="floors" class="required-field">Кількість поверхів:</label>
                    <input type="number" id="floors" name="floors" min="1" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="foundation">Тип фундаменту:</label>
                    <select id="foundation" name="foundation">
                        <option value="">-- Оберіть тип --</option>
                        <option value="Стрічковий">Стрічковий</option>
                        <option value="Цілий">Цілий</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="floor">Підлога:</label>
                    <select id="floor" name="floor">
                        <option value="">-- Оберіть тип --</option>
                        <option value="Цемент">Цемент</option>
                        <option value="Покриття плитою">Покриття плитою</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="walls">Матеріал стін:</label>
                    <select id="walls" name="walls">
                        <option value="">-- Оберіть тип --</option>
                        <option value="Цегла">Цегла</option>
                        <option value="Газоблок">Газобетон</option>
                        <option value="Бетон">Бетон</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="roof">Тип даху:</label>
                    <select id="roof" name="roof">
                        <option value="">-- Оберіть тип --</option>
                        <option value="Плоский">Плоский</option>
                        <option value="Двосхилий">Двосхилий</option>
                        <option value="Напів Вальмовий">Напів Вальмовий</option>
                    </select>
                </div>
            </div>
            
            <h2 class="section-title">Дати</h2>
            
            <div class="form-row date-fields">
                <div class="form-group date-field visible" id="start-date-field">
                    <label for="start_date" class="required-field">Дата початку</label>
                    <input type="date" id="start_date" name="start_date">
                </div>
                
                <div class="form-group date-field" id="end-date-field">
                    <label for="end_date">Дата завершення</label>
                    <input type="date" id="end_date" name="end_date">
                </div>
            </div>
            
            <div class="form-buttons">
                <button type="button" class="cancel-btn" onclick="window.location.href='estimates.php'">Скасувати</button>
                <button type="submit" class="save-btn">Створити проєкт</button>
            </div>
        </form>
    </div>

    <script>
        function toggleDateFields() {
            const status = document.getElementById('status').value;
            const startDateField = document.getElementById('start-date-field');
            const endDateField = document.getElementById('end-date-field');
            
            startDateField.classList.add('visible');
            
            if (status === 'Completed') {
                endDateField.classList.add('visible');
                document.getElementById('start_date').required = true;
                document.getElementById('end_date').required = true;
                document.querySelector('#start-date-field label').classList.add('required-field');
                document.querySelector('#end-date-field label').classList.add('required-field');
            } else {
                endDateField.classList.remove('visible');
                document.getElementById('end_date').required = false;
                document.querySelector('#end-date-field label').classList.remove('required-field');
                
                if (status === 'Active' || status === 'Pending') {
                    document.getElementById('start_date').required = true;
                    document.querySelector('#start-date-field label').classList.add('required-field');
                } else {
                    document.getElementById('start_date').required = false;
                    document.querySelector('#start-date-field label').classList.remove('required-field');
                }
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            toggleDateFields();
        });
    </script>
</body>
</html>