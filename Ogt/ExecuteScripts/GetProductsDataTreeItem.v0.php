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
//get_products_data_tree_item
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
$postData = file_get_contents('php://input');
$data_object = json_decode($postData, true);

if ($data_object == null)
    return null;

//основные параметры
$schema = "importer";

//json
$jsonContent = file_get_contents("tables.v2.json");
$tables = json_decode($jsonContent, true);
$table = $tables[3]['name'];

$products_nodes = $data_object['data']['products_nodes'];
$products = $data_object['data']['products'];
$kod = $data_object['data']['kod'];
$components = $data_object['options']['components'];
$materials = $data_object['options']['materials'];
$type = $data_object['options']['product_info']['type'];
$parent = $data_object['parent'];
$child = $data_object['child'];
$subChild = $data_object['subChild'];

$table_0 = $tables[0]['name'];
$table_4 = $tables[4]['name'];

//fields
$fields_0 = $tables[0]['fields'];
$fields_4 = $tables[4]['fields'];
//
$headers = Json::GetValueString($fields_0, 'caption', array('', '№'));
$breaks = Json::GetValueString($fields_0, 'break', array(true, true));

//headers/breaks
if (count($headers) == 0 || count($breaks) == 0)
    return;

$repository = new PSQLRepository($data, $pdo);
$service = new PSQLService($data, $repository);
//
if ($service == null && $repository == null)
    return;

//fields
$fields_value_0 = Json::GetFieldsValue($fields_0);

//sql
try {
  $service->BeginTransaction();
  //запрос: таблица входящих строк
  if ($type == "product") {
      $text = "
          SELECT
              '' AS tree_view,
              ROW_NUMBER() OVER(ORDER BY chtr) AS id,
              $fields_value_0,
              subquery
          FROM (
              SELECT
                  $fields_value_0,
                  CASE
                      WHEN EXISTS (
                          SELECT 1
                          FROM $schema.\"$table_0\"
                          WHERE nizd = t1.nizd AND
                              chch = t1.chto AND
                              chto != chch
                          LIMIT 1
                          ) THEN true
                      ELSE false
                  END subquery
              FROM (
                  SELECT 
                      nizd, chto, chtr, naim, chch, chcr, cp, mr, 1 AS depth, subquery, 
                      SUM(kol) AS kol, SUM(kols) AS kols, koli
                  FROM $schema.\"$table_0\"
                  GROUP BY nizd, chto, chtr, naim, chch, chcr, cp, mr, koli, depth, subquery
                  ORDER BY nizd, chto, chtr, naim, chch, chcr, cp, mr, koli, depth, subquery
              ) AS t1
              WHERE chcr = TRIM('$kod') AND
                  TRIM(SUBSTR(nizd, 1, 10)) || TRIM(SUBSTR(nizd, 11, 3)) = 
                      TRIM(UPPER('{$products_nodes['nizd']}')) || TRIM(UPPER('{$products_nodes['mod']}')) AND
                      chtr != TRIM('$kod')";
      
      if ($components) {
          $fields_value_4 = Json::GetFieldsValue($fields_4);
          $text .= "
              UNION ALL
              SELECT
                  $fields_value_4,
                  false AS subquery
              FROM $schema.\"$table_4\"
              WHERE TRIM(kudar) = TRIM('$kod') AND
                  TRIM(SUBSTR(nizd, 1, 10)) || TRIM(SUBSTR(nizd, 11, 3)) = 
                  TRIM(UPPER('{$products_nodes['nizd']}')) || TRIM(UPPER('{$products_nodes['mod']}'))";
      }
      //
      $text .= "
          ) AS united
          ORDER BY id";
  }

  if ($type == "node") {                    
      $text = "
          SELECT 
              '' AS tree_view,
              ROW_NUMBER() OVER(ORDER BY chtr) AS id,
              $fields_value_0, 
              subquery
          FROM (
              SELECT
                  $fields_value_0,
                  CASE
                      WHEN EXISTS (
                          SELECT 1
                          FROM $schema.\"$table_0\"
                          WHERE nizd = t1.nizd AND
                              chch = t1.chto AND
                              chto != chch
                          LIMIT 1
                          ) THEN true
                      ELSE false
                  END subquery
              FROM (
                  SELECT 
                      nizd, chto, chtr, naim, chch, chcr, cp, mr, 1 AS depth, subquery,
                      SUM(kol) AS kol, SUM(kols) AS kols, koli
                  FROM $schema.\"$table_0\"
                  GROUP BY nizd, chto, chtr, naim, chch, chcr, cp, mr, koli, depth, subquery
                  ORDER BY nizd, chto, chtr, naim, chch, chcr, cp, mr, koli, depth, subquery
              ) AS t1
              WHERE chcr = TRIM('$kod') AND
                  TRIM(SUBSTR(nizd, 1, 10)) || TRIM(SUBSTR(nizd, 11, 3)) = 
                  TRIM('{$products['nizd']}') || TRIM('{$products['mod']}') AND
                  chtr != TRIM('$kod') AND
                  chtr != TRIM('{$products['chtr']}')";

      if ($components) {
          $fields_value_4 = Json::GetFieldsValue($fields_4);
          $text .= "
              UNION ALL
              SELECT
                  $fields_value_4,
                  false AS subquery
              FROM $schema.\"$table_4\"
              WHERE TRIM(kudar) = TRIM('$kod') AND
                  TRIM(SUBSTR(nizd, 1, 10)) || TRIM(SUBSTR(nizd, 11, 3)) = 
                  TRIM(UPPER('{$products['nizd']}')) || TRIM(UPPER('{$products['mod']}'))";
      }
      //
      $text .= "
          ) AS united
          ORDER BY id";
  }
  //
  $query = $pdo->prepare($text);
  $query->execute();
  $response_array = $query->fetchAll(PDO::FETCH_ASSOC);
  $result = MUITreeItem::GetCustomTreeItems2($response_array, $parent['id']);

  //ответ
  $response = new class($result, $parent) {
    public $items;
    public $parentId;

    function __construct($result, $parent)
    {
        $this->items = $result;
        $this->parentId = $parent['id'];
    }
  };
  $service->CommitTransaction();
} catch(Exception $e) {
    $service->RollbackTransaction('');
}
echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>