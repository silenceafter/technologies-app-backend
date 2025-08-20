<?php
set_time_limit(1800);

abstract class CustomFile
{
    protected $Name;
    protected $Extension;
    protected $Folder;
    protected $Code;
    protected $Size;
    protected $Attribute;
    protected $DateOfCreation;
    protected $DateOfChange;

    function __construct($Name, $Folder)
    { 
        $this->Name = $Name;
        $this->Folder = $Folder;
    } 
}

interface ICustomFile
{
    public function Create($from);
    public function Download();
    public function Upload();
}

interface ICsvService extends ICustomFile
{
    //другие возможные методы для файла csv
}

interface IDbfService extends ICustomFile
{
    //другие возможные методы для файла dbf
}

class Csv extends CustomFile
{
    public function __construct($Name, $Folder) 
    {
        $this->Name = $Name;
        $this->Extension = '.csv';
        $this->Folder = $Folder;        
        //
        //записать вспомогательную информацию
    }

    public static function fromDB($Name, $Folder = "") 
    {
        return new static($Name, $Folder);
    }

    public function SetFolder($folder) 
    {
        $this->folder = $folder;
    }
}

class CsvService implements ICsvService
{
    private $repository = null;
    private $db;
    private $schema;
    private $table;

    public function __construct($data, $repository) 
    {
        $this->db = $data->db;
        $this->schema = $data->schema;
        $this->table = $data->table;
        $this->repository = $repository;                   
    }

    public function Clear()
    {
        $repository = $this->repository;
        //
        if ($repository != null) {
            return $repository->Clear();
        }
        return null;
    }
   
    public function Create($from) 
    {
        $repository = $this->repository;
        //
        if ($repository != null) {
            if (trim(strtolower($from)) == 'db') {
                //db -> csv
                return $repository->CreateFromDb();
            }

            if (trim(strtolower($from)) == 'dbf') {
                //dbf -> csv
                return $repository->CreateFromDbf();
            }            
        }
        return null;
    }

    public function Download() 
    {
        $repository = $this->repository;
        //
        if ($repository != null) {
            return $repository->Download();
        }
        return null;
    }

    public function Upload() 
    {
        //
    }
}

class CsvRepositoryPSQL //implements ICustomFile
{
    private $pdo = null;
    private $db = "";
    private $schema = "";
    private $table = "";

    public function __construct($data, $pdo)
    {
        $this->db = $data->db;
        $this->schema = $data->schema;
        $this->table = $data->table;
        $this->pdo = $pdo;
    }

    public function Clear() 
    {
        $filename = $this->table . ".csv";
        if (file_exists($filename)) {
            unlink($filename);
            return true;
        }
        return false;
    }

    public function CreateFromDbf() 
    {
        //для загрузки = dbf_write.php
        $schema = $_POST['schema'];
        //
        foreach($_FILES as $file) {
            if ($_FILES && $file["error"] == UPLOAD_ERR_OK)
            {
                $name = $file['name'];
                $result = move_uploaded_file($file['tmp_name'], $name);
                $dbname = $name;
                //
                if (file_exists($dbname)) {
                    $pathname = dirname($dbname);//путь
                    $pathInfo = pathinfo($dbname);//имя файла без расширения
                    $db = dbase_open($dbname, 0);
                    //
                    if($db){
                        $numrecords = dbase_numrecords($db);//строки
                        $numfields = dbase_numfields($db);//столбцы
                        $headerArray = dbase_get_header_info($db);
                        //headerArray: 1 - name, 2 - type, 3 - length, 4 - precision, ...		
                        $date = date('d-m-y H:i:s');                        
                        $year = '20' . substr($date, 6, 2);
                        $month = substr($date, 3, 2);
                        $day = substr($date, 0, 2);
                        $hour = substr($date, 9, 2);
                        $minute = substr($date, 12, 2);
                        $seconds = substr($date, 15, 2);
                        //
                        $dateNew = DateTime::createFromFormat("Y-m-d H:i:s", "$year-$month-$day $hour:$minute:$seconds");
                        //33% после загрузки dbf

                        //создание схема.progressbar
                        //create_progressbar($pdo, $schema);

                        //создание csv
                        $csvname = $pathname . '\\' . $pathInfo['filename'] . '.csv';
                        if (file_exists($csvname)) {
                            unlink($csvname);
                        }
                        
                        $fp = fopen($csvname, 'w');//a+
                        fputs($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
                        //
                        $rowCnt = 0;
                        $rowCntAll = 0;//?
                        $globalRowCnt = $numrecords;//кол-во загруженных строк
                        $csvArray = array();
                        $stringArray = array();
                        $headers = "";//названия колонок в строку для csv
                        foreach ($headerArray as $headerItem) {				
                            $headers .= $headerItem['name'] . ', ';//upd
                            $stringArray[] = $headerItem['name'];			
                        }
                        $csvArray[count($csvArray)] = $stringArray;
                        $headers = substr($headers, 0, strlen($headers) - 2);//upd
                        $rowCnt++;//upd
                
                        $uploadValue = 33 + ($rowCntAll / $numrecords) * 100 / 3;			
                        setProgress($pdo, $uploadValue, $name, $schema);
                        //
                        for ($i = 1; $i <= $numrecords; $i++)
                        {						
                            if ($rowCnt >= 1000) {
                                //выполнить insert и продолжать дальше
                                foreach ($csvArray as $fields) {
                                    if ($fields != null) {
                                        fputcsv($fp, $fields);
                                    }					
                                }
                                $csvArray = array();//обнулить массив
                                $uploadValue = 33 + ($rowCntAll / $numrecords) * 100 / 3;//$uploadValue += $uploadStep;
                                $rowCntAll += $rowCnt;//
                                $rowCnt = 0;
                                //												
                                setProgress($pdo, $uploadValue, $name, $schema);
                            }

                            $csvArray[] = array();
                            $arrayCount = count($csvArray);			
                            $stringArray = array();
                            //
                            $row = dbase_get_record_with_names($db, $i);
                            $empty = array();
                            foreach ($row as $key => $value)
                            {				
                                foreach ($headerArray as $headerItem) {
                                    if ($headerItem['name'] == $key) {
                                        //нашли соответствующее поле, смотрим тип данных
                                        switch($headerItem['type']) {
                                            case 'character':
                                                if ($key != 'deleted') {
                                                    trim($value) == '' ? $empty[] = true : $empty[] = false;//upd
                                                    $string866 = $value;
                                                    $stringUtf8 = iconv('CP866//IGNORE', 'UTF-8//IGNORE', $string866);
                                                    $stringArray[] = $stringUtf8;							
                                                }
                                                break;

                                            case 'date':
                                                //строка -> дата
                                                if ($key != 'deleted') {
                                                    trim($value) == '' ? $empty[] = true : $empty[] = false;//upd									
                                                    $year = substr($value, 0, 4);
                                                    $month = substr($value, 4, 2);
                                                    $day = substr($value, 6, 2);
                                                    //
                                                    $date = strtotime($year . '-' . $month . '-' . $day);//yyyymmdd > yyyy-mm-dd
                                                    $newformat = date('Y-m-d', $date);
                                                    $stringArray[] = $newformat;
                                                }
                                                break;

                                            case 'number':
                                                //строка -> число
                                                //numeric ?
                                                if ($key != 'deleted') {
                                                    trim($value) == 0 ? $empty[] = true : $empty[] = false;//upd
                                                    $stringArray[] = $value;								
                                                }
                                                break;
                                        }
                                        break;
                                    }
                                }					
                            }

                            $cnt = 0;
                            foreach($empty as $element) {
                                if ($element == true) {
                                    $cnt++;								
                                }
                            }
                            //
                            if ($cnt != count($empty)) {
                                $csvArray[$arrayCount - 1] = $stringArray;
                                
                            } else {
                                //удаляем пустую строку из массива
                                unset($csvArray[$arrayCount - 1]);
                                $globalRowCnt--;
                            }
                            $rowCnt += 1;						
                        }

                        if ($rowCnt < 1000) {
                            //выполнить последний insert
                            foreach ($csvArray as $fields) {
                                if ($fields != null) {
                                    fputcsv($fp, $fields);
                                }				
                            }
                            $rowCntAll += $rowCnt;
                            //
                            $uploadValue = 33 + ($rowCntAll / $numrecords) * 100 / 3;//$uploadValue = 50;
                            setProgress($pdo, $uploadValue, $name, $schema);
                        }
                        fclose($fp);

                        //подключение к postgres
                        $table = $schema . '.' . $pathInfo['filename'];//'schema1.table2'
                        //если таблица существует -> удаляем
                        $dropTable = "DROP TABLE IF EXISTS $table";
                        $tableReturn = $pdo->exec($dropTable);
                        //
                        //создаем таблицу
                        $newTable = "CREATE TABLE IF NOT EXISTS $table ( ";
                        foreach ($headerArray as $headerItem) {
                            switch($headerItem['type']) {
                                case 'character':
                                    $newTable .= $headerItem['name'] . ' ' . $headerItem['type'] . ' (' . $headerItem['length'] . ')';//вмещаем дополнительные 2 символа => "" ?
                                    break;

                                case 'date':
                                    $newTable .= $headerItem['name'] . ' ' . $headerItem['type'];
                                    break;

                                case 'number':
                                    //не numeric? это тип из vsfox
                                    $newTable .= $headerItem['name'] . ' ' . 'numeric' . '(' . $headerItem['length'] . ',' . $headerItem['precision'] . ')';
                                    break;
                                default:
                                    //echo "в таблице $table есть реквизиты, типы которых не будут обработаны в процессе выполнения программы " . '<br>';
                                    throw new Exception("нестандартный тип данных");
                                    break;
                            }			
                            $newTable .= ', ';
                        }

                        $newTable = substr($newTable, 0, strlen($newTable) - 2);
                        $newTable .= ')';
                        //
                        $pdo->exec($newTable);															
                        $date = date('d-m-y H:i:s');
                        //echo 'кол-во строк ' . $globalRowCnt . '<br>';//можно сделать запрос vacuum tablename, select reltuples...
                        //echo 'Время завершения: ' . $date . '<br>';
                        dbase_close($db);
                        //echo 'Таблица ' . $dbname . ' загружена в PostgreSQL <br>';				

                        $response = new class($name, $headers, $csvname, $numrecords) {
                            public $table;
                            public $headers;
                            public $csvname;
                            public $numrecords;
                            
                            function __construct($table, $headers, $csvname, $numrecords) {
                                $this->table = $table;
                                $this->headers = $headers;
                                $this->csvname = $csvname;
                                $this->numrecords = $numrecords;
                            }						
                        };
                    }		
                } else {
                    //echo "Файл '$dbname' не найден" . '<br>';
                }
            }
            //echo json_encode($response, JSON_UNESCAPED_UNICODE);
        }
    }

    public function CreateFromDb()
    {
        //для выгрузки
        $schema = $this->schema;
        $table = $this->table;
        $pdo = $this->pdo;
        $response = null;

        if ($schema != '' && $table != '') {
            try {            
                $Query = $pdo->prepare("SELECT
                    CHARACTER_MAXIMUM_LENGTH,
                    COLUMN_NAME,
                    NUMERIC_PRECISION,
                    NUMERIC_SCALE,
                    DATA_TYPE 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE LOWER(TABLE_SCHEMA) = '" . $schema . "' AND LOWER(TABLE_NAME) = '" . $table . "'");
                $Query->execute();
                $array = $Query->fetchAll(PDO::FETCH_ASSOC);
                //
                //параметры полей таблицы POSTGRESQL
                $columnsPostgres = array();
                foreach ($array as $column) {
                    //column_name
                    $colname = $column['column_name'];
                    if (strlen($colname) > 10) {
                        $colname = substr($colname, 0, 10);
                    }
        
                    if ($column['data_type'] == 'character' ||
                        $column['data_type'] == 'character varying' ||
                        $column['data_type'] == 'text' ||
                        $column['data_type'] == 'varchar' ||
                        $column['data_type'] == 'nvarchar') {
                        //символьный тип
                        $find = true;
                        //
                        $columnsPostgres[] = array(
                            'name' => $column['column_name'],
                            'sqlsrv:decl_type' => $column['data_type'],               
                            'len' => $column['character_maximum_length'],
                            'precision' => $column['numeric_scale']);
                        if ($column['character_maximum_length'] == 255 ||
                            $column['character_maximum_length'] == 256) {
                            $column['character_maximum_length'] = 254;//не превышаем, max=254
                        }
                        $columnsDBF[] = array($this->CheckFieldLength($colname), "C", $column['character_maximum_length']);            
                        continue;
                    }
        
                    if ($column['data_type'] == 'bigint' ||
                        $column['data_type'] == 'integer' ||
                        $column['data_type'] == 'money' ||                
                        $column['data_type'] == 'numeric' ||
                        $column['data_type'] == 'real' ||
                        $column['data_type'] == 'smallint' ||
                        $column['data_type'] == 'double precision') {
                        //числовой тип
                        $find = true;            
                        $columnsPostgres[] = array(
                            'name' => $column['column_name'],
                            'sqlsrv:decl_type' => $column['data_type'],
                            'len' => $column['numeric_precision'],
                            'precision' => $column['numeric_scale']);
                        $columnsDBF[] = array($this->CheckFieldLength($colname), "N", $column['numeric_precision'], $column['numeric_scale']);
                        continue;
                    }
        
                    if ($column['data_type'] == 'date') {
                        //дата
                        $find = true;            
                        $columnsPostgres[] = array(
                            'name' => $column['column_name'], 
                            'sqlsrv:decl_type' => $column['data_type'],
                            'len' => $column['numeric_precision'],
                            'precision' => $column['numeric_scale']);
                        $columnsDBF[] = array($this->CheckFieldLength($colname), "D");
                        continue;
                    }
        
                    if (!$find) {
                        //другой тип данных
                        /*echo 'В таблице ' . $tabname . ' используется тип данных ' .
                        $column['data_type'] . ', для которого нужна отдельная обработка';*/
                        break;
                    }
                }

                if ($find) {                   
                    $columns = '';
                    foreach ($columnsPostgres as $element) {
                        $columns .= $element['name'] . ', ';
                    }
                    
                    if (!empty($columns)) {
                        $columns = substr($columns, 0, strlen($columns) - 2);
                    }
                    
                    $folder = __DIR__;//"c:\work\dbfcreate\\";
                    $csv = "$folder\\$table.csv";
                    if (file_exists($csv)) {
                        unlink($csv);
                    }

                    //получаем данные
                    $QueryStr = 'COPY ' . $schema . '.' . $table . "( " . $columns . " ) TO '" . $folder . '\\' . $table . ".csv' DELIMITER ',' CSV HEADER";
                    $Query = $pdo->prepare($QueryStr);
                    $result = $Query->execute();                                                        
                }

                if (file_exists($csv)) {
                    $link = '//' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/';//. basename($csv);
                    $vv = $link . $table . '.csv';
                    $size = filesize($csv);
                    $sizeMb = round($size / 1024 / 1024, 1);//mb
                    //
                    $response = new class($link, $sizeMb) {
                        public $table;
                        public $code;
                        public $size;
                        public $dateOfCreation;
                        public $dateOfChange;
                        //
                        function __construct($value, $size)
                        {
                            $this->table = $value;
                            $this->code = "";                            
                            $this->size = $size;
                            $now = date("Y-m-d H:i:s");
                            $this->dateOfCreation = $now;
                            $this->dateOfChange = $now;
                        }
                    };
                }
            } catch(Exception $e) {
                $e->getMessage();
            }                
        }
        return $response;
        //echo json_encode($response, JSON_UNESCAPED_UNICODE);
    }

    public function CheckFieldLength($filedname) 
    {
        //имена реквизитов для DBF-файла не более 10-ти символов
        if (strlen($filedname) >= 10) {
            $filedname = substr($filedname, 0, 10);
        }
        return $filedname;
    }

    public function Download()
    {
        $link = '//' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/' . $this->table . '.csv';
        return new class($link) {
            public $table;
            //
            function __construct($value)
            {
                $this->table = $value;                
            }
        };
    }

    public function Upload() 
    {
        //
    }
}

class Dbf extends CustomFile
{
    public function __construct($Name, $Folder) 
    {
        $this->Name = $Name;
        $this->Extension = '.dbf';
        $this->Folder = $Folder;        
        //
        //записать вспомогательную информацию
    }
    
    public static function fromDB($Name, $Folder = "") 
    {
        return new static($Name, $Folder);
    }

    public function SetFolder($folder) 
    {
        $this->folder = $folder;
    }
}

class DbfService implements IDbfService
{
    //private $service = null;
    private $repository = null;
    private $db;
    private $schema;
    private $table;

    public function __construct($data, $repository) 
    {
        $this->db = $data->db;
        $this->schema = $data->schema;
        $this->table = $data->table;
        $this->repository = $repository;                   
    }

    public function Clear()
    {
        $repository = $this->repository;
        //
        if ($repository != null) {
            return $repository->Clear();
        }
        return null;
    }
    
    public function Create($from = "") 
    {
        $repository = $this->repository;
        //
        if ($repository != null) {
            if (trim(strtolower($from)) == 'db') {
                //db -> dbf
                return $repository->CreateFromDb();
            }

            if (trim(strtolower($from)) == 'csv') {
                //csv -> dbf
                return $repository->CreateFromCsv();
            }
        }
        return null;
    }

    public function Download() 
    {
        $repository = $this->repository;
        //
        if ($repository != null) {
            return $repository->Download();
        }
        return null;
    }

    public function GetParameters()
    {
        $repository = $this->repository;
        //
        if ($repository != null) {
            return $repository->GetParameters();
        }
        return null;
    }

    public function Upload() 
    {
        //реализация метода по умолчанию
    }

    public function UploadWithParameters($part, $data1, $data2)
    {
        $repository = $this->repository;
        //
        if ($repository != null) {
            if ($part == 1) {
                //часть 1
                return $repository->Upload1($data1, $data2);
            }

            if ($part == 2) {
                //часть 2
                return $repository->Upload2($data1, $data2);
            }
        }
        return null;
    }
}

class DbfRepositoryPSQL //implements ICustomFile
{
    private $pdo = null;
    private $db = "";
    private $schema = "";
    private $table = "";
    private $headers = "";
    private $csvname = "";

    public function __construct($data, $pdo) {
        $this->db = $data->db;
        $this->schema = $data->schema; 
        $this->table = $data->table;
        $this->pdo = $pdo;
        //
        if (property_exists($data, 'headers')) {
            $this->headers = $data->headers;
        }

        if (property_exists($data, 'csvname')) {
            $this->csvname = $data->csvname;
        }
    }

    public function Clear() 
    {
        $filename = $this->table . ".dbf";
        if (file_exists($filename)) {
            unlink($filename);
            return true;
        }
        return false;
    }
    
    public function CreateFromCsv()
    {
    }

    public function CreateFromDb() 
    {
        $schema = $this->schema;
        $table = $this->table;
        $pdo = $this->pdo;
        $response = null;

        if ($schema != '' && $table != '') {
            try {            
                $Query = $pdo->prepare("SELECT
                    CHARACTER_MAXIMUM_LENGTH,
                    COLUMN_NAME,
                    NUMERIC_PRECISION,
                    NUMERIC_SCALE,
                    DATA_TYPE 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE LOWER(TABLE_SCHEMA) = '" . $schema . "' AND LOWER(TABLE_NAME) = '" . $table . "'");
                $Query->execute();
                $array = $Query->fetchAll(PDO::FETCH_ASSOC);
                
                //параметры полей таблицы POSTGRESQL
                $columnsPostgres = array();
                foreach ($array as $column) {
                    //column_name
                    $colname = $column['column_name'];
                    if (strlen($colname) > 10) {
                        $colname = substr($colname, 0, 10);
                    }
        
                    if ($column['data_type'] == 'character' || $column['data_type'] == 'character varying' ||
                        $column['data_type'] == 'text' || $column['data_type'] == 'varchar' ||
                        $column['data_type'] == 'nvarchar') {
                        //символьный тип
                        $find = true;                        
                        $columnsPostgres[] = array(
                            'name' => $column['column_name'], 
                            'sqlsrv:decl_type' => $column['data_type'],               
                            'len' => $column['character_maximum_length'], 
                            'precision' => $column['numeric_scale']);
                        //
                        if ($column['character_maximum_length'] == 255 || $column['character_maximum_length'] == 256) {
                            $column['character_maximum_length'] = 254;//не превышаем, max=254
                        }
                        $columnsDBF[] = array($this->CheckFieldLength($colname), "C", $column['character_maximum_length']);            
                        continue;
                    }
        
                    if ($column['data_type'] == 'bigint' || $column['data_type'] == 'integer' ||
                        $column['data_type'] == 'money' || $column['data_type'] == 'numeric' ||
                        $column['data_type'] == 'real' || $column['data_type'] == 'smallint' ||
                        $column['data_type'] == 'double precision') {
                        //числовой тип
                        $find = true;            
                        $columnsPostgres[] = array(
                            'name' => $column['column_name'],
                            'sqlsrv:decl_type' => $column['data_type'],
                            'len' => $column['numeric_precision'],
                            'precision' => $column['numeric_scale']);
                        $columnsDBF[] = array($this->CheckFieldLength($colname), "N", $column['numeric_precision'], $column['numeric_scale']);
                        continue;
                    }
        
                    if ($column['data_type'] == 'date') {
                        //дата
                        $find = true;            
                        $columnsPostgres[] = array(
                            'name' => $column['column_name'], 
                            'sqlsrv:decl_type' => $column['data_type'],
                            'len' => $column['numeric_precision'],
                            'precision' => $column['numeric_scale']);
                        $columnsDBF[] = array($this->CheckFieldLength($colname), "D");
                        continue;
                    }
        
                    if (!$find) {
                        //другой тип данных
                        /*echo 'В таблице ' . $tabname . ' используется тип данных ' .
                        $column['data_type'] . ', для которого нужна отдельная обработка';*/
                        break;
                    }
                }

                if ($find) {
                    $folder = __DIR__;//"c:\work\dbfcreate\\";
                    $dbfname = "$folder\\$table.dbf";
                    //проверяем, существует ли файл
                    if (file_exists($dbfname)) {
                        unlink($dbfname);
                    }

                    //создаём dbf-файл
                    if (dbase_create($dbfname, $columnsDBF, DBASE_TYPE_DBASE)) {//DBASE_TYPE_DBASE
                        //открыть dbf-файл
                        $db = dbase_open($dbfname, 2);
                        if($db) {
                            $columns = '';
                            foreach ($columnsPostgres as $element) {
                                $columns .= $element['name'] . ', ';
                            }
                            
                            if (!empty($columns)) {
                                $columns = substr($columns, 0, strlen($columns) - 2);
                            }
                            
                            $folder = __DIR__;//"c:\work\dbfcreate\\";
                            $csv = "$folder\\$table.csv";
                            if (file_exists($csv)) {
                                unlink($csv);
                            }

                            //получаем данные
                            $QueryStr = 'COPY ' . $schema . '.' . $table . "( " . $columns . " ) TO '" . $folder . '\\' . $table . ".csv' DELIMITER ',' CSV HEADER";
                            $Query = $pdo->prepare($QueryStr);
                            $result = $Query->execute();                                  

                            $fp = fopen($csv, 'r');
                            $data = fgetcsv($fp);              
                            while (($data = fgetcsv($fp)) !== false) {
                                //приводим к нужному типу
                                for ($i = 0; $i < count($columnsDBF); $i++) {
                                    switch($columnsDBF[$i][1]) {
                                        case "C":
                                            $data[$i] = iconv('utf-8//IGNORE', 'CP866//IGNORE', $data[$i]);
                                            break;

                                        case "D":                                    
                                            $data[$i] = str_replace("-", "", $data[$i]);
                                            if (trim($data[$i]) == '19700101')//
                                                $data[$i] = '';//
                                            break;
                                    }
                                }
                                        
                                if (!dbase_add_record($db, $data)) {
                                    //echo 'Ошибка, не удалось добавить строку в dbf-файл' . '<br>';
                                    break;
                                }
                            }

                            //удалить csv-файл после выгрузки
                            if (file_exists($csv)) {
                                unlink($csv);
                            }
                        }
                    } else {
                        //echo "Ошибка, не получается создать базу данных\n";
                    }  
                }

                if (file_exists($dbfname)) {
                    $link = '//' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/';//. basename($csv);
                    $size = filesize($dbfname);
                    $sizeMb = round($size / 1024 / 1024, 1);//mb
                    //
                    $response = new class($link, $sizeMb) {
                        public $table;
                        public $code;
                        public $size;
                        public $dateOfCreation;
                        public $dateOfChange;
                        //
                        function __construct($value, $size)
                        {
                            $this->table = $value;
                            $this->code = "";                            
                            $this->size = $size;
                            $now = date("Y-m-d H:i:s");
                            $this->dateOfCreation = $now;
                            $this->dateOfChange = $now;
                        }
                    };
                }
            } catch(Exception $e) {
                $e->getMessage();
            }
        }
        return $response;
    }

    public function CheckFieldLength($filedname) 
    {
        //имена реквизитов для DBF-файла не более 10-ти символов
        if (strlen($filedname) >= 10) {
            $filedname = substr($filedname, 0, 10);
        }
        return $filedname;
    }

    public function Download() 
    {  
        $link = '//' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/' . $this->table . '.dbf';
        return new class($link) {
            public $table;
            //
            function __construct($value)
            {
                $this->table = $value;                
            }
        };
    }

    public function GetParameters()
    {//dbf_info.php
        //информация о файле
        $schema = $_POST['schema'];
        $response = null;
        $pdo = $this->pdo;
        //
        foreach($_FILES as $file) {
            if ($_FILES && $file["error"] == UPLOAD_ERR_OK)
            {
                $name = $file['name'];
                $result = move_uploaded_file($file['tmp_name'], $name);
                $dbname = $name;
                //
                if (file_exists($dbname)) {
                    $pathname = dirname($dbname);//путь
                    $pathInfo = pathinfo($dbname);//имя файла без расширения
                    $db = dbase_open($dbname, 0);  
                    //
                    if($db){     
                        $response = dbase_get_header_info($db);
                        /*for($i = 0; $i < count($response); $i++)
                        {
                            if (trim(strtolower($response[$i]['type'])) == 'number')
                                //number -> numeric
                                $response[$i]['type'] = 'numeric';
                        }*/
                        //headerArray: 1 - name, 2 - type, 3 - length, 4 - precision
                    }
                }
            }
        }
        return $response;
    }

    public function Upload1($headers_new, $headers_old)
    {//dbf_write.php
        //копирование файла на сервер, создание csv        
        $schema = $_POST['schema'];
        $response = null;
        $pdo = $this->pdo;
        $connection_active = true;
        //
        foreach($_FILES as $file) {
            if ($_FILES && $file["error"] == UPLOAD_ERR_OK)
            {
                $dbname = $file['name'];
                if (!file_exists($dbname)) {
                    if (!move_uploaded_file($file['tmp_name'], $dbname))
                        return null;
                }

                $pathname = dirname($dbname);//путь
                $pathInfo = pathinfo($dbname);//имя файла без расширения
                $db = dbase_open($dbname, 0);
                //
                if($db){
                    $numrecords = dbase_numrecords($db);//строки
                    $numfields = dbase_numfields($db);//столбцы

                    //headerArray                        
                    if (count($headers_new) > 0 && count($headers_old) > 0) {
                        //берем названия и значения реквизитов до изменений
                        $headerArray = $headers_old;//старые данные
                        $headerArrayNew = $headers_new;//новые данные

                        //проверка массива структуры
                        $deleteArray = array();
                        for($i = 0; $i < count($headerArray); $i++)
                        {
                            //в новом массиве может не быть реквизитов
                            $find = false; 
                            //поиск реквизита в оригинальном массиве
                            foreach($headerArrayNew as $headerItemNew) 
                            {
                                if (trim(strtolower($headerItemNew['id'])) == trim(strtolower($headerArray[$i]['id']))) {
                                    //id найдено
                                    $find = true;                                    
                                    break;
                                }                                                
                            }

                            if (!$find)
                                $deleteArray[] = $i;
                        }

                        //удаление элементов
                        if (count($deleteArray) > 0) {
                            foreach($deleteArray as $deleteItem)
                                unset($headerArray[$deleteItem]);
                        }
                    } else {
                        $headerArray = dbase_get_header_info($db);//headerArray: 1-name, 2-type, 3-length, 4-precision
                    }                        
                                                                                                    
                    $date = date('d-m-y H:i:s');
                    $year = '20' . substr($date, 6, 2);
                    $month = substr($date, 3, 2);
                    $day = substr($date, 0, 2);
                    $hour = substr($date, 9, 2);
                    $minute = substr($date, 12, 2);
                    $seconds = substr($date, 15, 2);
                    //
                    $dateNew = DateTime::createFromFormat("Y-m-d H:i:s", "$year-$month-$day $hour:$minute:$seconds");
                    //создание объекта progressbar
                    if (!Progressbar::ExistXml($pathInfo['filename'])) {
                        Progressbar::CreateXml($pathInfo['filename']);
                    }

                    //создание csv
                    $csvname = $pathname . '\\' . $pathInfo['filename'] . '.csv';
                    if (file_exists($csvname)) {
                        unlink($csvname);
                    }
                    
                    $fp = fopen($csvname, 'w');//a+
                    fputs($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
                    //
                    $rowCnt = 0;
                    $rowCntAll = 0;//?
                    $globalRowCnt = $numrecords;//кол-во загруженных строк
                    $csvArray = array();
                    $stringArray = array();
                    $headers = "";//названия колонок в строку для csv
                    //
                    foreach ($headerArray as $headerItem) {				
                        $headers .= $headerItem['name'] . ', ';//upd
                        $stringArray[] = $headerItem['name'];			
                    }

                    $csvArray[count($csvArray)] = $stringArray;
                    $headers = substr($headers, 0, strlen($headers) - 2);//upd
                    $rowCnt++;//upd
            
                    $uploadValue = ($rowCntAll / $numrecords) * 100 / 2;//$uploadValue = 33 + ($rowCntAll / $numrecords) * 100 / 3;
                    if (!$this->IsPdoAlive($pdo) ||
                        Progressbar::GetParameter('stop', $schema, $pathInfo['filename'])) {
                        fclose($fp);
                        return $response;    
                    }
                    Progressbar::SetProgress($uploadValue, $schema, $pathInfo['filename']);//setProgress($pdo, $uploadValue, $name, $schema);                                                 
                    //
                    for ($i = 1; $i <= $numrecords; $i++)
                    {						
                        if ($rowCnt >= 1000) {
                            //выполнить insert и продолжать дальше
                            foreach ($csvArray as $fields) {
                                if ($fields != null) {
                                    fputcsv($fp, $fields);
                                }					
                            }
                            $csvArray = array();//обнулить массив
                            $uploadValue = ($rowCntAll / $numrecords) * 100 / 2;//$uploadValue = 33 + ($rowCntAll / $numrecords) * 100 / 3;
                            $rowCntAll += $rowCnt;//
                            $rowCnt = 0;
                            //
                            if (!$this->IsPdoAlive($pdo) ||
                                Progressbar::GetParameter('stop', $schema, $pathInfo['filename'])) {
                                fclose($fp);
                                return $response;    
                            }
                            Progressbar::SetProgress($uploadValue, $schema, $pathInfo['filename']);//setProgress($pdo, $uploadValue, $name, $schema);                                                                  
                        }

                        $csvArray[] = array();
                        $arrayCount = count($csvArray);			
                        $stringArray = array();
                        //
                        $row = dbase_get_record_with_names($db, $i);
                        $empty = array();
                        foreach ($row as $key => $value)
                        {				
                            foreach ($headerArray as $headerItem) {
                                if ($headerItem['name'] == $key) {
                                    //нашли соответствующее поле, смотрим тип данных
                                    switch($headerItem['type']) {
                                        case 'character':
                                            if ($key != 'deleted') {
                                                trim($value) == '' ? $empty[] = true : $empty[] = false;//upd
                                                $string866 = $value;
                                                $stringUtf8 = iconv('CP866//IGNORE', 'UTF-8//IGNORE', $string866);
                                                $stringArray[] = $stringUtf8;
                                            }
                                            break;

                                        case 'date':
                                            //строка -> дата
                                            if ($key != 'deleted') {
                                                trim($value) == '' ? $empty[] = true : $empty[] = false;//upd									
                                                $year = substr($value, 0, 4);
                                                $month = substr($value, 4, 2);
                                                $day = substr($value, 6, 2);
                                                //
                                                $date = strtotime($year . '-' . $month . '-' . $day);//yyyymmdd > yyyy-mm-dd
                                                $newformat = date('Y-m-d', $date);
                                                $stringArray[] = $newformat;
                                            }
                                            break;

                                        case 'number':
                                            //строка -> число
                                            //numeric ?
                                            if ($key != 'deleted') {
                                                trim($value) == 0 ? $empty[] = true : $empty[] = false;//upd
                                                $stringArray[] = $value;								
                                            }
                                            break;
                                    }
                                    break;
                                }
                            }					
                        }

                        $cnt = 0;
                        foreach($empty as $element) {
                            if ($element == true) {
                                $cnt++;								
                            }
                        }
                        //
                        if ($cnt != count($empty)) {
                            $csvArray[$arrayCount - 1] = $stringArray;
                            
                        } else {
                            //удаляем пустую строку из массива
                            unset($csvArray[$arrayCount - 1]);
                            $globalRowCnt--;
                        }
                        $rowCnt += 1;						
                    }

                    if ($rowCnt < 1000) {
                        //выполнить последний insert
                        foreach ($csvArray as $fields) {
                            if ($fields != null) {
                                fputcsv($fp, $fields);
                            }				
                        }
                        $rowCntAll += $rowCnt;
                        //
                        $uploadValue = ($rowCntAll / $numrecords) * 100 / 2;//$uploadValue = 33 + ($rowCntAll / $numrecords) * 100 / 3;                                                                                    
                        if (!$this->IsPdoAlive($pdo) ||
                            Progressbar::GetParameter('stop', $schema, $pathInfo['filename'])) {
                            fclose($fp);
                            return $response;    
                        }
                        Progressbar::SetProgress($uploadValue, $schema, $pathInfo['filename']);//setProgress($pdo, $uploadValue, $name, $schema);
                    }
                    fclose($fp);

                    //подключение к postgres
                    $table = $pathInfo['filename'];//$schema . '.' . $pathInfo['filename'];
                    try {                    
                        $this->BeginTransaction();
                        //если таблица существует -> удаляем
                        $dropTable = "DROP TABLE IF EXISTS $schema.tmp_$table";//$table
                        $tableReturn = $pdo->exec($dropTable);
                        
                        //создаем таблицу не затрагивая существующую
                        $newTable = "CREATE TABLE IF NOT EXISTS $schema.tmp_$table ( ";
                        foreach ($headerArray as $headerItem) {
                            switch($headerItem['type']) {
                                case 'character':
                                    $newTable .= $headerItem['name'] . ' ' . $headerItem['type'] . ' (' . $headerItem['length'] . ')';//вмещаем дополнительные 2 символа => "" ?
                                    break;

                                case 'date':
                                    $newTable .= $headerItem['name'] . ' ' . $headerItem['type'];//NOT NULL?
                                    break;

                                case 'number':
                                    //number = тип из vsfox, numeric = postgresql
                                    $newTable .= $headerItem['name'] . ' ' . 'numeric' . '(' . $headerItem['length'] . ',' . $headerItem['precision'] . ')';
                                    break;

                                default:
                                    //echo "в таблице $table есть реквизиты, типы которых не будут обработаны в процессе выполнения программы " . '<br>';
                                    throw new Exception("нестандартный тип данных");
                                    break;
                            }
                            $newTable .= ', ';
                        }

                        $newTable = substr($newTable, 0, strlen($newTable) - 2);
                        $newTable .= ')';
                        //
                        $pdo->exec($newTable);
                        //фиксируем изменения
                        $this->CommitTransaction();//commit1

                        $response = new class($dbname, $headers, $csvname, $numrecords) {//$name
                            public $table;
                            public $headers;
                            public $csvname;
                            public $numrecords;
                            
                            function __construct($table, $headers, $csvname, $numrecords) {
                                $this->table = $table;
                                $this->headers = $headers;
                                $this->csvname = $csvname;
                                $this->numrecords = $numrecords;
                            }						
                        };
                    } catch (Exception $e) {
                        $this->RollbackTransaction();
                    }
                                                            															
                    $date = date('d-m-y H:i:s');
                    dbase_close($db);			
                    return $response;                    
                }
            }
            //echo json_encode($response, JSON_UNESCAPED_UNICODE);
        }
        return $response;
    }

    public function Upload2($headers_new, $headers_old)
    {//postgres_upload.php
        //завершение загрузки
        $pdo = $this->pdo;
        $headers = $this->headers;//?
        $csvname = $this->csvname;//
        //
        $table = $this->table;
        $table = basename($table, '.dbf');
        $schema = $this->schema;
        //
        $pathInfo = pathinfo(__FILE__);//$_SERVER['DOCUMENT_ROOT'];
        $currentPath = $pathInfo['dirname'] . '\\' . $csvname;

        //sql
        try {
            $this->BeginTransaction();
            //bulk insert
            $newTable = "COPY $schema.tmp_$table ( $headers ) FROM '$currentPath' DELIMITER ',' CSV HEADER";//$newTable = "COPY $schema.$table ( $headers ) FROM '$currentPath' DELIMITER ',' CSV HEADER";
            $pdo->exec($newTable);

            //vacuum (без транзакции, иначе ошибка)
            //$Query = "VACUUM $schema.tmp_$table";
            //$pdo->exec($Query);

            //alter table
            if (count($headers_new) > 0 && count($headers_old) > 0) {
                //alter table i=0 name, i=1 type, i=2 length, i=3 precision, i=4 format, i=5 offset
                for($i = 0; $i < count($headers_old); $i++)
                {
                    //поиск реквизита в оригинальном массиве                            
                    foreach($headers_new as $headers_new_item)
                    {                    
                        if (trim(strtolower($headers_new_item['id'])) == trim(strtolower($headers_old[$i]['id']))) {
                            //игнорируем столбцы, которые не будут загружаться
                            if (trim(strtolower($headers_new_item['enabled'])) == "false")
                                continue;

                            $newTable = "";                    
                            //type new/length or precision new
                            if (trim(strtolower($headers_new_item['type'])) != trim(strtolower($headers_old[$i]['type'])) ||
                                trim(strtolower($headers_new_item['length'])) != trim(strtolower($headers_old[$i]['length'])) ||
                                trim(strtolower($headers_new_item['precision'])) != trim(strtolower($headers_old[$i]['precision']))
                            ) {
                                $сonvertSqlString = $this->GetConvertSqlString($headers_old[$i], $headers_new_item);
                                if ($сonvertSqlString != '') {
                                    $newTable = "ALTER TABLE $schema.tmp_$table $сonvertSqlString";
                                    $pdo->exec($newTable);
                                }                           
                            }

                            //name new
                            if (trim(strtolower($headers_new_item['name'])) != trim(strtolower($headers_old[$i]['name']))) {
                                $column_name_old = trim(strtolower($headers_old[$i]['name']));
                                $column_name_new = trim(strtolower($headers_new_item['name']));
                                //
                                $newTable = "ALTER TABLE $schema.tmp_$table RENAME COLUMN $column_name_old TO $column_name_new";
                                $pdo->exec($newTable);
                            }
                            break;
                        }
                    }     
                }                                
            }
            //проверяем, есть ли оригинальная таблица, чтобы не потерять существующие данные
            $text = "SELECT EXISTS (SELECT FROM pg_tables WHERE schemaname = '$schema' AND tablename = '$table');";
            $Query = $pdo->prepare($text);
            $Query->execute();
            $response_array = $Query->fetchAll(PDO::FETCH_ASSOC);
            //
            if (count($response_array) > 0) {
                if ($response_array[0]['exists'] == "true") {
                    //таблица существует, нужно переименовать основную в копию
                    $Query = "ALTER TABLE $schema.$table RENAME TO $ex_original_$table";
                    $pdo->exec($Query);                                        
                }
                
                //переименовать tmp в основную таблицу, иначе -//-
                $Query = "ALTER TABLE $schema.tmp_$table RENAME TO $table";
                $pdo->exec($Query);
                
                //удалить копию старых данных, иначе -//-
                $text = "SELECT EXISTS (SELECT FROM pg_tables WHERE schemaname = '$schema' AND tablename = 'ex_original_$table');";
                $Query = $pdo->prepare($text);
                $Query->execute();
                $response_array = $Query->fetchAll(PDO::FETCH_ASSOC);
                //
                if (count($response_array) > 0) {
                    if ($response_array[0]['exists'] == "true") {
                        $Query = "DROP TABLE IF EXISTS $schema.ex_original_$table";
                        $pdo->exec($Query);                        
                    }
                }                
                $this->CommitTransaction();//commit2
            }
        } catch(Exception $e) {
            $this->RollbackTransaction();
            //
            $this->BeginTransaction();
            $text = "SELECT EXISTS (SELECT FROM pg_tables WHERE schemaname = '$schema' AND tablename = 'tmp_$table');";
            $Query = $pdo->prepare($text);
            $Query->execute();
            $response_array = $Query->fetchAll(PDO::FETCH_ASSOC);
            //
            if (count($response_array) > 0) {
                if ($response_array[0]['exists'] == "true") {
                    $Query = "DROP TABLE IF EXISTS $schema.tmp_$table";
                    $pdo->exec($Query);                        
                }
            }
            $this->CommitTransaction();//commit_exception
        }
        /**
         * фатальная ошибка (бд/сервер не отвечает) может чиститься с помощью кнопки очистки (админ-панель)
         * общий rollback => в схеме останется tmp_таблица => проверка существования => удаление
         */
                
        sleep(5);//10
        if (Progressbar::ExistXml($table))
            Progressbar::SetProgress(100, $schema, $table);
    }

    public function BeginTransaction()
    {
        $pdo = $this->pdo;
        $response = false;
        //
        try {
            $Query = "BEGIN";
            $pdo->exec($Query);
            $response = true;
        } catch(Exception $e) {
            //
        }
        return $response;
    }

    public function CommitTransaction()
    {
        $pdo = $this->pdo;
        $response = false;
        //
        try {
            $Query = "COMMIT";
            $pdo->exec($Query);
            $response = true;
        } catch(Exception $e) {
            //
        }
        return $response;
    }

    public function RollbackTransaction()
    {
        $pdo = $this->pdo;
        $response = false;
        //
        try {
            $Query = "ROLLBACK";
            $pdo->exec($Query);
            $response = true;
        } catch(Exception $e) {
            //
        }
        return $response;
    }

    public function GetConvertSqlString($data1_item, $data2_item)
    {
        //вернуть строку для alter table
        //data1
        $name_old = trim(strtolower($data1_item['name']));
        $type_old = trim(strtolower($data1_item['type']));
        $length_old = $data1_item['length'];
        $precision_old = $data1_item['precision'];

        //корректировка типа1
        $response = $this->GetType($type_old);
        if ($response != '')
            $type_old = $response;

        //data2
        $name_new = trim(strtolower($data2_item['name']));
        $type_new = trim(strtolower($data2_item['type']));
        $length_new = $data2_item['length'];
        $precision_new = $data2_item['precision'];

        //корректировка типа2
        $response = $this->GetType($type_new);
        if ($response != '')
            $type_new = $response;

        //проверяем тип1 и варианты преобразования
        switch($type_old) {
            case "character":
                //character -> character, character -> date
                if ($type_new == "character")
                    return "ALTER COLUMN $name_old TYPE $type_old ($length_new)";

                if ($type_new == "date")
                    return "ALTER COLUMN $name_old TYPE $type_new USING $name_old::$type_new";
                break;

            case "numeric":
                //numeric -> numeric, numeric -> character
                if ($type_new == "numeric")
                    return "ALTER COLUMN $name_old TYPE $type_old ($length_new, $precision_new) USING $name_old::$type_old";

                if ($type_new == "character")
                    return "ALTER COLUMN $name_old TYPE $type_new ($length_new) USING $name_old::$type_new";
                break;

            case "date":
                //date -> date, date -> character
                if ($type_new == "character")
                    return "ALTER COLUMN $name_old TYPE $type_new ($length_new) USING $name_old::$type_new";
                break;
        }
        return "";
    }

    public function GetType($fox_type)
    {
        //fox_type -> postgres_type
        switch(trim(strtolower($fox_type)))
        {
            case "number":
                return "numeric";
                break;
        }
        return "";
    }

    public function IsPdoAlive($pdo)
    {
        $connection_active = true;
        try {
            $pdo->exec("SELECT 1");
        } catch(Exception $e) {
            $connection_active = false;
        }
        return $connection_active;
    }
}

abstract class DataBase 
{
    protected $Name;
    protected $Type;
    protected $Schema;
}

class PSQL extends DataBase
{
    public function __construct($Name, $Type)
    {
        $this->Name = $Name;
        $this->Type = $Type;
    }

    public function SetSchema($schema)
    {
        $this->Schema = $schema;
    }
}

interface IPSQLService
{
    public function GetDatabases();
    public function GetSchemas();
    public function GetTables();
}

interface IPSQLRepository
{
    public function GetDatabases();
    public function GetSchemas();
    public function GetTables();
}

class PSQLService implements IPSQLService
{
    private $repository = null;
    private $db;
    private $schema;
    private $table;

    public function __construct($data, $repository)
    {
        $this->db = $data->db;
        $this->schema = $data->schema;
        $this->table = $data->table;
        $this->repository = $repository;
    }

    public function BeginTransaction()
    {
        $repository = $this->repository;
        //
        if ($repository != null)
            return $repository->BeginTransaction();
        return null;
    }

    public function CommitTransaction()
    {
        $repository = $this->repository;
        //
        if ($repository != null)
            return $repository->CommitTransaction();
        return null;
    }

    public function RollbackTransaction($savepoint)
    {
        $repository = $this->repository;
        //
        if ($repository != null)
            return $repository->RollbackTransaction($savepoint);
        return null;
    }

    public function SetSavepoint()
    {
    }

    public function GetDatabases() 
    {
        $repository = $this->repository;
        //
        if ($repository != null) {
            return $repository->GetDatabases();
        }
        return null;
    }

    public function GetDatabase() 
    {
        $repository = $this->repository;
        //
        if ($repository != null) {
            return $repository->GetDatabase();
        }
        return null;
    }

    public function GetSchemas()
    {
        $repository = $this->repository;
        //
        if ($repository != null) {
            return $repository->GetSchemas();
        }
        return null;
    }

    public function GetStop()
    {
        $repository = $this->repository;
        //
        if ($repository != null) {
            return $repository->GetStop();
        }
        return null;
    }

    public function GetTables() 
    {
        $repository = $this->repository;
        //
        if ($repository != null) {
            return $repository->GetTables();
        }
        return null;
    }

    public function IfExists($table, $type)
    {
        $repository = $this->repository;
        if ($repository != null)
            return $repository->IfExists($table, $type);
        return null;
    }
}

class PSQLRepository implements IPSQLRepository
{
    private $pdo = null;
    private $db = "";
    private $schema = "";
    private $table = "";

    public function __construct($data, $pdo) 
    {
        $this->db = $data->db;
        $this->schema = $data->schema;
        $this->table = $data->table;
        $this->pdo = $pdo;
    }

    public function BeginTransaction()
    {
        $pdo = $this->pdo;
        $done = false;
        //
        try {
            $text = "BEGIN";
            $query = $pdo->prepare($text);
            $query->execute();
        } catch(Exception $e) {
            $done = false;
        }
        return $done;
    }

    public function CommitTransaction()
    {
        $pdo = $this->pdo;
        $done = true;
        //
        try {
            $text = "COMMIT";
            $query = $pdo->prepare($text);
            $query->execute();
        } catch(Exception $e) {
            $done = false;
        }
        return $done;
    }

    public function RollbackTransaction($savepoint)
    {
        $pdo = $this->pdo;
        $done = true;
        //
        if (trim($savepoint) != "") {
            //savepoint
            try {
                $text = "SAVEPOINT $savepoint";
                $query = $pdo->prepare($text);
                $query->execute();
            } catch(Exception $e) {
                $done = false; 
            }    
        } else {
            //rollback
            try {
                $text = "ROLLBACK;";
                $query = $pdo->prepare($text);
                $query->execute();
            } catch(Exception $e) {
                $done = false;
            }
            
        }
        return $done;
    }

    public function GetAll()
    {
        $db = $this->db;
        $pdo = $this->pdo;
        $response = array();
        //
        if($db != '') {
            try {
                //получить список бд
                $text = "SELECT datname FROM pg_database";
                $Query = $pdo->prepare($text);
                $Query->execute();
                $db_array = $Query->fetchAll(PDO::FETCH_ASSOC);
                
                foreach($db_array as $db_item) {
                    $db_name = $db_item['datname'];
                    $response[] = new class($db_name) {
                        public $db;
                        public $schema;
                        public $table;

                        function __construct($db) {
                            $this->db = $db;
                            $this->schema = array();
                        }

                        function SetSchema($schema) {
                            $this->schema = $schema;
                        }

                        function SetTable($table_array) {
                            $this->table = $table_array;
                        }
                    };
                }

                foreach($response as $response_item) {
                    $db_name = $response_item->db;                    
                    if(trim(strtolower($db_name)) == trim(strtolower($db))) {
                        //найдена текущая бд
                        //получить список схем
                        $text = "SELECT schema_name FROM information_schema.schemata";//"SELECT table_schema FROM information_schema.tables GROUP BY table_schema";
                        $Query = $pdo->prepare($text);
                        $Query->execute();
                        $schemas_array = $Query->fetchAll(PDO::FETCH_ASSOC);

                        foreach($schemas_array as $schema_item) {
                            //получить таблицы для схемы
                            $schema_name = $schema_item['schema_name'];
                            $text = "SELECT table_name FROM information_schema.tables
                                WHERE table_schema IN('$schema_name', 'myschema');";
                            $Query = $pdo->prepare($text);
                            $Query->execute();
                            $tables_array = $Query->fetchAll(PDO::FETCH_ASSOC);
                            //
                            $response_item->schema[] = new class($this, $schema_name, $tables_array) {
                                public $parent;
                                public $schema;
                                public $table;

                                function __construct($parent, $schema, $table) {
                                    $this->parent = $parent;
                                    $this->schema = $schema;
                                    $this->table = $table;
                                }
                            };                                                       
                        }
                    }
                }                
            } catch(Exception $e) {
                $e->getMessage();
            }
        }        
        return $response;
    }

    public function GetDatabases() 
    {
        $pdo = $this->pdo;
        $response = array();
        //
        try {
            //получить список бд
            $text = "SELECT datname FROM pg_database";
            $Query = $pdo->prepare($text);
            $Query->execute();
            $db_array = $Query->fetchAll(PDO::FETCH_ASSOC);
            
            for($i = 0; $i < count($db_array); $i++) {
                $response[] = $db_array[i];
            }
            
        } catch(Exception $e) {
            $e->getMessage();
        }
        return $response;
    }

    public function GetSchemas() 
    {
        $pdo = $this->pdo;
        $response = array();
        //
        try {
            //получить список схем
            $text = "SELECT datname FROM pg_database";
            $Query = $pdo->prepare($text);
            $Query->execute();
            $db_array = $Query->fetchAll(PDO::FETCH_ASSOC);
            
            for($i = 0; $i < count($db_array); $i++) {
                $response[] = new class($db_array[i]) {
                    public $db;
                    public $schema_array;
                    public $table_array;
                    //
                    function __construct($db) {
                        $this->db = $db;
                    }
                };
            }
            
        } catch(Exception $e) {
            $e->getMessage();
        }
        return $response;
    }

    public function GetStop()
    {
        $pdo = $this->pdo;
        $schema = $this->schema;
        $table = $this->table;
        //
        $Query = $pdo->prepare("SELECT reltuples FROM pg_class WHERE oid = '$schema.$table'::regclass");
		$Query->execute();
		$array = $Query->fetchAll(PDO::FETCH_NUM);
		//
		if (count($array) > 0) {
			//останавливаем выполнение скрипта
			if (intval($array[0][0]) != -1) {                
                return true;
            }
        }
        return false;
    }

    public function GetTables()
    {
    }

    public function IfExists($table, $type)
    {
        $pdo = $this->pdo;
        $schema = $this->schema;
        $text = "";
        //
        switch ($type)
        {
            case "table":
                $text = "SELECT EXISTS (SELECT FROM pg_tables WHERE schemaname = '$schema' AND tablename = '$table');";
                break;

            case "mview":
                $text = "SELECT EXISTS (SELECT FROM pg_matviews WHERE schemaname = '$schema' AND matviewname = '$table');";
                break;

            case "view":
                break;
        }

        //запрос
        try {
            $query = $pdo->prepare($text);
            $query->execute();
            $response_array = $query->fetchAll(PDO::FETCH_ASSOC);
            //
            if (count($response_array) > 0) {
                if ($response_array[0]['exists'] == "true")
                    return true;
                return false;
            }
        } catch(Exception $e) {
            //ошибка
        }
        return false;
    }
}

class PSQLTable
{
    private $name;
    private $pdo;
    private $schema;

    public function __construct($pdo, $schema, $name, $parameters)
    {
        $this->pdo = $pdo;
        $this->schema = $schema;
        $this->name = $name;
        $this->parameters = $parameters;
    }

    public function GetTable()
    {        
        return $this->GetArray();
    }

    public function GetArray()
    {
        $name = $this->schema . '.' . $this->name;
        $parameters = $this->parameters;
        $pdo = $this->pdo;
        
        $text = "SELECT * FROM $name $parameters";
        $Query = $pdo->prepare($text);
        $Query->execute();
        return $Query->fetchAll(PDO::FETCH_ASSOC);
    }
}

class DBHelper extends ControlDBConnectPG//?
{
    public static function GetDatabase($pdo)
    {
        $text = "SELECT current_database()";
        $Query = $pdo->prepare($text);
        $Query->execute();
        $db_array = $Query->fetchAll(PDO::FETCH_ASSOC);
        //
        if (count($db_array) <= 0) {
            return "";
        }
        return $db_array[0]['current_database'];
    }

    public static function GetDatabaseAll($pdo)
    {
        $text = "SELECT datname FROM pg_database";
        $Query = $pdo->prepare($text);
        $Query->execute();
        return $Query->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function GetSchemaAll($pdo)
    {
        //получить список схем
        $text = "SELECT schema_name FROM information_schema.schemata";
        $Query = $pdo->prepare($text);
        $Query->execute();
        return $Query->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function GetTableAll($pdo, $schema)
    {
        $text = "SELECT table_name FROM information_schema.tables
            WHERE table_schema IN('$schema', 'myschema');";
        $Query = $pdo->prepare($text);
        $Query->execute();
        return $Query->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function GetTableHeader($data) {
        $table = $data['name'];
        $fields = $data['fields'];
        $headers = self::GetValueString($fields, 'caption', array());//параметры = 1 - реквизиты, 2 - какой элемент ассоциативного массива нужен, 3 - массив со

        //headers
        if (count($headers) == 0)
            return "";

        //sql
        try {
            //собрать таблицу
            $textHTML = '                
                <thead>
                    <tr>';
            foreach($headers as $header)
                $textHTML .= "<th>$header</th>";
            $textHTML .= '
                    </tr>
                </thead>';
            return $textHTML;
        } catch(Exception $e) {
        }
        return "";
    }

    public static function GetTableData($data_object) {
        $response = null;//array('status' => false);
        //получить соединение
        $db_object = ControlDBConnectPG::GetDb();
        $conn = $db_object->GetConn();

        //нет соединения
        if ($conn == null)
            return;
        $pdo = $conn;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $application_mode = $data_object['application_mode'];
        $schema = $data_object['table']['schema'];
        $table = $application_mode == "prod" ? $data_object['table']['name'] : $data_object['table']['name'] . "_dev";
        $fields = $data_object['table']['fields'];        
        $params = $data_object['params'];
        $tbody = $data_object['tbody'];
        $current_date_fix = $data_object['current_date_fix'];
        //
        $db = self::GetDatabase($pdo);
        $db = trim(strtolower($db));
        //
        if ($db == '')
            return;

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

        $headers = self::GetValueString($fields, 'caption', array());//параметры = 1 - реквизиты, 2 - какой элемент ассоциативного массива нужен, 3 - массив со
        $breaks = self::GetValueString($fields, 'break', array(false));//предустановленными значениями (для колонки номера строки)

        //headers/breaks
        if (count($headers) == 0 || count($breaks) == 0)
            return;

        //fields
        $cnt = 1;
        $fields_value = "";
        for($i = 1; $i < count($fields); $i++) 
        {
            foreach($fields as $field) 
            {
                if ($field['bind_number'] == $cnt) {
                    $field_correction = "";
                    switch(trim(strtolower($field['type']))) {
                        case "numeric":
                            //корректировка
                            $field_correction .= trim($field['correction']) != ""
                                ? "REPLACE({$field['name']}::text, {$field['name']}::text, ({$field['correction']})::text)::numeric"
                                : $field['name'];

                            //округление
                            $fields_value .= $field['precision'] > 0
                                ? "ROUND($field_correction, {$field['precision']}) AS {$field['name']}, "
                                : "$field_correction, ";
                            break;

                        case "date":
                            //корректировка
                            $fields_value .= trim($field['correction']) != ""
                                ? "REPLACE({$field['name']}::text, {$field['name']}::text, to_char({$field['name']}, 'dd.mm.yyyy')), "
                                : $field['name'];
                            break;

                        case "character":
                            $fields_value .= "{$field['name']}, ";
                            break;
                    }
                }
            }
            $cnt++;
        }
        $fields_value = substr($fields_value, 0, strlen($fields_value) - 2);

        //найти индекс по умолчанию        
        $indexes = $data_object['table']['indexes'];
        $index = $data_object['table']['default_index'];
        $index_value = "ORDER BY ";
        //
        $find = false;
        foreach($indexes as $item)
        {
            if ($item['name'] == $index) {
                //индекс найден
                $index_value .= $item['value'];
                $find = true;
                break;
            }
        }

        //если индекс не найден, данные будут без сортировки
        if (!$find)
            $index_value = "";

        //граница выборки
        $range = $params['max'] + $params['limit'];

        //sql
        try {
            $service->BeginTransaction();
            //ищем вспомогательную таблицу
            if ($service->IfExists($table, 'mview')) {
                //без поиска
                $text = "                  
                    SELECT *
                    FROM (
                        SELECT
                            *,
                            ROW_NUMBER() OVER() AS cnt
                        FROM (      
                            SELECT $fields_value
                            FROM $schema.\"$table\"
                            $index_value
                        ) AS result
                    ) AS numbered
                    WHERE cnt > :params_max AND cnt <= :range";
                //
                $query = $pdo->prepare($text);
                $query->bindValue(':params_max', $params['max']);
                $query->bindValue(':range', $range);
                $query->execute();
                $response_array = $query->fetchAll(PDO::FETCH_NUM);

                //собрать таблицу
                //tbody
                $max = $params['max'];
                $textHTML = $tbody ? "<tbody>" : "";
                if (count($response_array) > 0) {
                    for($i = 0; $i < count($response_array); $i++)
                    {
                        $textHTML .= '<tr viewed="false">';
                        $td_cnt = count($response_array[$i]);
                        for ($j = 0; $j < $td_cnt; $j++) 
                        {
                            //элемент cnt пропускаем, нужен только для вычисления max
                            if ($j == $td_cnt - 1) {
                                //наибольший id из списка (для границ порции)
                                if ($response_array[$i][$j] > $max)
                                    $max = $response_array[$i][$j];
                                continue;
                            }
                            //
                            $response_item = $response_array[$i][$j];
                            //current_date_fix
                            if ($current_date_fix) {
                                if ($response_array[$i][$j] == "20.05.2052")
                                    $response_item = "действует";
                            }
        
                            $textHTML .= !$breaks[$j]
                                ? "<td class=\"break_disable\">$response_item</td>"
                                : "<td>$response_item</td>";
                        }
                        $textHTML .= '</tr>';                        
                    }
                }
                //
                $textHTML .= $tbody ? "</tbody>" : "";
                $response = new class("table", $textHTML, $max) {
                    public $table;
                    public $html;
                    public $max;

                    function __construct(
                        $table, 
                        $textHTML,
                        $max
                        )
                    {
                        $this->table = $table;
                        $this->html = $textHTML;
                        $this->max = $max;
                    }
                };
            }
            $service->CommitTransaction();
        } catch(Exception $e) {
            $service->RollbackTransaction('');
        }
        return $response;
    }

    public static function GetTableUpdateInfo($json)
	{
		$response = "нет информации";		
        //получить соединение
        $db_object = ControlDBConnectPG::GetDb();
        $conn = $db_object->GetConn();

        //нет соединения
        if ($conn == null)
            return;
        $pdo = $conn;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        //данные таблицы из json (выведет данные по первой найденной таблице)
        $postData = file_get_contents($json);
        $data_object = json_decode($postData, true);

        if ($data_object == null)
            return $response;
        //
        $schema = "";
        $table = "";
        
        foreach($data_object as $table_json)
        {
            if (!$table_json['timestamp']['enable'])
                continue;
            //
            $schema = $table_json['schema'];
            $table = $table_json['public_name'];
            break;
        }
                
        $db = DBHelper::GetDatabase($pdo);
        $db = trim(strtolower($db));
        //
        if ($db == '')
            return $response;

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
            return $response;

        //sql
        try {
            $service->BeginTransaction();
            if (!$service->IfExists("updates", "table"))
                return $response;

            //запрос
            $text = "
                SELECT TO_CHAR(modify_date, 'DD ') || importer.get_month_name(SUBSTR(modify_date::text, 6, 2)::integer) || TO_CHAR(modify_date, ' YYYY \"года\", HH24:MI') AS modify_date
                FROM (
                    SELECT 
                        schema, 
                        name,
                        MAX(modify_date) AS modify_date
                    FROM importer.\"updates\"
                    WHERE LOWER(schema) = :schema
                        AND LOWER(name) = :table
                    GROUP BY schema, name, modify_date
                    ORDER BY schema, name, modify_date DESC
                    LIMIT 1
                ) AS result";
            //
            $query = $pdo->prepare($text);
            $query->bindValue(':schema', strtolower(trim($schema)));
            $query->bindValue(':table', strtolower(trim($table)));

            //ошибка от СУБД при наличии записи
            try {
                $query->execute();
            } catch(Exception $e) {
                return $response;
            }
            //
            $response = $query->fetchAll(PDO::FETCH_ASSOC)[0]['modify_date'];
            $service->CommitTransaction();
        } catch(Exception $e) {
            $service->RollbackTransaction('');
        }
        return $response;
	}

    public static function GetValueString($fields, $key, $values) 
    {
        $cnt = 1;
        for($i = 1; $i <= count($fields); $i++) 
        {
            foreach($fields as $field) 
            {
                if ($field['bind_number'] == $cnt) {
                    switch(gettype($field[$key])) {
                        case "integer":
                            $values[] = "{$field[$key]}";                        
                            break;

                        case "boolean":
                            $values[] = $field[$key] ? true : false;
                            break;

                        case "string":
                            $values[] = "{$field[$key]}";
                            break;
                    }
                }
            }
            $cnt++;
        }
        return $values;
    }

    public static function JsonDeserialize($path, $table = "default")
    {
        $json = file_get_contents($path);
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE)
            return json_last_error_msg();
        
        //по умолчанию будет выдан первый элемент json-файла
        if ($table == "default")
            return $data[0];
        
        //иначе найти указанную таблицу
        foreach($data as $item)
        {
            if ($item['param_name'] == trim(strtolower($table)))
                return $item;
        }
        return null;
    }
}

class SystemHelper
{
    public static function Clear($xml, $csv, $dbf)
    {
        //true = удалить
        $folder = scandir(__DIR__);
        if ($folder) {
            $response_xml = null;
            $response_csv = null;
            $response_dbf = null;

            //xml
            if($xml) {
                $response_xml = self::Delete($folder, 'xml');
            }

            //csv
            if($csv) {
                $response_csv = self::Delete($folder, 'csv');
            }

            //dbf
            if($dbf) {
                $response_dbf = self::Delete($folder, 'dbf');
            }

            return new class($response_xml, $response_csv, $response_dbf) {
                public $xml;
                public $csv;
                public $dbf;
            
                function __construct($xml, $csv, $dbf)
                {
                    $this->xml = $xml;
                    $this->csv = $csv;
                    $this->dbf = $dbf;
                }
            };
        }
        return null;
    }

    private static function Delete($folder, $extension)
    {
        $response = array();
        foreach($folder as $file)
        {
            $file_array = pathinfo($file);
            if (count($file_array) > 0) {
                if ($extension == $file_array['extension']) {
                    $result = false;
                    //
                    if (file_exists($file)) {
                        $result = unlink($file);
                    }

                    if($result) {
                        //добавляем в массив удаленных файлов
                        $response[] = $file_array['basename'];  
                    }                    
                }
            }            
        }
        return $response; 
    }

    public static function GetCurrentFolder()
    {
        //получить путь к файлу в ExecuteScripts
        $folders = array_values(array_filter(explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'))));
        $value = "";
        for($i = 0; $i < count($folders) - 1; $i++)
            $value .= $folders[$i] . "/";
        return $value;
    }

    public static function GetApplicationMode()
    {
        //режим работы приложения, production (чистовой) или development (отладка)
        $position = strpos(strtolower($_SERVER['PHP_SELF']), "dev");
		return $position === false ? "prod" : "dev";
    }
}

abstract class CustomElement
{
    protected $name;
}

class Progressbar extends CustomElement
{
    public static function CreateXml($table)
    {
        $xmlstr = "<?xml version='1.0' standalone='yes'?><progressbar></progressbar>";
        $xml = new SimpleXMLElement($xmlstr);
        $filename = Progressbar::GetFilename($table);
        return $xml->saveXML("$filename");
    }    

    public static function ExistXml($table)//$filename = progressbar_filename
    {
        $filename = Progressbar::GetFilename($table);
        if (file_exists($filename)) {
            return true;
        }
        return false;
    }

    public static function GetFilename($table)
    {
        //дать имя файла (префикс + имя_файла + расширение)
        return "progressbar_" . $table . ".xml";
    }

    public static function GetParameter($parameter, $schema, $table)
    {
        //ищем параметр, все его вхождения
        $new_array = array();
        if (Progressbar::ExistXml($table)) {
            $xml = Progressbar::ReadXml($table);
            //
            if (count($xml) > 0) {
                //выборка только по указанной схеме и таблице
                $tmp_array = Progressbar::GetSelect($xml, $schema, $table);
                if (count($tmp_array) > 0) {                    
                    $row = $tmp_array[0]->attributes();
                    foreach($tmp_array as $item) {
                        $parameter_current = $item->parameter;
                        if (trim(strtolower($parameter_current)) == trim(strtolower($parameter)))
                            $new_array[] = $item;
                    }
                }
            }
        }
        return $new_array;
    }

    public static function GetProgress($xml, $schema, $table)
    {
        //получаем актуальное значение процентной шкалы по схеме и таблице
        //ищем максимальный row id в выборке $tmp_array
        $row = $xml[0]->attributes();
        $row_max = (int)$row->id;
        $row_max_item = $xml[0];
        //
        foreach($xml as $item) {
            $id_current = (int)$item->value;
            if ($id_current > $row_max)
                $row_max = $id_current;
        }
        return $row_max;
    }

    public static function GetSelect($xml, $schema, $table)
    {
        //выборка только по указанной схеме и таблице
        $tmp_array = array();
        foreach($xml as $item) {
            if (trim(strtolower($schema)) == trim(strtolower($item->schema)) 
            && trim(strtolower($table)) == trim(strtolower($item->name))) {
                $tmp_array[] = $item;
            }                        
        }
        return $tmp_array;
    }

    public static function GetStop($pdo, $xml, $schema, $table)
    {
        $db = DBHelper::GetDatabase($pdo);
        $db = trim(strtolower($db));
        //
        if ($db != '') {
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
            if ($service != null && $repository != null) {
                if ($service->GetStop()) {
                    //бд подтверждает, что загрузка окончена
                    return true;//die();
                }
            }
            
            //проверяем стоп-флаг - запись "100" в таблице
            foreach($xml as $item) {
                if ((int)$item->value == 100) {
                    //останавливаем выполнение скрипта
                    return true;//die();                
                }
            }
        }
        return false;
    }

    public static function ReadXml($table)
    {
        $filename = Progressbar::GetFilename($table);
        if (file_exists($filename)) {
            $xml = simplexml_load_file("$filename");
            $gg = gettype($xml);
            if (gettype($xml) == 'array') {
                return $xml;
            }

            if (gettype($xml) == 'object') {
                return $xml;
            }
        }
        return array();
    }

    public static function SetParameter($parameter, $schema, $table)
    {
        $filename = Progressbar::GetFilename($table);
        $xml = Progressbar::ReadXml($table);//актуальное состояние файла
        if ($xml != null) {
            //новая запись
            $row = $xml->addChild('row', '');
            $row->addAttribute('id', count($xml));
            $row->addChild('value', Progressbar::GetProgress($xml, $schema, $table));
            $row->addChild('name', $table);
            $row->addChild('dt', date("Y-d-m G-i-s"));
            $row->addChild('schema', $schema);
            $row->addChild('parameter', $parameter);
            //
            $xml->saveXML("$filename");
            return true;
        }
        return false;
    }

    public static function SetProgress($value, $schema, $table)
    {
        //проверяем стоп-флаг - запись "100" в таблице
        $filename = Progressbar::GetFilename($table);
        $xml = Progressbar::ReadXml($table);//актуальное состояние файла
        if ($xml != null) {
            //новая запись
            $row = $xml->addChild('row', '');
            $row->addAttribute('id', count($xml));
            $row->addChild('value', $value);
            $row->addChild('name', $table);
            $row->addChild('dt', date("Y-d-m G-i-s"));
            $row->addChild('schema', $schema);
            $row->addChild('parameter', '');
            //
            $xml->saveXML("$filename");
            return true;
        }
        return false;
    }

    public static function ddd($schema, $table)
    {
        //xml-файл
        if (Progressbar::ExistXml($table)) {
            $xml = Progressbar::ReadXml($table);
            //
            if (count($xml) > 0) {
                //выборка только по указанной схеме и таблице
                $tmp_array = Progressbar::GetSelect($xml, $schema, $table);
                if (count($tmp_array) > 0) {
                    $value = Progressbar::GetProgress($tmp_array, $schema, $table);
                    if ($value == 500)
                        return true;
                }
            }
        }
        return false;
    }
}
?>