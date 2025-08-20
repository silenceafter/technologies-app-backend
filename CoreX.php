<?php
$config = include('config.php');

class ControlDBConnectPG
{
	private static $db = null;
	protected $Server = "localhost";
	protected $Database = "IVC";
	protected $UID = "postgres";
	protected $PWD = "bdw";
	protected $port = "5432";
	protected $conn;

	public function __construct()
	{
		try 
		{
			$this->conn = new PDO('pgsql:host='.$this->Server.';port='.$this->port.';dbname='.$this->Database.';', ''.$this->UID.'', ''.$this->PWD.'',array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
		}
		catch (PDOException $e)
		{
			die($e->getMessage());
		}
	}
	public static function GetDB() 
	{
    	if (self::$db == null) self::$db = new ControlDBConnectPG();
    	return self::$db;
  	}
  	public function GetConn()
  	{
  		return $this->conn;
  	}
}
abstract class Authorization
{
	public function CheckPageControlInfo()
	{
		if ($this->AjaxDefinition == 0)
		{
			$pconfig = include('pconfig.php');
		}
		else
		{
			if (!isset($this->PageControl)) 
			{
				$pconfig = include('../pconfig.php');
			}
			else
			{
				$parentconfig = include('../pconfig.php');
			}
		}
		if (isset($pconfig['pagecontrol'])) 
		{
			$this->PageControl = $pconfig['pagecontrol'];
		}
		else
		{
			$this->ParentControl = $parentconfig['pagecontrol'];
		}
	}
	public function DataCollection()
	{
		if (isset($_SESSION['enterid'])) $this->Session_EnterId = $_SESSION['enterid'];
		if (isset($_COOKIE['enterID'])) $this->Coockie_EnterId = $_COOKIE['enterID'];
		if (isset($_SESSION['USRHASH'])) $this->Session_UserHash = $_SESSION['USRHASH'];
		if (isset($_COOKIE['userhash'])) $this->Coockie_UserHash = $_COOKIE['userhash'];
		if ($_POST['login']) $this->Login = $_POST['login'];
		if ($_POST['password']) $this->Pass = $_POST['password'];
		if ($_POST['remember']) $this->RememberState = $_POST['remember'];
		$this->UserIP = $_SERVER['REMOTE_ADDR'];
		if (isset($config['remembertime'])) 
		{
			$this->RememberTime = $config['remembertime'];
		}
		else
		{
			$this->RememberTime = 30;//30
		}
		
		$this->DBConnect = ControlDBConnectPG::GetDb();
		$this->Date = date("Y-m-d H:i:s");
	}
}
class AuthorizationControl extends Authorization
{
	public $Login;
	public $Pass;
	public $RememberState = 0;
	public $RememberTime;
		
	public $Session_EnterId;
	public $Coockie_EnterId;
	public $Session_UserHash;
	public $Coockie_UserHash;

	public $DBConnect;
	public $Date;

	//public $ParentControl;//контроль доступа родительской страници
	public $UserId;//ид пользователя
	public $UserInfoArray = null;//массив данных о пользователе //lastname,firstname,patronimic,post,guild,briefly,status
	public $PageInfoArray; //массив данных по адресу запрашиваемой странице //useraccess,commandlevel,commandid
	public $PageAccess; //есть ли доступ у юзера к данной странице или команде
	public $RealDateEscape;//дата, когда польозватель должен вылететь из системы
	public $EnterId;

	public $PageControl; //нуждается ли страница в проверке доступа через систему авторизации // 0 всем, 1 авторизаванным, 2 контроль через бд
	public $AjaxDefinition = 0;// команда или интерфейс, 0 - интерфейс, 1 - команда
	
	public $DataState = 0; //статус данных авторизации, 0 - не успех, 1 - новая авторизация, 2 - действия через сессию, 3 - действия через куки, перетекающие в действия через сессию, 4 - пропуск без авторизации
	public $UserMessage; //сообщение при ошибке

	public function __construct()
	{
		//$this->CheckPageControlInfo();
		//if ($this->PageControl == 0 or $this->PageControl == 1 or $this->PageControl == 2) 
		//{
			$this->DataCollection();
			if ($_POST['exit'] == 1) 
			{
				self::UserExit();
			}
			else
			{
				self::CheckAuthorization();
				switch($this->DataState)
				{
					case 1:
						$this->EditUserAuthorization();
					break;
					case 3:
						$this->EditUserReAuthorization();
					break;
				}
			}
		//}
	}
	public function CheckAuthorization()
	{
		if(($this->Login AND $this->Pass) OR $this->Coockie_UserHash OR $this->Session_UserHash)
		{
			if ($this->Login AND $this->Pass) 
			{
				$this->DataState = self::CheckLoginPass();
			}
			else
			{
				$this->DataState = self::CheckServerInfo();
			}
		}
		else
		{
			$this->DataState = 0;
		}
	}
	public function CheckLoginPass()
	{
		$QueryStr = "SELECT * FROM GetUserAuthInfo('".$this->Login."')";
		$DB = $this->DBConnect;
		$conn = $DB->GetConn();
		$Query = $conn->prepare($QueryStr);
		$Query->execute();
		$array = $Query->fetchAll(PDO::FETCH_ASSOC);
		if (count($array)) 
		{
			if (password_verify($this->Pass,$array[0]['pass']))
			{
				$this->UserId = $array[0]['id'];
				return 1;
			}
			else
			{
				$this->UserMessage = "Не верный логин или пароль";
				return 0;
			}
		}
		else
		{
			$this->UserMessage = "Пользователь не зарегистрирован";
			return 0;
		
		}
	}
	public function CheckServerInfo()
	{
		$AuthState = 0;
		$UserHash = '';
		$UserEnterHash = '';
		if(isset($this->Session_UserHash))
		{
			$UserHash = $this->Session_UserHash;
			$UserEnterHash = $this->Session_EnterId;
		}
		else if (isset($this->Coockie_EnterId) AND isset($this->Coockie_UserHash))
		{
			$UserHash = $this->Coockie_UserHash;
			$UserEnterHash = $this->Coockie_EnterId;
			$AuthState++;
		}
		else
		{
			$this->UserMessage = "Данные авторизации повреждены. Авторизуйтесь повторно!(0)";
			$this->UserExit();
			return 0;
		}
		$QueryStr = "SELECT * FROM userentrylog 
							WHERE hash = '".$UserHash."'
							ORDER BY dateentry DESC LIMIT 1";
		$DB = $this->DBConnect;
		$conn = $DB->GetConn();
		$Query = $conn->prepare($QueryStr);
		$Query->execute();
		$array = $Query->fetchAll(PDO::FETCH_ASSOC);
		if ($array[0]['id'] AND (count($array) > 0))
		{
			if (hash_equals(hash('gost',$array[0]['id']),$UserEnterHash))
			{
				if ((strtotime($array[0]['dateescape'])-strtotime(date("Y-m-d H:i:s"))) < 0)
				{
					$this->UserExit();
					$this->UserMessage = "Время сеанса истекло.Авторизуйтесь повторно! (1)";
					return 0;
				}
				else
				{
					$AuthState += 2;
					$this->UserId = $array[0]['iduser'];
					$this->RealDateEscape = $array[0]['dateescape'];
					$this->EnterId = $array[0]['id'];
					return $AuthState;
				}
			}
			else
			{
				$this->UserExit();
				$this->UserMessage = "Данные авторизации повреждены. Авторизуйтесь повторно! (2)";
				return 0;
			}
		}
		else
		{
			$this->UserMessage = "Данные авторизации повреждены. Авторизуйтесь повторно! (3)";
			$this->UserExit();
			return 0;
		}
	}
	public function EditUserReAuthorization() //при datastate = 3
	{
		$QueryStr = "UPDATE userentrylog SET dateescape = '".date("Y-m-d H:i:s")."' WHERE hash = '".$this->Coockie_UserHash."'";
		$DB = $this->DBConnect;
		$conn = $DB->GetConn();
		$Query = $conn->prepare($QueryStr);
		$Query->execute();
		
		$QueryStr = "INSERT INTO UserEntryLog(id,iduser, dateentry, dateescape, hash, ip)
					VALUES (DEFAULT,".$this->UserId.",'".date("Y-m-d H:i:s")."','".$this->RealDateEscape."', DEFAULT, '".$this->UserIP."')
					RETURNING id, hash;";
		$DB = $this->DBConnect;
		$conn = $DB->GetConn();
		$Query = $conn->prepare($QueryStr);
		$Query->execute();
		$array = $Query->fetchAll(PDO::FETCH_ASSOC);
		$this->EnterId = $array[0]['id'];
		$this->Session_EnterId = hash('gost',$array[0]['id']);
		$this->Session_UserHash = $array[0]['hash'];
		$this->SetSession();
		$this->SetCookie();
	}
	public function EditUserAuthorization() //при datastate = 1
	{
		if (isset($this->RememberState) AND $this->RememberState > 0) 
		{
			$dateescape = date("Y-m-d H:i:s",strtotime("+".$this->RememberTime." day"));
		}
		else
		{
			$dateescape = date("Y-m-d H:i:s",strtotime($this->Date)+86400);//86400
		}
		$this->RealDateEscape = $dateescape;
		$QueryStr = "INSERT INTO UserEntryLog(id,iduser, dateentry, dateescape, hash, ip)
					VALUES (DEFAULT,".$this->UserId.",'".date("Y-m-d H:i:s")."','".$this->RealDateEscape."', DEFAULT, '".$this->UserIP."')
					RETURNING id, hash;";
		$DB = $this->DBConnect;
		$conn = $DB->GetConn();
		$Query = $conn->prepare($QueryStr);
		$Query->execute();
		$array = $Query->fetchAll(PDO::FETCH_ASSOC);
		$this->EnterId = $array[0]['id'];
		$this->Session_EnterId = hash('gost',$array[0]['id']);
		$this->Session_UserHash = $array[0]['hash'];
		$this->SetSession();
		if (isset($this->RememberState) AND $this->RememberState > 0) 
		{
			$this->SetCookie();
		}
	}
	public function SetSession()
	{
		$_SESSION['USRHASH'] = $this->Session_UserHash;
		$_SESSION['enterid'] = $this->Session_EnterId;
	}
	public function SetCookie()
	{
		setcookie("enterID",$this->Session_EnterId, strtotime("+".$this->RememberTime." day"),"/","localhost",0,1 );// localhost вместо пустой строки
		setcookie("userhash",$this->Session_UserHash, strtotime("+".$this->RememberTime." day"),"/","localhost",0,1 );
	}
	public function UnsetCookie()
	{
		setcookie("USH", '', strtotime("-360 day"),"/","",0 );
		setcookie("enterID","", strtotime("-360 day"),"/","",0);
		setcookie("userhash","", strtotime("-360 day"),"/","",0);
	}
	public function UnsetUserToken()
	{
		if(isset($this->Session_UserHash))
		{
			$Hash = $this->Session_UserHash;
		}
		else if(isset($this->Coockie_EnterId))
		{
			$Hash = $this->Coockie_UserHash;
		}
		else
		{
			return;
		}
		$QueryStr = "UPDATE userentrylog SET dateescape = '".date("Y-m-d H:i:s")."' WHERE hash = '".$Hash."'";
		$DB = $this->DBConnect;
		$conn = $DB->GetConn();
		$Query = $conn->prepare($QueryStr);
		$Query->execute();
	}
	public function UserExit()
	{
		$this->UnsetUserToken();
		session_destroy();
		$this->UnsetCookie();
	}
}
class CollectionUserInfo extends AuthorizationControl
{
	public function __construct()
	{
		parent::__construct();
		if ($this->AjaxDefinition == 1) 
		{
			$this->GetCommandInfo(); 
		}
		else
		{
			$this->GetPageInfo();
		} 
		switch($this->PageInfoArray[0]['accesslevel'])
		{//0 - свободный вход, 1 - только авторизованным, 2 - по правам

			case 0:
				$this->PageAccess = 1;
			break;
			case 1:
				if ($this->DataState > 0) 
				{
					$this->PageAccess = 1;	
				}
				else
				{
					$this->PageAccess = 0;
				}
			break;
			case 2:
				if ($this->DataState > 0 AND $this->DataState <= 3) 
				{
					$this->GetUserAccess();
					$this->PageAccess = $this->ChechUserAccess();	
				}
				else
				{
					$this->PageAccess = 0;
				}
			break;
		}
	}
	public function GetPageInfo() //если данная функция выдаёт null, значит страница не зарегистрирована в базе
	{
		
		$QueryStr = "SELECT name, id, accesslevel FROM pagelist WHERE LOWER(url) = LOWER('".mb_strtolower($_SERVER["SCRIPT_NAME"])."')";
		$DB = $this->DBConnect;
		$conn = $DB->GetConn();
		$Query = $conn->prepare($QueryStr);
		//var_dump($QueryStr);
		try 
		{	
			$Query->execute();
			$array = $Query->fetchAll(PDO::FETCH_ASSOC);
			if (count($array) > 0) 
			{
				$this->PageInfoArray = $array;
			}
			else
			{
				$this->PageInfoArray = null;
			}
		} 
		catch (Exception $e) 
		{
			$this->PageInfoArray = null;
		}
	}
	public function GetCommandInfo()
	{	
		// выдрать имя родительской папки и проверить, если у пользуна доступ в странице по такому адресу!
		//$ParenPageInExecPath = substr($_SERVER["SCRIPT_NAME"],0,strripos($_SERVER["SCRIPT_NAME"],'/'))."/index.php"; // если скрипт вместе с родительской страницей
		$ParenPageInPath = substr($_SERVER["SCRIPT_NAME"],0,strripos(substr($_SERVER["SCRIPT_NAME"],0,strripos($_SERVER["SCRIPT_NAME"],'/')),'/'))."/index.php"; //если скрипт в папке
		$QueryStr = "SELECT name, id, accesslevel FROM pagelist WHERE LOWER(url) = LOWER('".mb_strtolower($ParenPageInPath)."')";
		$DB = $this->DBConnect;
		$conn = $DB->GetConn();
		$Query = $conn->prepare($QueryStr);
		try 
		{	
			$Query->execute();
			$array = $Query->fetchAll(PDO::FETCH_ASSOC);
			if (count($array) > 0) 
			{
				$this->PageInfoArray = $array;
			}
			else
			{
				$this->PageInfoArray = null;
			}
		} 
		catch (Exception $e) 
		{
			$this->PageInfoArray = null;
		}
	}
	public function GetUserAccess()
	{
		$QueryStr = "SELECT count(*) as cnt FROM pageaccess WHERE userid = ".$this->UserId." AND pageid = ".$this->PageInfoArray[0]['id'];
		$DB = $this->DBConnect;
		$conn = $DB->GetConn();
		$Query = $conn->prepare($QueryStr);
		try 
		{	
			$Query->execute();
			$array = $Query->fetchAll(PDO::FETCH_ASSOC);
			if ($array[0]['cnt'] == 1) 
			{
				$this->PageInfoArray[0] +=array('access' => 1);
			}
			else
			{
				$this->PageInfoArray[0] +=array('access' => 0);
			}
		}
		catch (Exception $e) 
		{
			$this->PageInfoArray[0] +=array('access' => 0);
		}
	}
	public function ChechUserAccess()
	{
		if ($this->PageInfoArray[0]['access'] == 1) 
		{
			return 1;
		}
		else
		{
			return 2;
		}
	}
	public function GetUserInfo()
	{
		$QueryStr = "SELECT 
		USR.Lastname, 
		USR.Firstname, 
		USR.Patronimic, 
		USR.division,
		UPST.description AS Post,
		UDIV.description AS Guild,
		UDIV.briefly,
		USTAT.description AS Status,
		USTAT.idstatus AS IDStatus,
		UGR.description AS Group
		FROM Users AS USR
		LEFT JOIN Posts AS UPST ON UPST.idpost = USR.post
		LEFT JOIN Divisions AS UDIV ON UDIV.iddivision = USR.division
		LEFT JOIN Status AS USTAT ON USTAT.idstatus = USR.status
		LEFT JOIN Groups AS UGR ON UGR.idgroup = USR.user_group
		WHERE USR.iduser = '".$this->UserId."'";
		$DB = $this->DBConnect;
		$conn = $DB->GetConn();
		$Query = $conn->prepare($QueryStr);
		$Query->execute();
		$array = $Query->fetchAll(PDO::FETCH_ASSOC);
		$this->UserInfoArray = $array;
	}
	public function ActionLogEntering($ActionType,$Description = null)
	{
		$QueryStr = "INSERT INTO log VALUES (DEFAULT,:enterlog,:page_id,:action_type,:date,:description)";
		$DB = $this->DBConnect;
		$conn = $DB->GetConn();
		$Query = $conn->prepare($QueryStr);
		$Query->bindParam(':enterlog', $this->EnterId, PDO::PARAM_STR); 
		$Query->bindParam(':page_id', $this->PageInfoArray[0]['id'], PDO::PARAM_STR); 
		$Query->bindParam(':action_type', $ActionType, PDO::PARAM_STR); 
		$Query->bindParam(':date', $this->Date, PDO::PARAM_STR); 
		$Query->bindParam(':description', $Description, PDO::PARAM_STR); 
		$Query->execute();
		$array = $Query->fetchAll(PDO::FETCH_ASSOC);
	}
}
class GUIGenerator extends CollectionUserInfo
{
	public $EnterState;
	public function __construct()
	{
		parent::__construct();
		//var_dump($this);
		switch($this->PageAccess)
		{
			case 0: //форма авторизации
				$this->EnterState = 0;
				$this->GenerateHTML();
			break;
			case 1: //страница
				switch ($this->PageInfoArray[0]['accesslevel']) 
				{
					case 0: // доступная всем страница
							if ($this->DataState == 0) 
							{
								$this->EnterState = 1;
								$this->GenerateHTML();
								//гость
							}
							else
							{
								$this->EnterState = 2;
								$this->ActionLogEntering(0);
								$this->GenerateHTML();
								//юзер
							}
							
						break;
					case 1: //доступная только авторизованным пользователям страница
						$this->EnterState = 2;
						$this->ActionLogEntering(0);
						$this->GenerateHTML();
						break;
					case 2: //доступная только авторизованным пользователям, имеющим права на доступ к странице
						$this->EnterState = 2;
						$this->ActionLogEntering(0);
						$this->GenerateHTML();
						break;
				}
			break;
			case 2: // нет доступа
				$this->ActionLogEntering(1);
				$this->AccessDeniedPage();
			break;
		}
	}
	/*
	public function GetUserInfo()
	{
		$QueryStr = "SELECT 
		USR.Lastname, 
		USR.Firstname, 
		USR.Patronimic, 
		USR.division,
		UPST.description AS Post,
		UDIV.description AS Guild,
		UDIV.briefly,
		USTAT.description AS Status,
		UGR.description AS Group
		FROM Users AS USR
		LEFT JOIN Posts AS UPST ON UPST.idpost = USR.post
		LEFT JOIN Divisions AS UDIV ON UDIV.iddivision = USR.division
		LEFT JOIN Status AS USTAT ON USTAT.idstatus = USR.status
		LEFT JOIN Groups AS UGR ON UGR.idgroup = USR.user_group
		WHERE USR.iduser = '".$this->UserId."'";
		$DB = $this->DBConnect;
		$conn = $DB->GetConn();
		$Query = $conn->prepare($QueryStr);
		$Query->execute();
		$array = $Query->fetchAll(PDO::FETCH_ASSOC);
		$this->UserInfoArray = $array;
	}
	*/
	public function GenerateHTML()
	{
		echo "<!DOCTYPE html>
			<html>
			<head>
				<meta charset='utf-8'>
				<link rel='shortcut icon' href='/IVC/Capture/ico.png' type='image/x-icon'>";
				$this->RequireModuls();	
		echo "<title>".$this->PageInfoArray[0]['name']."</title>";
		echo "</head>
			  <body>";
		echo "<div class='body_container'>";
		
		switch($this->EnterState)
		{
			case 0:
				$this->GenerateAuthorizationForm();
			break;
			case 1:
				$this->GeneratePage(); // гость
			break;
			case 2:
				$this->GetUserInfo(); // юзер
				$this->GeneratePage();
			break;
		}
		echo "<div>";
		echo "</body>";
		echo "</html>";
	}
	public function GeneratePage()
	{
		$this->GenerateHead();
		$this->GenerateBody();
	}
	public function GenerateAuthorizationForm()
	{
		echo "
		<div class='authorization_form_container'>
			<form class='authorization_form' method='POST'>
			<div>Авторизация</div>";
		if ($this->UserMessage) 
		{
			echo "<div class='Error_message'>".$this->UserMessage."</div>";
		}
				echo "
				Логин
				<br>
				<input type='text' placeholder='Логин' name='login'>
				<br>
				Пароль
				<br>
				<input type='password' placeholder='Пароль' name='password'>
				<br>
				<input type='checkbox' name='remember' value='1'> Запомнить меня на этом устройстве
				<br>
				<div>
					<button type='submit'>Войти</button>
				</div>
			</form>
		</div>";
	}
	public function GenerateHead()
	{
		if ($this->UserInfoArray == null) 
		{
			$Guild = 'АО Электроагрегат';
			$User = 'Гость';
		}
		else
		{
			$Group = $this->UserInfoArray[0]['group'];
			$Guild = $this->UserInfoArray[0]['briefly'];
			$User = $this->UserInfoArray[0]['post']." - ".$this->UserInfoArray[0]['lastname']." ".substr($this->UserInfoArray[0]['firstname'],0,2).". ".substr($this->UserInfoArray[0]['patronimic'],0,2).". ";
		}
		echo "<div class='PageHead'>";
			echo "<button class='menu_toggler' onclick='ToggleMainMenu()'><a></a><a></a><a></a></button>";
			echo "<div>";
				echo $Guild." - ".$Group;
				echo "<br>";
				//echo $this->UserInfoArray[0]['description'];
			echo "</div>";
			echo"<div>";
				//echo "<a style='font-size:12px'>StarPlatinumSystem 0.1</a>";
				echo $this->PageInfoArray[0]['name'];
			echo "</div>";
			echo "<div>";
				echo $User;
				//echo print_r($this->UserInfoArray);
				echo "<form method='POST' name='exit'>";
				echo "<button name='exit' type='submit' value='1'>Выйти</button>";
			echo "</form>";
			echo "</div>";
		echo "</div>";
	}
	public function GenerateBody()
	{
		echo "<div class='LeftMenu'>";
		switch($this->EnterState)
		{
			case 1:
				$QueryStr = "SELECT * FROM pagelist where accesslevel = 0 order by pl.name";
				$DB = $this->DBConnect;
				$conn = $DB->GetConn();
				$Query = $conn->prepare($QueryStr);
				$Query->execute();
				$array = $Query->fetchAll(PDO::FETCH_ASSOC);
			break;
			case 2:
				$QueryStr = "SELECT distinct pl.id, pl.name, pl.url, pl.accesslevel FROM pagelist as pl
							left join pageaccess as pa on pa.pageid = pl.id
							where (pl.accesslevel < 2) OR (pa.userid = '".$this->UserId."') order by pl.name";
				$DB = $this->DBConnect;
				$conn = $DB->GetConn();
				$Query = $conn->prepare($QueryStr);
				$Query->execute();
				$array = $Query->fetchAll(PDO::FETCH_ASSOC);
				break;
			case 3:
				$QueryStr = "SELECT pl.id, pl.name, pl.url, pl.accesslevel FROM pagelist as pl
							left join pageaccess as pa on pa.pageid = pl.id
							where (pl.accesslevel < 2) OR (pa.userid = '".$this->UserId."') order by pl.name";
				$DB = $this->DBConnect;
				$conn = $DB->GetConn();
				$Query = $conn->prepare($QueryStr);
				$Query->execute();
				$array = $Query->fetchAll(PDO::FETCH_ASSOC);
			break;
		}
		if ($this->EnterState == 1) 
		{
			echo "<a href='http://".$_SERVER['SERVER_NAME']."/IVC/index.php'>Личный кабинет</a>";
			for ($i=0; $i < count($array); $i++) 
			{ 
				echo "<a href='http://".$_SERVER['SERVER_NAME'].$array[$i]['url']."'>".$array[$i]['name']."</a>";
			}
		}
		else
		{
			echo "<a href='http://".$_SERVER['SERVER_NAME']."/IVC/index.php'>Личный кабинет</a>";
			for ($i=0; $i < count($array); $i++) 
			{ 
				if ($array[$i]['id'] !== 0) 
				{
					echo "<a href='http://".$_SERVER['SERVER_NAME'].$array[$i]['url']."'>".$array[$i]['name']."</a>";
				}
			}
		}
		echo "</div>";
		echo "<div class='MainBody'>";
		$this->MainGeneration();
		echo "</div>";
	}
	public function MainGeneration()
	{
		//метод для перегрузки, в него пишется вызов кода подключаемой страницы
		//echo "Успешная авторизация!<br>Ид сессии ".$this->SessionId."<br>Ид юзера ".$this->UserId."<br>Хэш юзера ".$this->ClientHash."<br>ИП юзера ".$this->UserIP."<br>ИД входа ".$this->UserEnterId."<br>";
		//echo "Скоро здесь будет полезная информация<br>";
		//id пользователя
		$lastname = $this->UserInfoArray[0]['lastname'];
		$firstname = $this->UserInfoArray[0]['firstname'];
		$patronimic = $this->UserInfoArray[0]['patronimic'];

		//стили
		$rowStyle = "display: flex; flex-direction: row; margin-bottom: 0.2rem;";
		$marginStyle = "margin: 0;";

		//вывод
        echo "
			<div style=\"display: flex; flex-direction: column; padding-left: 1.5rem; padding-top: 1.5rem;\">
				<div style=\"$rowStyle\"><p style=\"$marginStyle\"><b>Учетная запись</b></p></div>
				<div style=\"$rowStyle\"><p style=\"$marginStyle\"><b>Пользователь: </b>$lastname $firstname $patronimic</p></div>
				<div style=\"$rowStyle\"><p style=\"$marginStyle\"><b>Должность: </b>{$this->UserInfoArray[0]['post']}</p></div>
				<div style=\"$rowStyle\"><p style=\"$marginStyle\"><b>Группа: </b>{$this->UserInfoArray[0]['group']}</p></div>
				<div style=\"$rowStyle\"><p style=\"$marginStyle\"><b>Отдел: </b>{$this->UserInfoArray[0]['guild']} ({$this->UserInfoArray[0]['briefly']})</p></div>
				<div style=\"$rowStyle\"><p style=\"$marginStyle\"><b>Статус учетной записи: </b>{$this->UserInfoArray[0]['status']}</p></div>
			</div>";
	}
	public function RequireModuls()
	{
		echo "<link rel='stylesheet' type='text/css' href='/IVC/Styles/main_theme.css'>";
		echo "<script src='/IVC/Ajax/Libs/JQuery/jquery-3.5.1.min.js'></script>";
		echo "<script src='/IVC/Scripts/control.js'></script>";
	}
	public function AccessDeniedPage()
	{
		echo "<div style='position: absolute;
			    top: 45%;
			    bottom: 45%;
			    left: 0;
			    right: 0;
			    text-align: center;
			    font-size: 30px;
			    color: red;
			    font-weight: bold;'>У вас нет доступа к этой странице!
			    <br><a style='font-size:20px;' href='http://".$_SERVER['SERVER_NAME']."/IVC/index.php'>Личный кабинет</a>
			    </div>";
	}
}
class ExecuteComand extends CollectionUserInfo
{
	public function __construct()
	{
		parent::__construct();
		switch($this->PageAccess)
		{
			case 0:
				echo "Вы не авторизовались";
				return 0;
			break;
			case 1:
				$this->DefineConstr();
			break;
			case 2:
				echo "У вас недостаточно прав";
				return 0;
			break;
		}
	}
	public function DefineConstr()
	{
		//метод для перегрузки
		//наследуя класс, необходимо в этом методе вызывать главный исполняющий скрипт
	} 
}
?>