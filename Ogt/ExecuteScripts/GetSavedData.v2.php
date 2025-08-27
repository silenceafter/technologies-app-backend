<?php
session_start();
header('Content-Type: application/json');
/*header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Access-Control-Allow-Credentials: true');*/
require_once($_SERVER['DOCUMENT_ROOT']."/IVC/coreX.php");
require_once $_SERVER['DOCUMENT_ROOT']."/IVC/Scripts/Library.v2.2.php";
//get_saved_data(technologies&operations)

$response = null;
//основное соединение
$db_object = ControlDBConnectPG::GetDb();

//данные
//$drawing_code = $_GET['code'];
$postData = file_get_contents('php://input');
$data_object = json_decode($postData, true);
//
$drawing_code = $data_object['drawing']['externalcode'];
$user = $data_object['user'];

//соединение Ogt
$conn = $db_object->GetConn();
$pdo = $conn;
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
//
$db = DBHelper::GetDatabase($pdo);
$db = trim(strtolower($db));

//запрос к базе данных
$data = new class($db, "schema", "table") {
    public $db;
    public $schema;
    public $table;

    function __construct($db, $schema, $table)
    {
        $this->db = $db;
        $this->schema = $schema;
        $this->table = $table;
    }
};

$repository = new PSQLRepository($data, $pdo);
$service = new PSQLService($data, $repository);

try {
    //данные пользователя
    $uid = $data_object['user']['UID'];
    $ivHex = $data_object['user']['ivHex'];
    $keyHex = $data_object['user']['keyHex'];
    $userId = SystemHelper::decrypt($uid, $keyHex, $ivHex);

    //sql
    $text = "
        SELECT *
        FROM (
            SELECT
                dt.drawings_technologies_id,
                dt.technology_id,
                dt.is_deleted,
                t.code AS label,
                t.name AS secondarylabel,
                tu.user_id,
                (
                    SELECT group_id
                    FROM ogt.technologies_prefix
                    WHERE SUBSTR(t.code, 1, 5) = prefix
                    LIMIT 1
                ),
                tu.creation_date,
                tu.last_modified,
                (
					SELECT external_code 
					FROM ogt.drawings 
					WHERE id = dt.drawing_id
				) AS drawings_externalcode
            FROM (
                SELECT 
                    id AS drawings_technologies_id,
                    technology_id,
                    drawing_id,
                    is_deleted
                FROM ogt.drawings_technologies
                WHERE drawing_id = (
                    SELECT id 
                    FROM ogt.drawings
                    WHERE external_code = :code
                )
            ) AS dt                    
            INNER JOIN ogt.technologies AS t
                ON dt.technology_id = t.id
            INNER JOIN ogt.technologies_users AS tu
                ON dt.drawings_technologies_id = tu.drawings_technologies_id
        ) AS joined
        WHERE is_deleted = false
        ORDER BY drawings_technologies_id";
    //
    $query = $pdo->prepare($text);
    $query->bindValue(':code', $drawing_code);
    $query->execute();
    $response_array = $query->fetchAll(PDO::FETCH_ASSOC);

    //ключи шифрования для id
    for($i = 0; $i < count($response_array); $i++)
    {
        $iv = SystemHelper::generateIv();
        $response_array[$i]['keyHex'] = bin2hex(openssl_random_pseudo_bytes(32));//32 байта для AES-256
        $response_array[$i]['ivHex'] = SystemHelper::encodeIv($iv);
    }
    $technologies = MUITreeItem::GetCustomTreeItemsTechnologies($response_array, $user);

    if (count($technologies) > 0) {
      /*for($i = 0; $i < count($technologies); $i++)
      {
        $drawings_technologies_id = $response_array[$i]['drawings_technologies_id'];
        //оригинал
        $text = "WITH grouped_components AS (
            SELECT
                op.technologies_operations_id,
                jsonb_agg(json_build_object(
                    'code', c.code,
                    'name', c.name,
                    'cnt', c.id,
                    'quantity', oc.quantity
                ) ORDER BY c.id) FILTER (WHERE c.id IS NOT NULL AND c.is_deleted = false AND oc.is_deleted = false) AS components
                FROM ogt.operations_parameters AS op
                LEFT JOIN ogt.operations_components AS oc
                    ON op.technologies_operations_id = oc.technologies_operations_id
                LEFT JOIN ogt.components AS c
                    ON oc.component_code_id = c.id
                GROUP BY op.technologies_operations_id
            ),        
            grouped_materials AS (
                SELECT 
                    op.technologies_operations_id,
                    jsonb_agg(json_build_object(
                        'code', m.code,
                        'name', m.name,
                        'cnt', m.id,
                        'mass', om.material_mass
                    ) ORDER BY m.id)
                        FILTER (WHERE m.id IS NOT NULL AND m.is_deleted = false AND om.is_deleted = false) AS materials
                    FROM ogt.operations_parameters AS op
                    LEFT JOIN ogt.operations_materials AS om
                        ON op.technologies_operations_id = om.technologies_operations_id
                    LEFT JOIN ogt.materials AS m
                        ON om.material_code_id = m.id
                    GROUP BY op.technologies_operations_id
                ),
            grouped_tooling AS (
                SELECT
                    op.technologies_operations_id,
                    jsonb_agg(jsonb_build_object(
                        'code', t.code,
                        'name', t.name,
                        'cnt', t.id
                    ) ORDER BY t.id) FILTER (WHERE t.id IS NOT NULL AND t.is_deleted = false AND ot.is_deleted = false) AS tooling
                FROM ogt.operations_parameters AS op
                LEFT JOIN ogt.operations_tooling AS ot
                    ON op.technologies_operations_id = ot.technologies_operations_id
                LEFT JOIN ogt.tooling AS t
                    ON ot.tooling_code_id = t.id
                GROUP BY op.technologies_operations_id	
            ),
            grouped_measuring_tools AS (
                SELECT
                    op.technologies_operations_id,
                    jsonb_agg(jsonb_build_object(
                        'code', mt.code,
                        'name', mt.name,
                        'cnt', mt.id
                    ) ORDER BY mt.id) FILTER (WHERE mt.id IS NOT NULL AND mt.is_deleted = false AND omt.is_deleted = false) AS measuring_tools
                FROM ogt.operations_parameters AS op
                LEFT JOIN ogt.operations_measuring_tools AS omt
                    ON op.technologies_operations_id = omt.technologies_operations_id
                LEFT JOIN ogt.measuring_tools AS mt
                    ON omt.measuring_tools_code_id = mt.id
                GROUP BY op.technologies_operations_id
            )
            SELECT
                op.technologies_operations_id,
                MIN(op.id) AS operations_parameters_id,
                MIN(oj.id) AS operations_jobs_id,                
                MIN(top.operation_id) AS operation_id,
                MIN(top.order_number) AS order_number,
                MIN((top.is_deleted)::INT)::BOOLEAN AS is_deleted,
                MIN(o.code) AS label,
                MIN(o.name) AS secondarylabel,
                MIN(op.shop_number) AS op_shop_number,
                MIN(op.area_number) AS op_area_number,
                MIN(op.document) AS op_document,
                MIN(op.description) AS operation_description,
                MIN(oj.grade) AS oj_grade,
                MIN(oj.working_conditions) AS oj_working_conditions,
                MIN(oj.number_of_workers) AS oj_number_of_workers,
                MIN(oj.number_of_processed_parts) AS oj_number_of_processed_parts,
                MIN(oj.labor_effort) AS oj_labor_effort,
                MIN(j.id) AS job_id,
                MIN(j.code) AS job_code,
                MIN(j.name) AS job_name,
                gt.tooling,
                gm.materials,
                gc.components,
                gmt.measuring_tools
            FROM (
                SELECT
                    id AS drawings_technologies_id
                FROM ogt.drawings_technologies AS dt
                WHERE id = :drawings_technologies_id AND is_deleted = false
            ) AS dtj
            INNER JOIN ogt.technologies_operations AS top
                ON dtj.drawings_technologies_id = top.drawings_technologies_id
            INNER JOIN ogt.operations AS o
                ON top.operation_id = o.id
            INNER JOIN ogt.operations_parameters AS op
                ON top.id = op.technologies_operations_id
            INNER JOIN ogt.operations_jobs AS oj
                ON op.technologies_operations_id = oj.technologies_operations_id
            INNER JOIN ogt.jobs AS j
                ON oj.job_code_id = j.id
            LEFT JOIN grouped_tooling AS gt
                ON gt.technologies_operations_id = op.technologies_operations_id
            LEFT JOIN grouped_materials AS gm
		        ON gm.technologies_operations_id = op.technologies_operations_id
            LEFT JOIN grouped_components AS gc
	            ON gc.technologies_operations_id = op.technologies_operations_id
            LEFT JOIN grouped_measuring_tools AS gmt
	            ON gmt.technologies_operations_id = op.technologies_operations_id
            WHERE top.is_deleted = false AND
                op.is_deleted = false AND
                oj.is_deleted = false AND				
				o.is_deleted = false AND
                j.is_deleted = false
            GROUP BY
                op.technologies_operations_id,
                gt.tooling,
                gm.materials,
                gc.components,
                gmt.measuring_tools
	        ORDER BY op.technologies_operations_id";
                
        //второй вариант
        $text = "SELECT DISTINCT ON (op.technologies_operations_id)
                op.technologies_operations_id,
                op.id AS operations_parameters_id,
                oj.id AS operations_jobs_id,                
                top.operation_id AS operation_id,
                top.order_number AS order_number,
                (top.is_deleted)::BOOLEAN AS is_deleted,
                o.code AS label,
                o.name AS secondarylabel,	
                op.shop_number AS op_shop_number,
                op.area_number AS op_area_number,
                op.document AS op_document,
                op.description AS operation_description,
                oj.grade AS oj_grade,
                oj.working_conditions AS oj_working_conditions,
                oj.number_of_workers AS oj_number_of_workers,
                oj.number_of_processed_parts AS oj_number_of_processed_parts,
                oj.labor_effort AS oj_labor_effort,
                j.id AS job_id,
                j.code AS job_code,
                j.name AS job_name
            FROM (
                SELECT
                    id AS drawings_technologies_id
                FROM ogt.drawings_technologies AS dt
                WHERE id = :drawings_technologies_id AND is_deleted = false
            ) AS dtj
            INNER JOIN ogt.technologies_operations AS top
                ON dtj.drawings_technologies_id = top.drawings_technologies_id
            INNER JOIN ogt.operations AS o
                ON top.operation_id = o.id
            INNER JOIN ogt.operations_parameters AS op
                ON top.id = op.technologies_operations_id
            INNER JOIN ogt.operations_jobs AS oj
                ON op.technologies_operations_id = oj.technologies_operations_id
            INNER JOIN ogt.jobs AS j
                ON oj.job_code_id = j.id
            WHERE top.is_deleted = false AND
                op.is_deleted = false AND
                oj.is_deleted = false AND				
                o.is_deleted = false AND
                j.is_deleted = false

            ORDER BY op.technologies_operations_id, op.id";
        //
        $query = $pdo->prepare($text);
        $query->bindValue(':drawings_technologies_id', $drawings_technologies_id);
        $query->execute();
        $response_array_o = $query->fetchAll(PDO::FETCH_ASSOC);

        $ids = array_column($response_array_o, 'technologies_operations_id');
        $intIds = array_map(function($id) {
            return validateAndCastInt($id, 'technologies_operations_id');
        }, $ids);
        $placeholders = implode(',', array_fill(0, count($intIds), '?'));

        //добавить дополнительные данные
  
            //components
            $text = "
                SELECT
                    c.id AS cnt,
                    c.code,
                    c.name,
                    oc.quantity
                FROM ogt.operations_parameters AS op
                LEFT JOIN ogt.operations_components AS oc
                    ON op.technologies_operations_id = oc.technologies_operations_id
                LEFT JOIN ogt.components AS c
                    ON oc.component_code_id = c.id
                WHERE op.technologies_operations_id IN ($placeholders) AND 
                    c.is_deleted = false AND 
                    oc.is_deleted = false
                ORDER BY c.id";
            //
            $query = $pdo->prepare($text);
            $query->execute($intIds);
            $response_array_components = $query->fetchAll(PDO::FETCH_ASSOC);
            $gg = 1;
            //$array = array_map(fn($measuringTool) => ['components' => 'value', ...$item], $array);
            //$response_item['components'] = json_encode($response_array_components, JSON_UNESCAPED_UNICODE);
        
            $response_array_o[0]['components'] = null;
        

        $technologies[$i] = MUITreeItem::GetCustomTreeItemsOperations($response_array_o, $technologies[$i]);
      }*/

        // 1. Собираем все ID технологий
        $drawingsTechnologiesIds = array_column($response_array, 'drawings_technologies_id');
        $intIds = array_map(function($id) {
            return validateAndCastInt($id, 'drawings_technologies_id');
        }, $drawingsTechnologiesIds);
        
        // 2. Проверяем, есть ли ID для запроса
        if (empty($intIds)) {
            //return $technologies;
            echo json_encode($technologies, JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        // 3. Создаем placeholders для запроса
        $placeholders = implode(',', array_fill(0, count($intIds), '?'));

        // 4. Основной запрос для всех технологий сразу
        $text = "
            SELECT DISTINCT ON (op.technologies_operations_id)
                op.technologies_operations_id,
                op.id AS operations_parameters_id,
                oj.id AS operations_jobs_id,                
                top.operation_id AS operation_id,
                top.order_number AS order_number,
                (top.is_deleted)::BOOLEAN AS is_deleted,
                o.code AS label,
                o.name AS secondarylabel,	
                op.shop_number AS op_shop_number,
                op.area_number AS op_area_number,
                op.document AS op_document,
                op.description AS operation_description,
                oj.grade AS oj_grade,
                oj.working_conditions AS oj_working_conditions,
                oj.number_of_workers AS oj_number_of_workers,
                oj.number_of_processed_parts AS oj_number_of_processed_parts,
                oj.labor_effort AS oj_labor_effort,
                j.id AS job_id,
                j.code AS job_code,
                j.name AS job_name,
                dtj.drawings_technologies_id
            FROM (
                SELECT
                    id AS drawings_technologies_id
                FROM ogt.drawings_technologies AS dt
                WHERE id IN ($placeholders) AND is_deleted = false
            ) AS dtj
            INNER JOIN ogt.technologies_operations AS top
                ON dtj.drawings_technologies_id = top.drawings_technologies_id
            INNER JOIN ogt.operations AS o
                ON top.operation_id = o.id
            INNER JOIN ogt.operations_parameters AS op
                ON top.id = op.technologies_operations_id
            INNER JOIN ogt.operations_jobs AS oj
                ON op.technologies_operations_id = oj.technologies_operations_id
            INNER JOIN ogt.jobs AS j
                ON oj.job_code_id = j.id
            WHERE top.is_deleted = false AND
                op.is_deleted = false AND
                oj.is_deleted = false AND				
                o.is_deleted = false AND
                j.is_deleted = false
            ORDER BY op.technologies_operations_id, op.id";
        
        // 5. Выполняем запрос
        $query = $pdo->prepare($text);
        $query->execute($intIds);
        $response_array_o = $query->fetchAll(PDO::FETCH_ASSOC);
        
        // 6. Если операций нет, возвращаем технологии без изменений
        if (empty($response_array_o)) {
            echo json_encode($technologies, JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        // 7. Собираем все ID операций для подзапросов
        $operationIds = array_column($response_array_o, 'technologies_operations_id');
        $operationIntIds = array_map(function($id) {
            return validateAndCastInt($id, 'technologies_operations_id');
        }, $operationIds);
        
        // 8. Создаем placeholders для подзапросов
        $operationPlaceholders = implode(',', array_fill(0, count($operationIntIds), '?'));
        
        // 9. Запрос для компонентов
        $componentsText = "
            SELECT
                op.technologies_operations_id,
                c.id AS cnt,
                c.code,
                c.name,
                oc.quantity
            FROM ogt.operations_parameters AS op
            LEFT JOIN ogt.operations_components AS oc
                ON op.technologies_operations_id = oc.technologies_operations_id
            LEFT JOIN ogt.components AS c
                ON oc.component_code_id = c.id
            WHERE op.technologies_operations_id IN ($operationPlaceholders) AND 
                c.is_deleted = false AND 
                oc.is_deleted = false
            ORDER BY op.technologies_operations_id, c.id";
        //
        $componentsQuery = $pdo->prepare($componentsText);
        $componentsQuery->execute($operationIntIds);
        $componentsResults = $componentsQuery->fetchAll(PDO::FETCH_ASSOC);
        
        // 10. Группируем компоненты по технологическим операциям
        $componentsByOperation = [];
        foreach ($componentsResults as $component) {
            $opId = $component['technologies_operations_id'];
            if (!isset($componentsByOperation[$opId])) {
                $componentsByOperation[$opId] = [];
            }
            $componentsByOperation[$opId][] = [
                'cnt' => $component['cnt'],
                'code' => $component['code'],
                'name' => $component['name'],
                'quantity' => $component['quantity']
            ];
        }
        
        // 11. Запрос для материалов
        $materialsText = "
            SELECT
                op.technologies_operations_id,
                m.id AS cnt,
                m.code,
                m.name,
                om.material_mass
            FROM ogt.operations_parameters AS op
            LEFT JOIN ogt.operations_materials AS om
                ON op.technologies_operations_id = om.technologies_operations_id
            LEFT JOIN ogt.materials AS m
                ON om.material_code_id = m.id
            WHERE op.technologies_operations_id IN ($operationPlaceholders) AND 
                m.is_deleted = false AND 
                om.is_deleted = false
            ORDER BY op.technologies_operations_id, m.id";
        //
        $materialsQuery = $pdo->prepare($materialsText);
        $materialsQuery->execute($operationIntIds);
        $materialsResults = $materialsQuery->fetchAll(PDO::FETCH_ASSOC);

        // 12. Группируем материалы по технологическим операциям
        $materialsByOperation = [];
        foreach ($materialsResults as $material) {
            $opId = $material['technologies_operations_id'];
            if (!isset($materialsByOperation[$opId])) {
                $materialsByOperation[$opId] = [];
            }
            $materialsByOperation[$opId][] = [
                'cnt' => $material['cnt'],
                'code' => $material['code'],
                'name' => $material['name'],
                'mass' => $material['material_mass']
            ];
        }

        // 13. Запрос для оснастки
        $toolingText = "
            SELECT
                op.technologies_operations_id,
                t.id AS cnt,
                t.code,
                t.name
            FROM ogt.operations_parameters AS op
            LEFT JOIN ogt.operations_tooling AS ot
                ON op.technologies_operations_id = ot.technologies_operations_id
            LEFT JOIN ogt.tooling AS t
                ON ot.tooling_code_id = t.id
            WHERE op.technologies_operations_id IN ($operationPlaceholders) AND 
                t.is_deleted = false AND
                ot.is_deleted = false
            ORDER BY op.technologies_operations_id, t.id";
        //
        $toolingQuery = $pdo->prepare($toolingText);
        $toolingQuery->execute($operationIntIds);
        $toolingResults = $toolingQuery->fetchAll(PDO::FETCH_ASSOC);

        // 14. Группируем оснастку по технологическим операциям
        $toolingByOperation = [];
        foreach ($toolingResults as $item) {
            $opId = $item['technologies_operations_id'];
            if (!isset($toolingByOperation[$opId])) {
                $toolingByOperation[$opId] = [];
            }
            $toolingByOperation[$opId][] = [
                'cnt' => $item['cnt'],
                'code' => $item['code'],
                'name' => $item['name']
            ];
        }

        // 15. Запрос для инструментов
        $measuringToolsText = "
            SELECT
                op.technologies_operations_id,
                mt.id AS cnt,
                mt.code,
                mt.name
            FROM ogt.operations_parameters AS op
            LEFT JOIN ogt.operations_measuring_tools AS omt
                ON op.technologies_operations_id = omt.technologies_operations_id
            LEFT JOIN ogt.measuring_tools AS mt
                ON omt.measuring_tools_code_id = mt.id
            WHERE op.technologies_operations_id IN ($operationPlaceholders) AND 
                mt.is_deleted = false AND
                omt.is_deleted = false
            ORDER BY op.technologies_operations_id, mt.id";
        //
        $measuringToolsQuery = $pdo->prepare($measuringToolsText);
        $measuringToolsQuery->execute($operationIntIds);
        $measuringToolsResults = $measuringToolsQuery->fetchAll(PDO::FETCH_ASSOC);

        // 14. Группируем оснастку по технологическим операциям
        $measuringToolsByOperation = [];
        foreach ($measuringToolsResults as $measuringTool) {
            $opId = $measuringTool['technologies_operations_id'];
            if (!isset($measuringToolsByOperation[$opId])) {
                $measuringToolsByOperation[$opId] = [];
            }
            $measuringToolsByOperation[$opId][] = [
                'cnt' => $measuringTool['cnt'],
                'code' => $measuringTool['code'],
                'name' => $measuringTool['name']
            ];
        }
        
        // 15. Группируем операции по технологиям
        $operationsByTechnology = [];
        foreach ($response_array_o as $operation) {
            $techId = $operation['drawings_technologies_id'];
            if (!isset($operationsByTechnology[$techId])) {
                $operationsByTechnology[$techId] = [];
            }
            
            // Добавляем компоненты к операции
            $operation['components'] = $componentsByOperation[$operation['technologies_operations_id']] ?? null;
            $operation['materials'] = $materialsByOperation[$operation['technologies_operations_id']] ?? null;
            $operation['tooling'] = $toolingByOperation[$operation['technologies_operations_id']] ?? null;
            $operation['measuring_tools'] = $measuringToolsByOperation[$operation['technologies_operations_id']] ?? null;
            //            
            $operationsByTechnology[$techId][] = $operation;
        }
        
        // 16. Добавляем операции к соответствующим технологиям
        foreach ($technologies as $i => $technology) {
            $techId = $response_array[$i]['drawings_technologies_id'];
            $operations = $operationsByTechnology[$techId] ?? [];
            
            // Обрабатываем операции для текущей технологии
            $technologies[$i] = MUITreeItem::GetCustomTreeItemsOperations($operations, $technology);
        }
    }
    //
    $response = $technologies;
} catch (Exception $e) {
}
echo json_encode($response, JSON_UNESCAPED_UNICODE);

function validateAndCastInt($value, $fieldName = 'ID') {
    $intValue = filter_var($value, FILTER_VALIDATE_INT);
    if ($intValue === false) {
        ApiResponse::error("Поле $fieldName должно быть целым числом", 400);
    }
    return $intValue;
}
?>