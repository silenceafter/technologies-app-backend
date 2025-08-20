<?php
session_start();
header('Content-Type: application/json');
/*header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization");
header('Access-Control-Allow-Credentials: true');*/
require_once($_SERVER['DOCUMENT_ROOT']."/IVC/coreX.php");
require_once $_SERVER['DOCUMENT_ROOT']."/IVC/Scripts/Library.v2.2.php";
//get_user_data

class UserInfo extends ExecuteComand
{
    public $AjaxDefinition = 0;
    public function DefineConstr() {}
}

try {
    if ($_SERVER['REQUEST_METHOD'] === "POST") {
      $userInfo = new UserInfo();
      if ($userInfo == null) {
          echo json_encode(null, JSON_UNESCAPED_UNICODE);
      }
      $userInfo->CheckServerInfo();

      //пользователь не авторизован
      if ($userInfo->DataState == 0) {            
          $response = (object)['UserMessage' => $userInfo->UserMessage, 'DataState' => $userInfo->DataState];
          echo json_encode($response, JSON_UNESCAPED_UNICODE);
      }
  
      //пользователь успешно авторизован и взаимодействует через сессию
      if ($userInfo->DataState > 0) {
          $response = null;
          //получить соединение
          $db_object = ControlDBConnectPG::GetDb();
          $conn = $db_object->GetConn();

          //нет соединения
          if ($conn == null)
              return;

          //соединение
          $pdo = $conn;
          $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
          //
          $db = DBHelper::GetDatabase($pdo);
          $db = trim(strtolower($db));

          //запрос к базе данных
          $data = new class($db, $schema, $table_0) {
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
          $user = $data_object;

          //sql
          try {
              //данные пользователя
              $uid = $user['UID'];
              $ivHex = $user['ivHex'];
              $keyHex = $user['keyHex'];
              $userId = SystemHelper::decrypt($uid, $keyHex, $ivHex);
                  
              //количество техпроцессов, созданных пользователем
              $text = "
                SELECT COUNT(*) AS cnt
                FROM ogt.technologies_users AS tu
                INNER JOIN ogt.drawings_technologies AS dt
                  ON tu.drawings_technologies_id = dt.id
                INNER JOIN ogt.drawings AS d
                  ON dt.drawing_id = d.id
                INNER JOIN ogt.technologies AS t
                  ON dt.technology_id = t.id
                WHERE tu.user_id = :userId AND
                  dt.is_deleted = false AND
                  d.is_deleted = false AND
                  t.is_deleted = false";
              //
              $query = $pdo->prepare($text);
              $query->bindValue(':userId', $userId);
              $query->execute();
              $response_array_technologies_cnt = $query->fetchAll(PDO::FETCH_ASSOC)[0]['cnt'];

              //дата последнего действия
              $text = "
                SELECT creation_date
                FROM ogt.technologies_users
                WHERE user_id = :userId
                ORDER BY id DESC
                LIMIT 1";
              //
              $query = $pdo->prepare($text);
              $query->bindValue(':userId', $userId);
              $query->execute();
              $response_array_technologies_last_cdate = $query->fetchAll(PDO::FETCH_ASSOC)[0]['creation_date'];

              //активность пользователя за последний год
              $text = "
                SELECT
                  TO_CHAR(action_date::date, 'DD.MM.YYYY') AS date,
                  EXTRACT(day FROM action_date::date) AS day,                   
                  COUNT(*) AS actions_count
                FROM (
                  SELECT
                    date AT TIME ZONE 'UTC' AS action_date
                  FROM ogt.users_log
                  WHERE user_id = :userId AND 
                    date >= DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '1 MONTH' AND -- Начало прошлого месяца
                    date < DATE_TRUNC('month', CURRENT_DATE) -- До начала текущего месяца
                ) AS daily_actions
                GROUP BY action_date::date
                ORDER BY day ASC";
              //
              $query = $pdo->prepare($text);
              $query->bindValue(':userId', $userId);
              $query->execute();
              $response_array_user_last_month_actions = $query->fetchAll(PDO::FETCH_ASSOC);

              //последние добавленные техпроцессы
              $text = "
                SELECT
                  ROW_NUMBER() OVER() AS cnt,
                  d.external_code AS drawing_external_code,
                  d.name AS drawing_name,
                  t.code AS technology_code,
                  t.name AS technology_name,                  
                  tu.creation_date,
                  tu.last_modified
                FROM ogt.technologies_users AS tu
                  INNER JOIN ogt.drawings_technologies AS dt
                    ON tu.drawings_technologies_id = dt.id
                  INNER JOIN ogt.drawings AS d
                    ON dt.drawing_id = d.id
                  INNER JOIN ogt.technologies AS t
                    ON dt.technology_id = t.id
                  WHERE tu.user_id = :userId AND
                    dt.is_deleted = false AND
                    d.is_deleted = false AND
                    t.is_deleted = false
                  ORDER BY tu.id
                  LIMIT 5";
              //
              $query = $pdo->prepare($text);
              $query->bindValue(':userId', $userId);
              $query->execute();
              $response_array = $query->fetchAll(PDO::FETCH_ASSOC);
              $response = $response_array;

              $response = (object)[
                "TechnologiesCreatedByUserHeaders" => (object) [
                  "cnt" => "№", 
                  "drawing_external_code" => "Код ДСЕ", 
                  "drawing_name" => "Наименование ДСЕ", 
                  "technology_code" => "Код технологии", 
                  "technology_name" => "Наименование технологии", 
                  "creation_date" => "Дата создания",
                  "last_modified" => "Дата последнего изменения"
                ],
                "TechnologiesCreatedByUser" => $response_array,
                "TechnologiesCreatedByUserCount" => $response_array_technologies_cnt,
                "TechnologiesCreatedByUserLastCreationDate" => $response_array_technologies_last_cdate,
                "TechnologiesCreatedByUserLastMonthActions" => $response_array_user_last_month_actions
            ];
          } catch(Exception $e) {
            $response = OgtHelper::GetResponseCode(500); //ответ
          }            
          //
          header('Content-Type: application/json');
          http_response_code($response->code);
          echo json_encode($response, JSON_UNESCAPED_UNICODE);
      }     
    }
} catch(Exception $e) {
    echo json_encode(null, JSON_UNESCAPED_UNICODE);
}
?>