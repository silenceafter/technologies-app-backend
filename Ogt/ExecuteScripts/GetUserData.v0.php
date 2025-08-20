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
    if ($_SERVER['REQUEST_METHOD'] === "GET") {
        $userInfo = new UserInfo();
        if ($userInfo == null) {
            echo json_encode(null, JSON_UNESCAPED_UNICODE);
        }
        //
        $userInfo->CheckServerInfo();

        //пользователь не авторизован
        if ($userInfo->DataState == 0) {            
            $response = (object)['UserMessage' => $userInfo->UserMessage, 'DataState' => $userInfo->DataState];
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            /*$url = "http://localhost:3000/login";
            header("Location: $url");
            exit();*/
        }
    
        //пользователь успешно авторизован и взаимодействует через сессию
        if ($userInfo->DataState > 0) {
            //перенаправление на react-клиент
            $userInfo->GetUserInfo();
            $userInfoArray = $userInfo->UserInfoArray[0];
            
            //проверяем доступ к системе            
            if ($userInfo->PageAccess == 1 && ($userInfoArray['idstatus'] == 2 || $userInfoArray['idstatus'] == 3)) {
                //у пользователя есть доступ
                $iv = SystemHelper::generateIv();
                $keyHex = bin2hex(openssl_random_pseudo_bytes(32));
                $ivHex = SystemHelper::encodeIv($iv);

                //если перед нами пользователь задачи (не системы), то нужно определить статус пользователя
                $groupId = null;                
                if ($userInfoArray['idstatus'] == 2) {
                    //получить соединение
                    $db_object = ControlDBConnectPG::GetDb();
                    $pdo = $db_object->GetConn();
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                    //users_status
                    $text = "
                        SELECT 
                            us.status_id,
                            s.name
                        FROM ogt.users_status AS us
                        INNER JOIN ogt.status AS s
                            ON us.status_id = s.id
                        WHERE us.user_id = :userId AND
                            us.is_deleted = false AND
	                        s.is_deleted = false
                        LIMIT 1";
                    //
                    $query = $pdo->prepare($text);
                    $query->bindValue(':userId', $userInfo->UserId);
                    $query->execute();
                    $response_array = $query->fetchAll(PDO::FETCH_ASSOC);
                    //                  
                    $taskStatusId = $response_array[0]['status_id'];
                    $taskStatusName = $response_array[0]['name'];

                    //prefix
                    $text = "
                        SELECT DISTINCT 
                            tp.group_id
                        FROM (
                            SELECT
                                user_group
                            FROM public.users
                            WHERE iduser = :userId
                        ) AS u
                        INNER JOIN ogt.technologies_prefix AS tp
                            ON u.user_group = tp.group_id AND
                                tp.is_deleted = false
                        LIMIT 1";
                    //
                    $query = $pdo->prepare($text);
                    $query->bindValue(':userId', $userInfo->UserId);
                    $query->execute();
                    $response_array = $query->fetchAll(PDO::FETCH_ASSOC);
                    $groupId = $response_array[0]['group_id']; 
                } elseif ($userInfoArray['idstatus'] == 3) {
                    //подзапрос необязателен, т.к. пользователь задачи уже администратор системы
                    $taskStatusId = null; //2
                    $taskStatusName = $userInfoArray['status'];
                }

                //добавить в UserInfoArray
                $UserInfoArray = $userInfo->UserInfoArray[0];
                $UserInfoArray['keyHex'] = $keyHex;
                $UserInfoArray['ivHex'] = $ivHex;
                $UserInfoArray['UID'] = SystemHelper::encrypt($userInfo->UserId, $keyHex, $ivHex);
                $UserInfoArray['GID'] = SystemHelper::encrypt($groupId, $keyHex, $ivHex); //$groupId;
                //$UserInfoArray['taskStatusId'] = $taskStatusId;
                $UserInfoArray['taskStatusName'] = $taskStatusName;
                $UserInfoArray['role'] = OgtHelper::GetUserAccess($UserInfoArray, $taskStatusId, $groupId);
                //
                $response = (object)[
                    'UserMessage' => $userInfo->UserMessage, 
                    'UserInfoArray' => $UserInfoArray, 
                    'DataState' => $userInfo->DataState
                ];                
            } else {
                //пользователь авторизован, но у него нет доступа к технологиям
                //удаляем все данные сессии
                $_SESSION = array();

                //удаляем сессию
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $params["path"], $params["domain"],
                        $params["secure"], $params["httponly"]
                    );
                }

                //удаляем идентификатор сессии
                session_destroy();
                $response = (object)[
                    'UserMessage' => $userInfo->UserMessage, 
                    'UserInfoArray' => $userInfo->UserInfoArray[0], 
                    'DataState' => $userInfo->DataState
                ];//echo json_encode(null, JSON_UNESCAPED_UNICODE);
            }           
            //
            //$response = (object)['UserMessage' => $userInfo->UserMessage, 'UserInfoArray' => $userInfo->UserInfoArray[0], 'DataState' => $userInfo->DataState];
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
        }       
    }
} catch(Exception $e) {
    echo json_encode(null, JSON_UNESCAPED_UNICODE);
}
?>