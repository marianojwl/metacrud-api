<?php

require_once __DIR__ . '/SimpleXLS.php';
require_once __DIR__ . '/XLSReader.php';


$columns = getColumns($pdo, $tablename);

$isMultiple = true;

$maxSize = $table_meta['import']['maxSize'] ?? 1000000; // expresado en bytes

$acceptedTypes = $table_meta['import']['accept'] ?? ['application/vnd.ms-excel'];

$template = $table_meta['import']['template'] ?? null;

if(!$template){
  echo json_encode(['success'=>false,'error' => 'Error al abrir el archivo']);
  http_response_code(400);
  exit;
}

// INITIALIZE VARIABLES
$filesKey = null;
$filesArray = null;


// CHECK IF COLUMN IS EXPECTING UPLOAD
foreach($_FILES as $key => $array){
  // SET VARIABLES
  $filesKey = $key;
  $filesArray = $array;
}


// ORGANIZE FILES
$filesToUpload = [];
$count = is_array($filesArray['name']) ? count($filesArray['name']) : 1;
for($i=0; $i<$count; $i++){
  $filesToUpload[] = [
    'name' => is_array($filesArray['name']) ? $filesArray['name'][$i] : $filesArray['name'],
    'type' => is_array($filesArray['type']) ? $filesArray['type'][$i] : $filesArray['type'],
    'tmp_name' => is_array($filesArray['tmp_name']) ? $filesArray['tmp_name'][$i] : $filesArray['tmp_name'],
    'error' => is_array($filesArray['error']) ? $filesArray['error'][$i] : $filesArray['error'],
    'size' => is_array($filesArray['size']) ? $filesArray['size'][$i] : $filesArray['size']
  ];
}


// CHECK FOR ERRORS, SIZE AND FILE TYPE
foreach($filesToUpload as $file){
  if($file['error'] != 0){
    echo json_encode(['success'=>false, 'error' => 'Error en archivo ' . $file['name']]);
    http_response_code(400);
    exit;
  }

  if($file['size'] > $maxSize){
    echo json_encode(['success'=>false, 'error' => 'Archivo demasiado grande']);
    http_response_code(400);
    exit;
  }

  if(!in_array($file['type'], $acceptedTypes)){
    echo json_encode(['success'=>false, 'error' => 'Tipo de archivo no permitido']);
    http_response_code(400);
    exit;
  }
}

$data = [];
foreach($filesToUpload as $file){
  try {
    $xr = new \marianojwl\XLSReader\XLSReader($file['tmp_name'], $template, $file['name']);
    $data = array_merge($data, $xr->getData());
  } catch (Throwable $e) {
    throw new Exception($e->getMessage());
    exit;
  }
}

// DEBUG
if(false) {
print_r($data);
die();
}

// $tablename my contain dbname.tablename
$parts = explode('.', $tablename);
if (count($parts) == 2) {
    $dbname = $parts[0];
    $tablename = $parts[1];
} else {
    $dbname = null;
}

$isTransactional = @$table_meta['import']['isTransactional'] ?? false;
$isInsertIgnore = @$table_meta['import']['isInsertIgnore'] ?? false;
$insertIgnore = $isInsertIgnore ? 'INSERT IGNORE' : 'INSERT';

if($isTransactional)
  $conn->begin_transaction();

try {
    // Ensure $data is not empty
    if (empty($data)) {
        throw new Exception('No data found in the uploaded file.');
    }

    if($dbname){
      $conn->select_db($dbname);
    }

    $fieldsToInsert = array_map(function($column) {
        return $column['Field'];
    }, $columns);

    foreach ($data as $row) {
        $sql1 = $insertIgnore . " INTO `$tablename` (";
        $sql2 = "VALUES (";
        $colKeys = array_keys($row);
        $colKeys = array_intersect($colKeys, $fieldsToInsert); // Filter keys to only those in $fieldsToInsert
        $colKeys = array_values($colKeys); // Re-index the array to avoid issues with keys
        foreach ($colKeys as $key) {
          if (isset($row[$key])) {
              $sql1 .= "`" . $key . "`, ";
              $sql2 .= "'" . $conn->real_escape_string($row[$key]) . "', ";
          } else {
              $sql1 .= "`" . $key . "`, ";
              $sql2 .= "NULL, ";
          }
        }
        $sql1 = rtrim($sql1, ', ') . ") ";
        $sql2 = rtrim($sql2, ', ') . ")";
        $sql = $sql1 . $sql2;
        
        if (!$conn->query($sql) && $isTransactional) {
          throw new Exception('Error executing query: ' . $conn->error);
        }
    }

    if($isTransactional)
      $conn->commit();
} catch (Exception $e) {
    if($isTransactional)
      $conn->rollback();

    throw new Exception('Error during import: ' . $e->getMessage());
}


$conn->close();

foreach($filesToUpload as $file){
  try {
    // if $table_meta['import']['saveFile'] is true, save the file to __DIR__/../imports/tablename/year/month/day/time()
    if(@$table_meta['import']['saveFile']){
      $path = __DIR__ . '/../imports/' . str_replace('.', '_', $tablename) . '/' . date('Y/m/d/');
      if(!is_dir($path)){
        mkdir($path, 0777, true);
      }
      $filename = $path . time() . '_' . basename($file['name']);
      move_uploaded_file($file['tmp_name'], $filename);
    }

  } catch (Throwable $e) {
    throw new Exception("Importación exitosa, pero no se pudo guardar una copia del archivo. " . $e->getMessage());
    exit;
  }
}


echo json_encode(['success'=>true, 'message' => 'Importación exitosa']);
http_response_code(200);
exit;