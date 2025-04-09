<?php
namespace marianojwl\XLSReader {
  class Sheet {
    protected $index;
    protected $name;
    protected $rows;
    protected $template;
    protected $dataSets;
    protected $metaData;

    public function name() { return $this->name; }
    public function index() { return $this->index; }
    public function rows() { return $this->rows; }

    private function getParsedValue($key, $value, $type="string", $regexMatch=null, $givenFormat=null, $outputFormat=null, $allMatchesMustBeEqual=false) {
      $rawValue = $value;
      if ($regexMatch) {
        preg_match('/'.$regexMatch.'/', $value, $matches);
        if (empty($matches)) {
          throw new \Exception("$key: \"$rawValue\" no pudo ser validado con $regexMatch");
        }
        if($allMatchesMustBeEqual) {
          for($m = 2; $m < count($matches); $m++){
            if($matches[$m] != $matches[1]){
              throw new \Exception($matches[$m] . " no es igual a " . $matches[1]);
            }
          }

        }
        $rawValue = $matches[1] ?? null;
      }
      switch ($type) {
        case 'number':
          $rawValue = floatval($rawValue);
          break;
        case 'date':
          try {
            $rawValue = \DateTime::createFromFormat($givenFormat, $rawValue)->format($outputFormat);
          } catch (\Throwable  $e) {
            throw new \Exception("Error parsing date: " . $e->getMessage());
          }
          break;
        case 'serialDate':
          try {
            // Excel dates start from 1899-12-31
            $unixTimestamp = ($rawValue - 25569) * 86400;

            // Format as date
            $rawValue = gmdate("Y-m-d", $unixTimestamp);
            
          } catch (\Throwable  $e) {
            throw new \Exception("Error parsing date: " . $e->getMessage());
          }
          break;
        default:
          break;
      }
      return $rawValue;
    }

    private function getMetaWithAnchorRefWithValue($meta){
      /*
        { 
          "name":"deposito_total_voucher",
          "anchorRef":{
            "column":1, 
            "regexMatch":"^Total\\sDinero\\sDepositado:$",
            "occurrence":1
          },
          "xOffset":1,
          "yOffset":0,
          "type":"number", 
          "regexMatch":"^([0-9]+(\\.[0-9]{1,2})?)$"
        }  
          */
      $md = $meta;
      $rows = $this->rows;
      $rowCount = count($rows);
      $anchorRefColumn = $meta['anchorRef']['column'] - 1;
      $anchorRefRegexMatch = $meta['anchorRef']['regexMatch'];
      $occurrence = $meta['anchorRef']['occurrence'] ?? 1;
      $xOffset = $meta['xOffset'] ?? 0;
      $yOffset = $meta['yOffset'] ?? 0;
      $rowIndex = null;
      for($i=0; $i < $rowCount; $i++){
        if(isset($rows[$i][$anchorRefColumn]) && preg_match('/'.$anchorRefRegexMatch.'/', $rows[$i][$anchorRefColumn])){
          $occurrence--;
          if($occurrence == 0){
            $rowIndex = $i;
            break;
          }
        }
      }
      if($rowIndex === null){
        throw new \Exception("No se encontró la referencia de anclaje para " . $meta['key']);
      }
      $rawValue = @$rows[$rowIndex+$yOffset][$anchorRefColumn+$xOffset] ?? null;
      if($rawValue === null){
        throw new \Exception("No se encontró el valor para " . $meta['key']);
      }
      $md['value'] = $this->getParsedValue( 
        $meta['key'],
        $rawValue,
        @$meta['type'],
        $meta['regexMatch'] ?? null,
        $meta['givenFormat'] ?? null,
        $meta['outputFormat'] ?? null,
        $meta['allMatchesMustBeEqual'] ?? false
      );
      return $md;

    }
    private function getMetaWithValue($meta){
      $md = $meta;
      $rows = $this->rows;
      $md['value'] = $this->getParsedValue( 
        $meta['key'],
        $rows[$meta['row']-1][$meta['column']-1],
        @$meta['type'],
        $meta['regexMatch'] ?? null,
        $meta['givenFormat'] ?? null,
        $meta['outputFormat'] ?? null,
        $meta['allMatchesMustBeEqual'] ?? false
      );
      return $md;
    }

    private function getSheetLevelMetaDataStructureFromTemplate() {
      $template = $this->template;
      $metaData = array_filter($template['setMarkers']['metaData'], function($meta) {
        return (isset($meta['column']) && isset($meta['row'])) || isset($meta['anchorRef']);
      });

      $md = [];

      foreach($metaData as $meta) {
        
        if(isset($meta['anchorRef'])) {
          $md[$meta['key']] = $this->getMetaWithAnchorRefWithValue($meta);
        } else {
          $md[$meta['key']] = $this->getMetaWithValue($meta);
        }
      }
      
      return $md;
    }

    private function getSetLevelMetaDataStructureFromTemplate() {
      $template = $this->template;
      $metaData = array_filter($template['setMarkers']['metaData'], function($meta) {
        return isset($meta['setColumn']) && isset($meta['setRow']);
      });

      return $metaData;
    }

    public function __construct($template, $index, $name, $rows) {
      $this->template = $template;
      $this->rows = $rows;
      $this->index = $index;
      $this->name = $name;
      $this->dataSets = [];
      $this->metaData = $this->getSheetLevelMetaDataStructureFromTemplate();
    }

    private function isBegginingOfSet($row){
      $regexMatch = $this->template['setMarkers']['start']['regexMatch'];
      $column = $this->template['setMarkers']['start']['column'] - 1;
      return isset($row[$column]) && preg_match('/'.$regexMatch.'/', $row[$column]);
    }

    private function isEndOfSet($row){
      if($row==null) {
        return true;
      }
      $regexMatch = $this->template['setMarkers']['end']['regexMatch'];
      $column = $this->template['setMarkers']['end']['column'] - 1;
      return isset($row[$column]) && preg_match('/'.$regexMatch.'/', $row[$column]);
    }

    private function getParsedRow($row) {
      $newRow = [];
      $keys = $this->template['setMarkers']['values']['keys'];
      foreach($keys as $key) {
        $newRow[$key['name']] = $this->getParsedValue(
          $key['name'],
          $row[$key['column']-1],
          $key['type'] ?? 'string',
          $key['regexMatch'] ?? null,
          $key['givenFormat'] ?? null,
          $key['outputFormat'] ?? null,
          $key['allMatchesMustBeEqual'] ?? false
        );
      }
      return $newRow;
    }

    protected function iterate() {
      $totalRows = count($this->rows);
      $newSet = null;
      $inDataArea = false;
      $startOffset = $this->template['setMarkers']['values']['startOffset'];
      $endOffset = $this->template['setMarkers']['values']['endOffset'];
      $setMetaDataStructure = $this->getSetLevelMetaDataStructureFromTemplate();
      $j = -1;
      foreach($this->rows as $i => $row) {

        // CHECK IF BEGGINING OF SET
        if($this->isBegginingOfSet($row)){
          $newSet = [];
          $newSetMetaData = array_map(function($meta) {
            return [$meta['key'] => null];
          }, $setMetaDataStructure);
          $newSetMetaData = array_merge(...$newSetMetaData);
          $j = -1;
        }
        
        $j++;

        // CHECK IF END OF SET
        //if($this->isEndOfSet($row)){
        if($this->isEndOfSet(@$this->rows[$i+$endOffset-1])){
          if($newSet) {
            $this->dataSets[] = $newSet;
          }
          $newSet = null;
          $inDataArea = false;
        }

        // CHECK IF IN DATA AREA
        if($newSet !== null && $j > ($startOffset-1) ){
          $inDataArea = true;
        }

        // CHECK IF IN META DATA AREA
        if($newSet !== null && !$inDataArea ){

          foreach($setMetaDataStructure as $key){
            if($newSetMetaData[$key['key']]) {
              continue;
            } 
            if($key['setRow'] == $j+1){
              $newSetMetaData[$key['key']] = $this->getParsedValue(
                $key['key'],
                $row[$key['setColumn']-1],
                $key['type'] ?? 'string',
                $key['regexMatch'] ?? null,
                $key['givenFormat'] ?? null,
                $key['outputFormat'] ?? null,
                $key['allMatchesMustBeEqual'] ?? false
              );
            }
          }
        }

        // IF IN SET, ADD DATA
        if($inDataArea){
          $newRow = $newSetMetaData;

          // ADD META DATA
          foreach($this->metaData as $key => $meta) {
            $newRow[$key] = $meta['value'];
          }

          // ADD VALUES
          $newRow = array_merge($newRow, $this->getParsedRow($row));
          // ADD NEW ROW TO SET
          array_push($newSet, $newRow);
        }
      }

    }
    public function getData() {
      $this->iterate();
      
      if(@$this->template['mergeSets']??false){
        $this->dataSets = array_merge(...$this->dataSets);
      }
        
      return $this->dataSets;
    }

  }
  class XLSReader {
    protected $xlsPath;
    protected $parsedXls;
    protected $template;
    protected $sheets;
    protected $realFileName;

    public function __construct($xlsPath, $template, $realFileName) {
      $this->xlsPath = $xlsPath;
      $this->template = $template;
      $this->realFileName = $realFileName;
      $this->parsedXls = \Shuchkin\SimpleXLS::parse($xlsPath);
      $sheetNames = $this->parsedXls->sheetNames();
      foreach ($sheetNames as $index => $name) {
        $sheetTemplate = array_filter($this->template['sheets'], function($sheet) use ($name) {
          return $sheet['name'] == $name;
        });
        if (empty($sheetTemplate)) {
          continue;
        }
        try {
          $this->sheets[$index] = new Sheet($sheetTemplate[0], $index, $name, $this->parsedXls->rows($index));
        } catch (\Throwable $e) {
          throw new \Exception("Error con el archivo «".$this->realFileName."»: " . $e->getMessage());
        }
      }
    }

    public function getAsJson(){
      return json_encode($this->getData());
    }
    public function getData(){
      $data = [];
      foreach ($this->sheets as $sheet) {
        //$data[$sheet->name()] = $sheet->getData();
        $data = array_merge($data, $sheet->getData());
      }
      return $data;
    }
  }
  
}