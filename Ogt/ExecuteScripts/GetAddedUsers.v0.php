<?php
session_start();
header('Content-Type: application/json');
require_once($_SERVER['DOCUMENT_ROOT']."/IVC/coreX.php");
require_once $_SERVER['DOCUMENT_ROOT']."/IVC/Scripts/Library.v2.2.php";
//get_added_users

$response = null;
//основное соединение
$db_object = ControlDBConnectPG::GetDb();
$conn = $db_object->GetConn();

//нет соединения
if ($conn == null)
    return;
$pdo = $conn;
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

//sql
try {
  $text = "
      SELECT
        u.lastname,
        u.firstname,
        u.patronimic,
        d.description AS division,
        g.description AS group,
        p.description AS post,
        s.description AS status
      FROM ogt.users_status AS us
      INNER JOIN public.users AS u
        ON us.user_id = u.iduser
      INNER JOIN public.posts AS p
        ON u.post = p.idpost
      INNER JOIN public.status AS s
        ON u.status = s.idstatus
      INNER JOIN public.divisions AS d
        ON u.division = d.iddivision
      INNER JOIN public.groups AS g
        ON u.user_group = g.idgroup
      WHERE us.is_deleted = false";
  //
  $query = $pdo->prepare($text);
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
} catch (Exception $e) {
}

header('Content-Type: application/json');
echo $response;
?>