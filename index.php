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

// DIFFERENT HEADERS FOR DIFFERENT REQUESTS
if ($method == 'get' && $resource == 'meta' && $_ENV['IS_PRODUCTION']==1) {
  // Cache table structure for x hours
  $cacheTTL = 3600 * 24 * 7; // 1 week
  header("Cache-Control: public, max-age=$cacheTTL, must-revalidate");
  header("Expires: " . gmdate("D, d M Y H:i:s", time() + $cacheTTL) . " GMT");
  header("Pragma: cache");
} else {
  // No caching for data
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Cache-Control: post-check=0, pre-check=0", false);
  header("Pragma: no-cache");
  header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
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
case 'import':
case 'chart':
case 'filters':

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
        } catch (Throwable  $e) {
            $errorMsg = $e->getMessage();
            if(strpos($errorMsg, 'SQLSTATE[23000]') !== false){
                $entryValue = @explode("Duplicate entry '", $errorMsg)[1];
                $entryValue = @explode("'", $entryValue)[0];
                echo json_encode(['success'=>false, 'error'=>$errorMsg, 'message' => 'Valor duplicado. "' . $entryValue . '" ya existe en la base de datos.']); 
                http_response_code(400);
                exit;
            }
            echo json_encode(['success'=>false, 'message'=>'Algo salió mal.', 'error' => $e->getMessage()]);
            http_response_code(400);
        }
        exit;
    }
    break;
}

echo json_encode(['success'=>false, 'error' => 'Resource not found']);
http_response_code(404);