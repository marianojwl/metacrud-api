<?php
// Includes
include_once(__DIR__ . '/config/includes.php');



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
if($tablename && !in_array($tablename, explode(',', $_ENV['METACRUD_ALLOWED_TABLES']))){
  echo json_encode(['error' => 'Table not allowed']);
  http_response_code(403);
  exit;
}

// Include PDO
// include_once(__DIR__ . '/config/pdo.php');


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