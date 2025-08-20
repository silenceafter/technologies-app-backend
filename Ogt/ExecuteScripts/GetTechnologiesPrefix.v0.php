<?php
session_start();
header('Content-Type: application/json');
/*header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization");
header('Access-Control-Allow-Credentials: true');*/
require_once($_SERVER['DOCUMENT_ROOT']."/IVC/coreX.php");
require_once $_SERVER['DOCUMENT_ROOT']."/IVC/Scripts/Library.v2.2.php";
//get_technologies_prefix

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

            //данные
            $postData = file_get_contents('php://input');
            $data_object = json_decode($postData, true);
            //
            $uid = $data_object['UID'];
            $ivHex = $data_object['ivHex'];
            $keyHex = $data_object['keyHex'];
            $userId = SystemHelper::decrypt($uid, $keyHex, $ivHex);

            //sql
            try {        
                //users_status
                $text = "
                    SELECT status_id
                    FROM ogt.users_status
                    WHERE user_id = :userId AND is_deleted = false
                    LIMIT 1";
                //
                $query = $pdo->prepare($text);
                $query->bindValue(':userId', $userId);
                $query->execute();
                $statusId = $query->fetchAll(PDO::FETCH_ASSOC)[0]['status_id'];

                //technologies_prefix
                if ($statusId == 1) {
                    //пользователь задачи => ограничение по подразделению и бюро
                    $text = "
                        SELECT prefix
                        FROM ogt.technologies_prefix
                        WHERE group_id = (
                            SELECT user_group
                            FROM public.users
                            WHERE iduser = :userId
                        ) AND is_deleted = false
                        ORDER BY prefix";
                    //
                    $query = $pdo->prepare($text);
                    $query->bindValue(':userId', $userId);
                } elseif ($statusId == 2) {
                    //администратор задачи 
                    $text = "
                        SELECT prefix
                        FROM ogt.technologies_prefix
                        ORDER BY prefix";
                    //
                    $query = $pdo->prepare($text);             
                }
                //
                $query->execute();
                $response_array = $query->fetchAll(PDO::FETCH_ASSOC);
                $response = $response_array;            
            } catch(Exception $e) {
              $response = OgtHelper::GetResponseCode(500, [], ""); //ответ
            }            
            //
            header('Content-Type: application/json');
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
        }
    }    
} catch(Exception $e) {
    echo json_encode(null, JSON_UNESCAPED_UNICODE);
}
?>