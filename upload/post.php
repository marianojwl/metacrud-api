<?php
// GET ALL COLUMNS IN TABLE
$columns = getColumns($pdo, $tablename);


// GET COLUMNS EXPECTING UPLOADS
$columnsExpectingUploads = array_filter($columns, function($column){
  return $column['Comment']['metacrud']['upload'] ?? false;
});


// CHECK FOR NUMBER OF FILE ARRAYS, 1 EXPECTED
if(count($_FILES) != 1){
  echo json_encode(['success'=>false, 'error' => 'Número inseperado de parámetros']);
  http_response_code(400);
  exit;
}


// INITIALIZE VARIABLES
$filesKey = null;
$filesArray = null;


// CHECK IF COLUMN IS EXPECTING UPLOAD
foreach($_FILES as $key => $array){
  if(!in_array($key, array_map(function($column){ return $column['Field']; }, $columnsExpectingUploads))){
    echo json_encode(['success'=>false, 'error' => 'Column not expecting upload']);
    http_response_code(400);
    exit;
  }
  // SET VARIABLES
  $filesKey = $key;
  $filesArray = $array;
}

// CHECK IF SOMETHING WENT WRONG
if(!$filesKey || !$filesArray){
  echo json_encode(['success'=>false, 'error' => 'Error Interno']);
  http_response_code(400);
  exit;
}


// GET COLUMN AND PARAMETERS
$column = array_filter($columnsExpectingUploads, function($column) use ($filesKey){
  return $column['Field'] == $filesKey;
});

$column = array_values($column)[0];

$isMultiple = $column['Comment']['metacrud']['upload']['multiple'] ?? false;

$maxSize = $column['Comment']['metacrud']['upload']['maxSize'] ?? 1000000; // expresado en bytes

$acceptedTypes = $column['Comment']['metacrud']['upload']['accept'] ?? ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];

$uploadDir = $column['Comment']['metacrud']['upload']['dir'] ?? __DIR__ . '/../uploads/' . $tablename . '/' . $filesKey . '/';

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


// CREATE UPLOAD DIR IF NOT EXISTS
if(!file_exists($uploadDir)){
  mkdir($uploadDir, 0777, true);
}

// GET RELATIVE PATH IN HOST


$uploadedFiles = [];

// UPLOAD FILES
foreach($filesToUpload as $file){
  $sanitized_filename = preg_replace('/[^A-Za-z0-9\-_\.]/', '-', $file['name']);
  $uploadPath = $uploadDir . $sanitized_filename;
  while(file_exists($uploadPath)){
    $uploadPath = $uploadDir . uniqid() . '_' . $sanitized_filename;
  }
  if(!move_uploaded_file($file['tmp_name'], $uploadPath)){
    echo json_encode(['success'=>false, 'error' => 'Error al subir archivo ' . $file['name']]);
    http_response_code(400);
    exit;
  }
  // get absolute path
  $uploadPath = str_replace('\\', '/', $uploadPath);
  $uploadPath = str_replace($_SERVER['DOCUMENT_ROOT'], '', $uploadPath);
  $uploadPath = ltrim($uploadPath, '/');

  $parts = explode('/', $uploadPath);
  $numberOfParts  = count($parts);
  $newPath = '';
  for($i=0; $i<$numberOfParts; $i++){
    if(isset($parts[$i+1])){
      if($parts[$i+1] == '..'){
        $i++;
      } else {
        $newPath .= $parts[$i] . '/';
      }
    } else {
      $newPath .= $parts[$i];
    }
  }

  $uploadedFiles[] = $newPath;
}
echo json_encode(['success'=>true, 'message' => 'Archivos subidos', 'data' => $uploadedFiles]);
exit;