<?php
session_start();
header('Content-Type: application/json');
/*header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Access-Control-Allow-Credentials: true');*/
require_once($_SERVER['DOCUMENT_ROOT']."/IVC/coreX.php");
require_once $_SERVER['DOCUMENT_ROOT']."/IVC/Scripts/Library.v2.2.php";
require_once $_SERVER['DOCUMENT_ROOT']."/IVC/Composer/vendor/autoload.php";
//create_products_data_tree

$response = null;
//получить соединение
$db_object = ControlDBConnectPG::GetDb();
$conn = $db_object->GetConn();

//нет соединения
if ($conn == null)
    return;

//соединение Ogt
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

//данные
$values = array("search" => $_GET['search'], "limit" => $_GET['limit'], "page" => $_GET['page']);

//основные параметры
$schema = "importer";

//json
$jsonContent = file_get_contents("tables.v2.json");
$tables = json_decode($jsonContent, true);
$table = $tables[3]['name'];

//запрос к базе данных
$data = new class($db, $schema, $table) {
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
//
$response = GetMoreInfo($pdo, $values);
echo json_encode($response, JSON_UNESCAPED_UNICODE);

function GetMoreInfo($pdo, $params)
{
    //граница выборки
    $offset = ($params['page'] - 1) * $params['limit'];
    $range = $offset + $params['limit'];

    try {
        //список drawings
        if (trim($params['search'] == "")) {
            //без поиска
            $text = "
                SELECT
                    cnt,
                    nizd,
                    mod,
                    kudar AS external_code,
                    naim
                FROM importer.\"m10870_view_dev\"
                WHERE TRIM(nizd) != '' AND 
                    cnt > :params_max AND cnt <= :range
                ORDER BY cnt
                LIMIT :params_limit";
            //
            $query = $pdo->prepare($text);
            $query->bindValue(':params_limit', $params['limit']);
            $query->bindValue(':params_max', $offset);
            $query->bindValue(':range', $range);
        } else {
            //поиск
            $text = "
                SELECT *
                FROM (
                    SELECT
                        ROW_NUMBER() OVER(ORDER BY nizd) AS cnt,
                        SUBSTR(nizd,1,10) AS nizd,
                        SUBSTR(nizd,11,3) AS mod,
                        (
                            SELECT kudar
                            FROM importer.\"m10870_view\"
                            WHERE TRIM(UPPER(nizd)) = TRIM(UPPER(SUBSTR(subquery.nizd,1,10))) AND
                                TRIM(UPPER(mod)) = TRIM(UPPER(SUBSTR(subquery.nizd,11,3)))
                            LIMIT 1
                        ) AS external_code,
                        (
                            SELECT naim
                            FROM importer.\"m10870_view\"
                            WHERE TRIM(UPPER(nizd)) = TRIM(UPPER(SUBSTR(subquery.nizd,1,10))) AND
                                TRIM(UPPER(mod)) = TRIM(UPPER(SUBSTR(subquery.nizd,11,3)))
                            LIMIT 1
                        ) AS naim
                    FROM (
                        SELECT nizd
                        FROM importer.\"pm10201_view\"
                        WHERE TRIM(UPPER(chtr)) = :search
                        GROUP BY nizd
                    ) AS subquery
                ) AS result
                WHERE cnt > :params_max AND cnt <= :range
                ORDER BY cnt
                LIMIT :params_limit";
            //
            $query = $pdo->prepare($text);
            $query->bindValue(':params_limit', $params['limit']);
            $query->bindValue(':params_max', $offset);
            $query->bindValue(':range', $range);
            $query->bindValue(':search', $params['search']);
        }
        //
        $query->execute();
        $response_array = $query->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {
        $gg = 1;
    }
    //преобразовать в json
    return MUITreeItem::GetCustomTreeItems($response_array);
}
?>