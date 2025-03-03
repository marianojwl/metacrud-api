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

    if($controller == 'column'){
      try {
        if(!$_SESSION[$_ENV['METACRUD_IS_ADMIN_SESSION_KEY']])
          throw new Exception('Permiso denegado.');
        include_once(__DIR__ . "/meta/column.php");
      } catch (Exception $e) {
          echo json_encode(['success'=>false, 'error' => $e->getMessage()]);
          http_response_code(400);
      }
      exit;
    }

    if($controller == 'setTableComment'){
      try {
        if(!$_SESSION[$_ENV['METACRUD_IS_ADMIN_SESSION_KEY']])
          throw new Exception('Permiso denegado.');
        include_once(__DIR__ . "/meta/setTableComment.php");
      } catch (Exception $e) {
          echo json_encode(['success'=>false, 'error' => $e->getMessage()]);
          http_response_code(400);
      }
      exit;
    }
      
    break;
case 'crud':
case 'upload':
  //{"metacrud":{"userPermissionsVars":["$_SESSION.Cinemacenter-INTRANET.metacrud.perfiles_id"], "permissions": { "create": [7], "update":[7], "delete":[7], "read":[7] }}}
    $table_status = getTableStatus($pdo, $tablename);
    $table_meta = $table_status['Comment']['metacrud'] ?? [];
    $userPermissionsVars = $table_meta['userPermissionsVars'] ?? null;
    //$userPermissions = $_SESSION[$_ENV['METACRUD_USER_ROLES_SESSION_KEY']] ?? [];
    $userPermissions = getUserPermissions($userPermissionsVars);
    if($table_meta['permissions']??false) {
      if(count($userPermissions) == 0){
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
      if(!hasPermission($table_meta, $actions[$method], $userPermissions)){
        echo json_encode(['success'=>false, 'error' => 'Permiso denegado.']);
        http_response_code(403);
        exit;
      }
    }
    if(file_exists(__DIR__ . "/".$resource."/$method.php")){
        try {
            include_once(__DIR__ . "/".$resource."/$method.php");
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            if(strpos($errorMsg, 'SQLSTATE[23000]') !== false){
                $entryValue = explode("Duplicate entry '", $errorMsg)[1];
                $entryValue = explode("'", $entryValue)[0];
                echo json_encode(['success'=>false, 'error' => 'Valor duplicado. "' . $entryValue . '" ya existe en la base de datos.']); 
                http_response_code(400);
                exit;
            }
            echo json_encode(['success'=>false, 'error' => $e->getMessage()]);
            http_response_code(400);
        }
        exit;
    }
    break;
}

echo json_encode(['success'=>false, 'error' => 'Resource not found']);
http_response_code(404);