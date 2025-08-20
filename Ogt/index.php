<?php
session_start();
/*header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");*/
/*header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization");
header('Access-Control-Allow-Credentials: true');*/

//имя папки, в том числе для classes.php
$folder = basename(dirname(__FILE__));
//
require_once $_SERVER['DOCUMENT_ROOT']."/IVC/coreX.php";
require_once $_SERVER['DOCUMENT_ROOT']."/IVC/Scripts/Library.v2.1.php";
require_once "classes.v1.php";

class UserInfo extends ExecuteComand
{
    public $AjaxDefinition = 0;
    public function DefineConstr() {}
}

$PageGenerator = new $folder();
$PageGenerator->CheckServerInfo();
if ($_SERVER['REQUEST_METHOD'] === "GET") {
    //сессия истекла/нужна повторная авторизация
    if ($PageGenerator != null && $PageGenerator->DataState == 0) {        
        /*$url = "http://localhost:3000/login";
        header("Location: $url");
        exit();*/
    }

    //пользователь успешно авторизован и взаимодействует через сессию
    if ($PageGenerator != null && $PageGenerator->DataState > 0) {
        //перенаправление на react-клиент        
        header('Content-Type: application/json');
        
        //данные
        $values = array("action" => $_GET['action']);
        $action = "";
        if (array_key_exists('action', $values)) {
            $action = $values['action'];
        }

        //выйти из учетной записи
        if (strtolower(trim($action)) == "signout") {
            $PageGenerator->UserExit();
        }
    }
} else if ($_SERVER['REQUEST_METHOD'] === "POST") {
    //авторизация
}
?>