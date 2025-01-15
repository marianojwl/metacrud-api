<?php
// Headers
include_once(__DIR__ . '/config/headers.php');
include_once(__DIR__ . '/config/env.php');

// Session
session_start();
$_SESSION['USER_ROLES'] = [1,2];

// Functions
function getColumns($pdo, $tablename){
  $stmt = $pdo->prepare("SHOW FULL COLUMNS FROM $tablename");
  $stmt->execute();
  $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // array map to json enconde every column's Comment
  $columns = array_map(function($column) {
      $column['Comment'] = json_decode($column['Comment'], true);
      return $column;
  }, $columns);

  return $columns;
}

function getTableStatus($pdo, $tablename){
  $parts = array_reverse(explode('.', $tablename));
  $tb = $parts[0];
  $db = $parts[1]??null;
  $sql = "SHOW TABLE STATUS ";
  if($db){
    $sql .= "FROM " . $db . " ";
  }
  $sql .= "LIKE :tb";
  $stmt = $pdo->prepare($sql);
  $stmt->bindParam(':tb', $tb);
  $stmt->execute();

  $table = $stmt->fetch(PDO::FETCH_ASSOC);

  $table['Comment'] = json_decode($table['Comment'], true);

  return $table;
}


function validateInputField($column, $input){
  $metacrud = $column['Comment']['metacrud'] ?? [];

  // CONTINUE ON PRIMARY KEY AUTO_INCREMENT
  if($column['Key'] == 'PRI' && $column['Extra'] == 'auto_increment'){
    return false;
  }

  // CHECK IF REQUIRED
  if($column['Null'] == 'NO' && !isset($input[$column['Field']])){
    echo json_encode(['error' => $column['Field'] . ' is required']);
    http_response_code(400);
    exit;
  }

  // REGEX VALIDATION IF SET
  if(isset($metacrud['regex_pattern'])){
    $pattern = '/' . $metacrud['regex_pattern'] . '/';
    if(!preg_match($pattern, $input[$column['Field']])) {
      echo json_encode(['success'=>false, 'error' => $column['Field'] . ' is invalid']);
      http_response_code(400);
      exit;
    }
  }

  return true;
}

function getPrimaryKeyName($columns){
  foreach($columns as $column){
    if($column['Key'] == 'PRI'){
      return $column['Field'];
    }
  }
}
/*
  const hasPermission = (action) => {
    if(!table_meta?.permissions[action]) return true;
    return user_roles?.some(role => table_meta?.permissions[action]?.includes(role));
  };
*/
function hasPermission($table_meta, $action, $user_roles){
  if(!isset($table_meta['permissions'][$action])) return true;
  return array_intersect($user_roles, $table_meta['permissions'][$action]);
}


// Parse URL and method
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('index.php','',$_SERVER['SCRIPT_NAME']);
$uri = str_replace($path, '', $uri);
$method = strtolower($_SERVER['REQUEST_METHOD']);

// Parse URL parts
$uri_parts = explode('/', $uri);
$resource = $uri_parts[0] ?? null; // meta or crud
$tablename = $uri_parts[1] ?? null;
$controller = $uri_parts[2] ?? null;
$requested_id = $uri_parts[2] ?? null;

// Verify if table is allowed
if($tablename && !in_array($tablename, explode(',', $_ENV['ALLOWED_TABLES']))){
  echo json_encode(['error' => 'Table not allowed']);
  http_response_code(403);
  exit;
}

// Include PDO
include_once(__DIR__ . '/config/pdo.php');


// Include resource
switch($resource){
  case 'meta':
    if($controller == 'columns'){
      echo json_encode(['data' => getColumns($pdo, $tablename)]);
      exit;
    }

    if($controller == 'table'){
      echo json_encode(['data' => getTableStatus($pdo, $tablename)]);
      exit;
    }
      
    break;
case 'crud':
    $table_status = getTableStatus($pdo, $tablename);
    $table_meta = $table_status['Comment']['metacrud'] ?? [];
    $user_roles = $_SESSION[$_ENV['METACRUD_USER_ROLES_SESSION_KEY']] ?? [];
    if($table_meta['permissions']??false) {
      if(count($user_roles) == 0){
        echo json_encode(['success'=>false, 'error' => 'User Roles Undifined']);
        http_response_code(401);
        exit;
      }

      $actions = [
        'get' => 'read',
        'post' => 'create',
        'put' => 'update',
        'delete' => 'delete'
      ];
      if(!hasPermission($table_meta, $actions[$method], $user_roles)){
        echo json_encode(['success'=>false, 'error' => 'Permission Denied']);
        http_response_code(403);
        exit;
      }
    }
    if(file_exists(__DIR__ . "/crud/$method.php")){
        try {
            include_once(__DIR__ . "/crud/$method.php");
        } catch (Exception $e) {
            echo json_encode(['success'=>false, 'error' => $e->getMessage()]);
            http_response_code(400);
        }
        exit;
    }
    break;
}

echo json_encode(['success'=>false, 'error' => 'Resource not found']);
http_response_code(404);