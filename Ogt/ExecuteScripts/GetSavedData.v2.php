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
    $service->BeginTransaction();
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
      for($i = 0; $i < count($technologies); $i++)
      {
        $drawings_technologies_id = $response_array[$i]['drawings_technologies_id'];
        /*$text = "WITH grouped_components AS (
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
	        ORDER BY op.technologies_operations_id";*/
        
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

        //добавить дополнительные данные
        foreach($response_array_o as $response_item)
        {
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
                WHERE op.technologies_operations_id = :technologies_operations_id AND 
                    c.is_deleted = false AND 
                    oc.is_deleted = false
                ORDER BY c.id";
            //
            $query = $pdo->prepare($text);
            $query->bindValue(':technologies_operations_id', $response_item['technologies_operations_id']);
            $query->execute();
            $response_array_components = $query->fetchAll(PDO::FETCH_ASSOC);
            $response_item['components'] = json_encode($response_array_components, JSON_UNESCAPED_UNICODE);
        }

        

        $technologies[$i] = MUITreeItem::GetCustomTreeItemsOperations($response_array_o, $technologies[$i]);
      }
    }
    //
    $response = $technologies;
    $service->CommitTransaction();
} catch (Exception $e) {
    $service->RollbackTransaction('');
}
echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>