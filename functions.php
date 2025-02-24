<?php

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

  if(isset($table['Comment']['metacrud']['file'])){
    try{
      $fullpath = $_ENV['METACRUD_META_FILES_BASE_DIR'] . $table['Comment']['metacrud']['file'];
      $table['Comment']['metacrud'] = json_decode( file_get_contents($fullpath), true);
    } catch(Exception $e){
      $table['Comment']['metacrud'] = [];
    }
  }

  // replace with session values , eg:   { "metacrud": {"restrictions":{"create": { "cine_id":"$_SESSION.Cinemacenter-INTRANET.metacrud.gerente_en_cine_ids" }  } } }
  if(isset($table['Comment']['metacrud']['restrictions'])){
    foreach($table['Comment']['metacrud']['restrictions'] as $action => $restrictions){
      foreach($restrictions as $field => $value){
        // if $value is a string
        if(is_string($value)){     
          if(strpos($value, '$_SESSION') === 0){
            $parts = explode('.', $value);
            // remove first element
            array_shift($parts);
            $session = $_SESSION;
            foreach($parts as $part){
              $session = $session[$part];
            }
            $table['Comment']['metacrud']['restrictions'][$action][$field] = $session;
          } else {
            $table['Comment']['metacrud']['restrictions'][$action][$field] = $value;
          }
        } else {
          $table['Comment']['metacrud']['restrictions'][$action][$field] = $value;
        } 
      }
    }
  }

  return $table;
}


function validateInputField($column, $input){
  $metacrud = $column['Comment']['metacrud'] ?? [];

  // CONTINUE ON PRIMARY KEY AUTO_INCREMENT
  if($column['Key'] == 'PRI' && $column['Extra'] == 'auto_increment'){
    return false;
  }

  // CHECK IF REQUIRED
  if(( $column['Null'] == 'NO' && empty($column['Default']) ) && !isset($input[$column['Field']])){
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

function generateCombinations($arrays, $prefix = []) {
  if (empty($arrays)) {
      return [$prefix];
  }
  
  $key = array_key_first($arrays);
  $values = array_shift($arrays);
  
  $result = [];
  foreach ($values as $value) {
      $newPrefix = array_merge($prefix, [$key => $value]);
      $result = array_merge($result, generateCombinations($arrays, $newPrefix));
  }
  
  return $result;
}