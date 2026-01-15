<?php
session_start();

// Обробка POST-запиту для входу
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header("Content-Type: application/json");
    
    $serverName = "WIN-C0REURL4NB2\\MSSQLSERVER01";
    $database = "form_data";

    try {
        $conn = new PDO(
            "sqlsrv:Server=$serverName;Database=$database;TrustServerCertificate=1;Encrypt=1",
            null,
            null
        );
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Пошук користувача за логіном
        $stmt = $conn->prepare("
            SELECT user_id, username, password_hash, role 
            FROM dbo.Users 
            WHERE username = :login
        ");
        $stmt->bindParam(':login', $_POST["login"]);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            echo json_encode(["success" => false, "message" => "Користувача з таким логіном не знайдено"]);
            exit;
        }
        
        // Перевірка пароля
        if (password_verify($_POST["password"], $user['password_hash'])) {
            // Успішна автентифікація
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['authenticated'] = true;
            
            echo json_encode([
                "success" => true,
                "message" => "Успішний вхід",
                "redirect" => "estimates.php"
            ]);
            exit;
        } else {
            echo json_encode(["success" => false, "message" => "Невірний пароль"]);
            exit;
        }
    } catch (PDOException $e) {
        echo json_encode([
            "success" => false, 
            "message" => "Помилка бази даних: " . $e->getMessage()
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Увійти | Будівельний калькулятор</title>
    <style>
        body {
            background: linear-gradient(135deg, #cae4fc, #061853);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .auth-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 40px rgba(1, 11, 46, 0.671);
            max-width: 450px;
            width: 100%;
            padding: 30px;
            animation: fadeIn 0.8s ease-out;
        }

        .auth-header {
            text-align: center;
            position: relative;
            margin-bottom: 35px;
        }

        .auth-header h1 {
            font-size: 2rem;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .auth-header p {
            color: #555;
            font-size: 1.2rem;
        }

        .logo {
            position: absolute;
            top: -15px;
            left: 10%;
            transform: translateX(-80%);
            width: 60px;
            height: 60px;
            background: #eb7609;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
        }

        .auth-form .input-group {
            margin-bottom: 1.5rem;
        }

        .input-group label {
            display: block;
            margin-bottom: 0.8rem;
            font-weight: 600;
            color: #2c3e50;
        }

        .input-group input {
            width: 90%;
            padding: 0.8rem;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 1rem;
            transition: 0.3s;
        }

        .input-group input:focus {
            border-color: #eb7609;
            outline: none;
            box-shadow: 0 0 5px rgba(235, 118, 9, 0.3);
        }

        .forgot-password {
            display: block;
            text-align: right;
            font-size: 1rem;
            color: #eb7609;
            text-decoration: none;
            margin-bottom: 1.2rem;
        }

        .forgot-password:hover {
            text-decoration: underline;
        }

        .auth-button {
            background: linear-gradient(135deg, #eb7609, #d2690e);
            color: white;
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 8px rgba(235, 118, 9, 0.3);
        }

        .auth-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(235, 118, 9, 0.4);
        }

        .register-link {
            text-align: center;
            margin-top: 1.5rem;
            color: #555;
        }

        .register-link a {
            color: #eb7609;
            text-decoration: none;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 480px) {
            .auth-container {
                padding: 20px;
            }

            .logo {
                width: 50px;
                height: 50px;
                font-size: 1.3rem;
                top: -50px;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-header">
            <div class="logo">
                <i class="fas fa-calculator"></i>
            </div>
            <h1>Увійти</h1>
            <p>Введіть свої дані для входу в обліковий запис</p>
        </div>

        <form class="auth-form" method="POST">
            <div class="input-group">
                <label for="login">Логін</label>
                <input type="text" id="login" name="login" required>
            </div>

            <div class="input-group">
                <label for="password">Пароль</label>
                <input type="password" id="password" name="password" required>
            </div>

            <a href="forgot-password.html" class="forgot-password">Забули пароль?</a>
            <button type="submit" class="auth-button">Далі</button>
            <p class="register-link">
                Ще не маєте акаунта? <a href="register.html">Створити обліковий запис</a>
            </p>
        </form>
    </div>

    <script>
        document.querySelector('.auth-form').addEventListener('submit', async function(event) {
            event.preventDefault();
            
            const formData = new FormData(this);
            const submitButton = this.querySelector('.auth-button');
            
            // Відображення індикатора завантаження
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Обробка...';
            
            try {
                const response = await fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    window.location.href = data.redirect;
                } else {
                    alert(data.message || 'Помилка входу');
                    submitButton.disabled = false;
                    submitButton.textContent = 'Далі';
                }
            } catch (error) {
                console.error('Помилка:', error);
                alert('Сталася помилка під час входу');
                submitButton.disabled = false;
                submitButton.textContent = 'Далі';
            }
        });
    </script>
</body>
</html>