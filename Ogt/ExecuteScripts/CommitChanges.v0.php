<?php
session_start();
header('Content-Type: application/json');
require_once($_SERVER['DOCUMENT_ROOT'] . "/IVC/coreX.php");
require_once($_SERVER['DOCUMENT_ROOT'] . "/IVC/Scripts/Library.v2.2.php");

class ApiResponse {
    public static function json($data, $code = 200) {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error($message, $code = 500) {
        self::json(['error' => $message, 'code' => $code], $code);
    }
}

class Logger {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function logAction($userId, $actionType, $drawingsTechnologiesId = null, $technologiesOperationsId = null, $value = null) {
        $actionId = match ($actionType) {
            'createTechnology' => 7,
            'updateTechnology' => 8,
            'deleteTechnology' => 9,
            'createOperation' => 10,
            'updateOperation' => 11,
            'deleteOperation' => 12,
            'saveDataSuccess' => 18,
            'saveDataError' => 19,
            default => 0,
        };

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO ogt.\"users_log\" 
                (action_id, value, user_id, technologies_operations_id, drawings_technologies_id, date)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$actionId, $value, $userId, $technologiesOperationsId, $drawingsTechnologiesId]);
        } catch (Exception $e) {
            // Логируем, но не прерываем выполнение
        }
    }
}

class TechnologyService {
    private $pdo;
    private $logger;
    private $userId;

    public function __construct($pdo, $userId, $logger) {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->userId = $userId;
    }

    // Создание технологии
    public function createTechnology($technology) {
        try {
            // 1. Генерация кода
            $prefix = $technology['content']['formValues']['prefix'];
            $stmt = $this->pdo->prepare("SELECT ogt.get_next_doc_number(:prefix)");
            $stmt->execute([':prefix' => $prefix]);
            $nextCode = $stmt->fetchColumn();

            // 2. Получаем имя префикса
            $stmt = $this->pdo->prepare("SELECT name FROM ogt.technologies_prefix WHERE prefix = :prefix LIMIT 1");
            $stmt->execute([':prefix' => $prefix]);
            $prefixName = $stmt->fetchColumn();

            // 3. Вставляем технологию
            $stmt = $this->pdo->prepare("
                INSERT INTO ogt.technologies (code, name) VALUES (:code, :name) RETURNING id
            ");
            $stmt->execute([
                ':code' => $prefix . sprintf("%05d", $nextCode),
                ':name' => $prefixName,
            ]);
            $technologyId = $stmt->fetchColumn();

            // 4. Связь с чертежом
            $drawingExternalCode = $technology['drawing']['externalCode'];
            $stmt = $this->pdo->prepare("
                INSERT INTO ogt.drawings_technologies 
                (drawing_id, technology_id) 
                VALUES (
                    (SELECT id FROM ogt.drawings WHERE TRIM(UPPER(external_code)) = TRIM(UPPER(:external_code))),
                    :technology_id
                ) RETURNING id
            ");
            $stmt->execute([':external_code' => $drawingExternalCode, ':technology_id' => $technologyId]);
            $drawingsTechnologiesId = $stmt->fetchColumn();

            // 5. Запись пользователя
            $stmt = $this->pdo->prepare("
                INSERT INTO ogt.technologies_users 
                (drawings_technologies_id, user_id, creation_date, last_modified)
                VALUES (:drawings_technologies_id, :user_id, NOW(), NOW())
            ");
            $stmt->execute([
                ':drawings_technologies_id' => $drawingsTechnologiesId,
                ':user_id' => $this->userId,
            ]);

            // 6. Создаём операции
            $response = [];
            foreach ($technology['children'] as $operation) {
                if ($operation['content']['isNewRecord']) {
                    $response[] = $this->createOperation($operation, $drawingsTechnologiesId);
                }
            }

            $this->logger->logAction($this->userId, 'createTechnology', $drawingsTechnologiesId);

            return ['status' => 'created', 'id' => $technologyId];
        } catch (Exception $e) {
            $this->logger->logAction($this->userId, 'saveDataError', null, null, $e->getMessage());
            throw $e;
        }
    }

    // Обновление технологии
    public function updateTechnology($technology) {
        try {
            $proxyId = $technology['proxy']['proxyId'];
            $ivHex = $technology['proxy']['ivHex'];
            $keyHex = $technology['proxy']['keyHex'];
            $drawingsTechnologiesId = SystemHelper::decrypt($proxyId, $keyHex, $ivHex);

            $operationsToCreate = array_filter($technology['children'], fn($op) => $op['content']['isNewRecord'] && !$op['content']['isDeleted']);
            $operationsToUpdate = array_filter($technology['children'], fn($op) => !$op['content']['isNewRecord'] && $op['content']['isUpdated']);
            $operationsToDelete = array_filter($technology['children'], fn($op) => $op['content']['isDeleted']);

            $response = [];

            foreach ($operationsToCreate as $op) {
                $response[] = $this->createOperation($op, $drawingsTechnologiesId);
            }

            foreach ($operationsToUpdate as $op) {
                $response[] = $this->updateOperation($op, $technology);
            }

            foreach ($operationsToDelete as $op) {
                $response[] = $this->deleteOperation($op, $technology);
            }

            return ['status' => 'updated', 'id' => $drawingsTechnologiesId, 'details' => $response];
        } catch (Exception $e) {
            throw $e;
        }
    }

    // Удаление технологии
    public function deleteTechnology($technology) {
        try {
            $proxyId = $technology['proxy']['proxyId'];
            $ivHex = $technology['proxy']['ivHex'];
            $keyHex = $technology['proxy']['keyHex'];
            $drawingsTechnologiesId = SystemHelper::decrypt($proxyId, $keyHex, $ivHex);

            foreach ($technology['children'] as $operation) {
                $this->deleteOperation($operation, $technology);
            }

            // 1. Удаляем технологию
            $stmt = $this->pdo->prepare("UPDATE ogt.drawings_technologies SET is_deleted = true WHERE id = ? AND is_deleted = false");
            $stmt->execute([$drawingsTechnologiesId]);

            $this->logger->logAction($this->userId, 'deleteTechnology', $drawingsTechnologiesId);

            return ['status' => 'deleted', 'id' => $drawingsTechnologiesId];
        } catch (Exception $e) {
            throw $e;
        }
    }

    // Создание операции
    private function createOperation($operation, $drawingsTechnologiesId) {
        try {
            // 1. Получаем operation_id
            $operationCode = $operation['content']['formValues']['operationCode']['code'];
            $operationName = $operation['content']['formValues']['operationCode']['name'];
            $operationId = OgtHelper::GetOperationId($this->pdo, $operationCode, $operationName);

            // 2. Вставляем операцию
            $stmt = $this->pdo->prepare("
                INSERT INTO ogt.technologies_operations 
                (drawings_technologies_id, operation_id, order_number) 
                VALUES (?, ?, ?) RETURNING id
            ");
            $stmt->execute([
                $drawingsTechnologiesId,
                $operationId,
                $operation['content']['formValues']['orderNumber']
            ]);
            $technologiesOperationsId = $stmt->fetchColumn();

            // 3. Вставляем параметры операции
            $stmt = $this->pdo->prepare("
                INSERT INTO ogt.operations_parameters 
                (shop_number, area_number, document, technologies_operations_id, description)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $operation['content']['formValues']['shopNumber'],
                $operation['content']['formValues']['areaNumber'],
                $operation['content']['formValues']['document'],
                $technologiesOperationsId,
                $operation['content']['formValues']['operationDescription'] ?? $operation['content']['formValues']['description']
            ]);

            // 4. Вставляем job-данные
            $jobCode = $operation['content']['formValues']['jobCode'] ?? null;
            if ($jobCode) {
                $jobId = OgtHelper::GetJobId($this->pdo, $jobCode['code'], $jobCode['name']);
                $stmt = $this->pdo->prepare("
                    INSERT INTO ogt.operations_jobs 
                    (technologies_operations_id, grade, working_conditions, number_of_workers, number_of_processed_parts, labor_effort, job_code_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $technologiesOperationsId,
                    $operation['content']['formValues']['grade'],
                    $operation['content']['formValues']['workingConditions'],
                    $operation['content']['formValues']['numberOfWorkers'],
                    $operation['content']['formValues']['numberOfProcessedParts'],
                    $operation['content']['formValues']['laborEffort'],
                    $jobId
                ]);
            }

            // 5. Вставляем материалы
            $materials = $operation['content']['formValues']['materialCode'] ?? [];
            if (!empty($materials)) {
                $materialStmt = $this->pdo->prepare("
                    INSERT INTO ogt.operations_materials 
                    (technologies_operations_id, material_code_id, material_mass)
                    VALUES (?, ?, ?)
                ");
                
                foreach ($materials as $material) {
                    // Получаем ID материала по коду и названию
                    $materialId = OgtHelper::GetMaterialId($this->pdo, $material['code'], $material['name']);
                    
                    if ($materialId) {
                        // Вставляем материал с его массой
                        $materialStmt->execute([
                            $technologiesOperationsId,
                            $materialId,
                            $material['mass'] ?? 0.0
                        ]);
                    }
                }
            }

            // 6. Вставляем комплектующие
            $components = $operation['content']['formValues']['componentCode'] ?? [];
            if (!empty($components)) {
                $componentStmt = $this->pdo->prepare("
                    INSERT INTO ogt.operations_components 
                    (technologies_operations_id, component_code_id, quantity)
                    VALUES (?, ?, ?)
                ");
                
                foreach ($components as $component) {
                    // Получаем ID комплектующего по коду и названию
                    $componentId = OgtHelper::GetComponentId($this->pdo, $component['code'], $component['name']);
                    
                    if ($componentId) {
                        // Вставляем комплектующее с количеством
                        $componentStmt->execute([
                            $technologiesOperationsId,
                            $componentId,
                            $component['quantity'] ?? 0
                        ]);
                    }
                }
            }

            // 5. Логируем
            $this->logger->logAction(
                $this->userId,
                'createOperation',
                $drawingsTechnologiesId,
                $technologiesOperationsId
            );

            return ['status' => 'created', 'id' => $technologiesOperationsId];
        } catch (Exception $e) {
            $this->logger->logAction(
                $this->userId,
                'saveDataError',
                $drawingsTechnologiesId,
                $technologiesOperationsId,
                $e->getMessage()
            );
            return OgtHelper::GetResponseCode(500, $operation, $technology, 'createOperation');
        }
    }

    // Обновление операции
    private function updateOperation($operation, $technology) {
        try {
            $proxyTOId = $operation['proxy']['proxyTOId'];
            $proxyDTId = $operation['proxy']['proxyDTId'];
            $ivHex = $technology['proxy']['ivHex'];
            $keyHex = $technology['proxy']['keyHex'];
            $technologiesOperationsId = SystemHelper::decrypt($proxyTOId, $keyHex, $ivHex);
            $drawingsTechnologiesId = SystemHelper::decrypt($proxyDTId, $keyHex, $ivHex);

            $changedValues = $operation['content']['changedValues'];
            if (empty($changedValues)) {
                return OgtHelper::GetResponseCode(304, $operation, $technology, 'updateOperation');
            }

            // 0. Обновление order_number (orderNumber)
            if (isset($changedValues['orderNumber'])) {
                $value = (int) $changedValues['orderNumber'];
                $stmt = $this->pdo->prepare("UPDATE ogt.technologies_operations SET order_number = ? WHERE id = ? AND is_deleted = false");
                $stmt->execute([$value, $technologiesOperationsId]);
            }

            // 1. Обновление operation_id (operationCode)
            if (isset($changedValues['operationCode'])) {
                $value = $changedValues['operationCode'];
                $newOperationId = OgtHelper::GetOperationId($this->pdo, $value['code'], $value['name']);

                if ($newOperationId) {
                    $stmt = $this->pdo->prepare("UPDATE ogt.technologies_operations SET operation_id = ? WHERE id = ? AND is_deleted = false");
                    $stmt->execute([$newOperationId, $technologiesOperationsId]);
                }
            }

            // 2. Обновление параметров операции
            $stmtParts = [];
            $stmtParams = [];

            if (isset($changedValues['operationDescription'])) {
                $stmtParts[] = "description = ?";
                $stmtParams[] = $changedValues['operationDescription'];
            }

            if (isset($changedValues['shopNumber'])) {
                $stmtParts[] = "shop_number = ?";
                $stmtParams[] = $changedValues['shopNumber'];
            }

            if (isset($changedValues['areaNumber'])) {
                $stmtParts[] = "area_number = ?";
                $stmtParams[] = $changedValues['areaNumber'];
            }

            if (isset($changedValues['document'])) {
                $stmtParts[] = "document = ?";
                $stmtParams[] = $changedValues['document'];
            }

            if (!empty($stmtParts)) {
                $stmt = $this->pdo->prepare("
                    UPDATE ogt.operations_parameters 
                    SET " . implode(', ', $stmtParts) . " 
                    WHERE technologies_operations_id = ? AND
                        is_deleted = false
                ");
                $stmt->execute([...$stmtParams, $technologiesOperationsId]);
            }

            // 3. Обновление job_code_id (jobCode)
            if (isset($changedValues['jobCode'])) {
                $value = $changedValues['jobCode'];
                $newJobId = OgtHelper::GetJobId($this->pdo, $value['code'], $value['name']);

                if ($newJobId) {
                    $stmt = $this->pdo->prepare("
                        UPDATE ogt.operations_jobs 
                        SET job_code_id = ? 
                        WHERE technologies_operations_id = ? AND
                            is_deleted = false
                    ");
                    $stmt->execute([$newJobId, $technologiesOperationsId]);
                }
            }

            // 4. Обновление параметров job
            $stmtParts = [];
            $stmtParams = [];

            if (isset($changedValues['grade'])) {
                $stmtParts[] = "grade = ?";
                $stmtParams[] = $changedValues['grade'];
            }

            if (isset($changedValues['workingConditions'])) {
                $stmtParts[] = "working_conditions = ?";
                $stmtParams[] = $changedValues['workingConditions'];
            }

            if (isset($changedValues['numberOfWorkers'])) {
                $stmtParts[] = "number_of_workers = ?";
                $stmtParams[] = $changedValues['numberOfWorkers'];
            }

            if (isset($changedValues['numberOfProcessedParts'])) {
                $stmtParts[] = "number_of_processed_parts = ?";
                $stmtParams[] = $changedValues['numberOfProcessedParts'];
            }

            if (isset($changedValues['laborEffort'])) {
                $stmtParts[] = "labor_effort = ?";
                $stmtParams[] = $changedValues['laborEffort'];
            }

            if (!empty($stmtParts)) {
                $stmt = $this->pdo->prepare("
                    UPDATE ogt.operations_jobs 
                    SET " . implode(', ', $stmtParts) . " 
                    WHERE technologies_operations_id = ? AND
                        is_deleted = false
                ");
                $stmt->execute([...$stmtParams, $technologiesOperationsId]);
            }

            // 4.5. Обработка материалов
            $operationFormValues = $operation['content']['formValues'];
            $formMaterials = $operationFormValues['materialCode'] ?? [];
            
            // Получаем текущие материалы из БД (только активные)
            $currentMaterialsStmt = $this->pdo->prepare("
                SELECT om.id, om.material_code_id, m.code, m.name, om.material_mass 
                FROM ogt.operations_materials om
                JOIN ogt.materials m ON om.material_code_id = m.id
                WHERE om.technologies_operations_id = ? AND om.is_deleted = false
            ");
            $currentMaterialsStmt->execute([$technologiesOperationsId]);
            $currentMaterials = $currentMaterialsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Создаем индекс текущих материалов по комбинации кода и наименования
            $currentMaterialsByCodeName = [];
            foreach ($currentMaterials as $material) {
                $key = strtolower(trim($material['code'])) . '|' . strtolower(trim($material['name']));
                $currentMaterialsByCodeName[$key] = $material;
            }
            
            // Обрабатываем материалы из formValues
            foreach ($formMaterials as $material) {
                // Пропускаем материалы без кода или названия
                if (empty($material['code']) || empty($material['name'])) {
                    continue;
                }                
                //
                $key = strtolower(trim($material['code'])) . '|' . strtolower(trim($material['name']));                
                if (isset($currentMaterialsByCodeName[$key])) {
                    // Материал уже существует, проверяем нужно ли обновить массу
                    $currentMaterial = $currentMaterialsByCodeName[$key];
                    $newMass = $material['mass'] ?? 0.0;
                    
                    // Если масса изменилась, обновляем
                    if (abs(floatval($currentMaterial['material_mass']) - floatval($newMass)) > 0.0001) {
                        $updateStmt = $this->pdo->prepare("
                            UPDATE ogt.operations_materials 
                            SET material_mass = ? 
                            WHERE id = ?
                        ");
                        $updateStmt->execute([$newMass, $currentMaterial['id']]);
                    }
                    
                    // Удаляем из индекса, чтобы потом определить удаленные материалы
                    unset($currentMaterialsByCodeName[$key]);
                } else {
                    // Новый материал, добавляем
                    $materialCodeId = OgtHelper::GetMaterialId($this->pdo, $material['code'], $material['name']);
                    
                    if ($materialCodeId) {
                        $insertStmt = $this->pdo->prepare("
                            INSERT INTO ogt.operations_materials 
                            (technologies_operations_id, material_code_id, material_mass, is_deleted)
                            VALUES (?, ?, ?, false)
                        ");
                        $insertStmt->execute([
                            $technologiesOperationsId,
                            $materialCodeId,
                            $material['mass'] ?? 0.0
                        ]);
                    }
                }
            }
            
            // Обрабатываем удаленные материалы
            foreach ($currentMaterialsByCodeName as $material) {
                $updateStmt = $this->pdo->prepare("
                    UPDATE ogt.operations_materials 
                    SET is_deleted = true 
                    WHERE id = ?
                ");
                $updateStmt->execute([$material['id']]);
            }

            // 4.6. Обработка комплектующих
            $operationFormValues = $operation['content']['formValues'];
            $formComponents = $operationFormValues['componentCode'] ?? [];
            
            // Получаем текущие комплектующие из БД (только активные)
            $currentComponentsStmt = $this->pdo->prepare("
                SELECT oc.id, oc.component_code_id, c.code, c.name, oc.quantity
                FROM ogt.operations_components oc
                JOIN ogt.components c ON oc.component_code_id = c.id
                WHERE oc.technologies_operations_id = ? AND oc.is_deleted = false
            ");
            $currentComponentsStmt->execute([$technologiesOperationsId]);
            $currentComponents = $currentComponentsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Создаем индекс текущих комплектующих по комбинации кода и наименования
            $currentComponentsByCodeName = [];
            foreach ($currentComponents as $component) {
                $key = strtolower(trim($component['code'])) . '|' . strtolower(trim($component['name']));
                $currentComponentsByCodeName[$key] = $component;
            }
            
            // Обрабатываем комплектующие из formValues
            foreach ($formComponents as $component) {
                // Пропускаем комплектующие без кода или названия
                if (empty($component['code']) || empty($component['name'])) {
                    continue;
                }                
                //
                $key = strtolower(trim($component['code'])) . '|' . strtolower(trim($component['name']));                
                if (isset($currentComponentsByCodeName[$key])) {
                    // Комплектующее уже существует, проверяем нужно ли обновить количество
                    $currentComponent = $currentComponentsByCodeName[$key];
                    $newQuantity = $component['quantity'] ?? 0;
                    
                    // Если количество изменилось, обновляем
                    if ((int)$currentComponent['quantity'] !== (int)$newQuantity) {
                        $updateStmt = $this->pdo->prepare("
                            UPDATE ogt.operations_components
                            SET quantity = ? 
                            WHERE id = ?
                        ");
                        $updateStmt->execute([(int)$newQuantity, $currentComponent['id']]);
                    }
                    
                    // Удаляем из индекса, чтобы потом определить удаленные комплектующие
                    unset($currentComponentsByCodeName[$key]);
                } else {
                    // Новое комплектующее, добавляем
                    $componentCodeId = OgtHelper::GetComponentId($this->pdo, $component['code'], $component['name']);
                    
                    if ($componentCodeId) {
                        $insertStmt = $this->pdo->prepare("
                            INSERT INTO ogt.operations_components
                            (technologies_operations_id, component_code_id, quantity, is_deleted)
                            VALUES (?, ?, ?, false)
                        ");
                        $insertStmt->execute([
                            $technologiesOperationsId,
                            $componentCodeId,
                            $component['quantity'] ?? 0
                        ]);
                    }
                }
            }
            
            // Обрабатываем удаленные комплектующие
            foreach ($currentComponentsByCodeName as $component) {
                $updateStmt = $this->pdo->prepare("
                    UPDATE ogt.operations_components
                    SET is_deleted = true
                    WHERE id = ?
                ");
                $updateStmt->execute([$component['id']]);
            }

            // 5. Обновление даты
            $stmt = $this->pdo->prepare("
                UPDATE ogt.technologies_users 
                SET last_modified = NOW() 
                WHERE drawings_technologies_id = ? AND
                    is_deleted = false
            ");
            $stmt->execute([$drawingsTechnologiesId]);
            //
            $this->logger->logAction($this->userId, 'updateOperation', $drawingsTechnologiesId, $technologiesOperationsId);
            return OgtHelper::GetResponseCode(200, $operation, $technology, 'updateOperation');
        } catch (Exception $e) {
            $this->logger->logAction($this->userId, 'saveDataError', $drawingsTechnologiesId, $technologiesOperationsId, $e->getMessage());
            return OgtHelper::GetResponseCode(500, $operation, $technology, 'updateOperation');
        }
    }

    // Удаление операции
    private function deleteOperation($operation, $technology) {
        try {
            $proxyTOId = $operation['proxy']['proxyTOId'];
            $proxyDTId = $operation['proxy']['proxyDTId'];
            $ivHex = $technology['proxy']['ivHex'];
            $keyHex = $technology['proxy']['keyHex'];
            $technologiesOperationsId = SystemHelper::decrypt($proxyTOId, $keyHex, $ivHex);
            $drawingsTechnologiesId = SystemHelper::decrypt($proxyDTId, $keyHex, $ivHex);

            //запросы
            $this->pdo->prepare("UPDATE ogt.technologies_operations SET is_deleted = true WHERE id = ?")->execute([$technologiesOperationsId]);
            $this->pdo->prepare("UPDATE ogt.operations_parameters SET is_deleted = true WHERE technologies_operations_id = ?")->execute([$technologiesOperationsId]);
            $this->pdo->prepare("UPDATE ogt.operations_jobs SET is_deleted = true WHERE technologies_operations_id = ?")->execute([$technologiesOperationsId]);
            $this->pdo->prepare("UPDATE ogt.operations_materials SET is_deleted = true WHERE technologies_operations_id = ?")->execute([$technologiesOperationsId]);
            $this->pdo->prepare("UPDATE ogt.operations_components SET is_deleted = true WHERE technologies_operations_id = ?")->execute([$technologiesOperationsId]);
            
            //лог
            $this->logger->logAction($this->userId, 'deleteOperation', $drawingsTechnologiesId, $technologiesOperationsId);
            return OgtHelper::GetResponseCode(200, $operation, $technology, 'deleteOperation');
        } catch (Exception $e) {
            $this->logger->logAction($this->userId, 'saveDataError', null, null, $e->getMessage());
            return OgtHelper::GetResponseCode(500, $operation, $technology, 'deleteOperation');
        }
    }
}

// === Основной обработчик запроса ===
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ApiResponse::error('Метод не поддерживается', 405);
    }

    $postData = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        ApiResponse::error('Неверный JSON', 400);
    }

    $user = $postData['user'] ?? null;
    $technologies = $postData['technologies'] ?? [];

    if (!$user || empty($technologies)) {
        ApiResponse::error('Недостающие данные', 400);
    }

    // Подключение к БД
    $dbConn = ControlDBConnectPG::GetDb();
    $pdo = $dbConn->GetConn();
    if (!$pdo) {
        ApiResponse::error('Ошибка подключения к БД', 500);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Расшифровка UID
    $userId = SystemHelper::decrypt($user['UID'], $user['keyHex'], $user['ivHex']);

    // Инициализация
    $logger = new Logger($pdo);
    $techService = new TechnologyService($pdo, $userId, $logger);

    // Начинаем транзакцию
    $pdo->beginTransaction();

    $response = [];

    // === Обработка ===
    foreach ($technologies as $technology) {
        if ($technology['content']['isNewRecord']) {
            $response[] = $techService->createTechnology($technology);
        } elseif ($technology['content']['isDeleted']) {
            $response[] = $techService->deleteTechnology($technology);
        } else { /* if ($technology['content']['isUpdated']) */
            $response[] = $techService->updateTechnology($technology);
        }
    }

    // Фиксируем транзакцию
    $pdo->commit();
    $logger->logAction($userId, 'saveDataSuccess');

    // Ответ клиенту
    ApiResponse::json([
        'code' => 200,
        'response' => $response,
    ]);
} catch (Exception $e) {
    // Откатываем транзакцию
    $pdo->rollBack();
    $logger->logAction($userId, 'saveDataError', null, null, $e->getMessage());
    ApiResponse::error('Внутренняя ошибка сервера', 500);
}