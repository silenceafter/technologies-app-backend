<?php
session_start();
header('Content-Type: application/json');
/*header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Access-Control-Allow-Credentials: true');*/
require_once($_SERVER['DOCUMENT_ROOT']."/IVC/coreX.php");
require_once $_SERVER['DOCUMENT_ROOT']."/IVC/Scripts/Library.v2.2.php";
//get_equipment

$response = null;
//основное соединение
$db_object = ControlDBConnectPG::GetDb();
$conn = $db_object->GetConn();

//нет соединения
if ($conn == null)
    return;
$pdo = $conn;
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

//данные
$value = $_GET['search'];
$params = array("limit" => $_GET['limit'], "page" => $_GET['page']);
//
$db = DBHelper::GetDatabase($pdo);
$db = trim(strtolower($db));
//
if ($db == '')
    return;

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
//
if ($service == null && $repository == null)
    return;

//граница выборки
$offset = ($params['page'] - 1) * $params['limit'];
$range = $offset + $params['limit'];//$params['max'] + $params['limit']

//sql
try {
    $service->BeginTransaction();
    if ($value == "") {
        //без поиска
        $text = "
            SELECT *
            FROM ogt.equipment_view
            WHERE cnt > :params_max AND cnt <= :range
            ORDER BY cnt
            LIMIT :params_limit";
        //
        $query = $pdo->prepare($text);
        $query->bindValue(':params_limit', $params['limit']);
        $query->bindValue(':params_max', $offset);
        $query->bindValue(':range', $range);
    } else {
        //с поиском
            $text = "
                SELECT *
                FROM (
                    SELECT
                        ROW_NUMBER() OVER() AS cnt,
                        *
                    FROM (
                        SELECT
                            name,
                            type,
                            name_pos + type_pos + name_type_pos AS total
                        FROM (
                            SELECT *,
                                POSITION(TRIM(UPPER(:value)) IN UPPER(name)) AS name_pos,
                                POSITION(TRIM(UPPER(:value)) IN UPPER(type)) AS type_pos,
                                POSITION(TRIM(UPPER(:value)) IN name || ' ' || UPPER(type)) AS name_type_pos
                            FROM ogt.equipment
                            WHERE is_deleted = false AND (
                                name ILIKE '%' || :value || '%' OR
                                type ILIKE '%' || :value || '%' OR
                                name || ' ' || type ILIKE '%' || :value || '%'
                        )) AS subquery
                        WHERE name_pos > 0 OR type_pos > 0 OR name_type_pos > 0
                        ORDER BY total                                                      
                    ) AS result
                ) AS numbered
                WHERE cnt > :params_max AND cnt <= :range";
        //
        $query = $pdo->prepare($text);
        $query->bindValue(':value', $value);
        $query->bindValue(':params_max', $offset);
        $query->bindValue(':range', $range);
    }
    //
    $query->execute();
    $response_array = $query->fetchAll(PDO::FETCH_ASSOC);
    //
    $response = new class("table", $response_array) {
        public $table;
        public $data;

        function __construct(
            $table, 
            $data
            )
        {
            $this->table = $table;
            $this->data = $data;
        }
    };

    $response = json_encode($response, JSON_UNESCAPED_UNICODE);
    $service->CommitTransaction();
} catch (Exception $e) {
    $service->RollbackTransaction('');
}

header('Content-Type: application/json');
echo $response;
?>