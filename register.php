<?php
header(header: "Content-Type: application/json");
header(header: "Access-Control-Allow-Origin: *");
header(header: "Access-Control-Allow-Methods: POST, OPTIONS");
header(header: "Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$json = file_get_contents(filename: 'php://input');
$data = json_decode(json: $json, associative: true);

if (!$data) {
    echo json_encode(value: ["success" => false, "message" => "Invalid JSON data"]);
    exit;
}

// Перевіряємо обов'язкові поля: тепер включаємо username
if (!isset($data["username"], $data["email"], $data["password"])) {
    echo json_encode(value: ["success" => false, "message" => "Missing username, email or password"]);
    exit;
}

$serverName = "WIN-C0REURL4NB2\\MSSQLSERVER01";
$database = "form_data";

try {
    $conn = new PDO(
        dsn: "sqlsrv:Server=$serverName;Database=$database;TrustServerCertificate=1;Encrypt=1",
        username: null,
        password: null
    );
    $conn->setAttribute(attribute: PDO::ATTR_ERRMODE, value: PDO::ERRMODE_EXCEPTION);
    
    // Починаємо транзакцію
    $conn->beginTransaction();
    
    // Отримуємо наступний ID
    $stmt = $conn->query(query: "SELECT MAX(user_id) AS max_id FROM dbo.Users WITH (UPDLOCK)");
    $result = $stmt->fetch(mode: PDO::FETCH_ASSOC);
    $userId = ($result['max_id'] ?? 0) + 1;

    // Використовуємо username з вхідних даних
    $username = $data["username"];
    
    // Значення за замовчуванням
    $role = $data["role"] ?? 'user';
    $company = $data["company"] ?? null;
    $contactInfo = $data["contact_info"] ?? null;
    
    // Хешування пароля
    $passwordHash = password_hash(password: $data["password"], algo: PASSWORD_DEFAULT);

    // Вставка даних
    $stmt = $conn->prepare(query: "
        INSERT INTO dbo.Users 
        (user_id, username, password_hash, email, role, company, contact_info, created_at, updated_at)
        VALUES (:user_id, :username, :password_hash, :email, :role, :company, :contact_info, GETDATE(), GETDATE())
    ");
    
    $stmt->bindParam(param: ':user_id', var: $userId, type: PDO::PARAM_INT);
    $stmt->bindParam(param: ':username', var: $username);
    $stmt->bindParam(param: ':password_hash', var: $passwordHash);
    $stmt->bindParam(param: ':email', var: $data["email"]);
    $stmt->bindParam(param: ':role', var: $role);
    $stmt->bindParam(param: ':company', var: $company);
    $stmt->bindParam(param: ':contact_info', var: $contactInfo);
    
    $stmt->execute();
    
    // Підтверджуємо транзакцію
    $conn->commit();

    echo json_encode(value: ["success" => true]);
} catch (PDOException $e) {
    // Відкат транзакції при помилці
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(value: [
        "success" => false, 
        "message" => "Database error: " . $e->getMessage(),
        "error_details" => $e->errorInfo ?? []
    ]);
}