<?php
namespace marianojwl\metacrud {
  class QueryMaker {
    protected $conn;

    protected $table;
    public function table($table) { $this->table = $table; return $this; }

    protected $columns = [];
    public function columns($columns) { $this->columns = $columns; return $this; }
    public function addColumn($column) { $this->columns[] = $column; return $this; }
    
    protected $conditions = [];
    public function conditions($conditions) { $this->conditions = $conditions; return $this; }
    public function addCondition($condition) { $this->conditions[] = $condition; return $this; }

    private function getMainTableConditions() {
      return [];
    }

    public function getSelectQuery() {
      $sql = "";
      $sql .= "SELECT ";
      $sql .= implode(", ", $this->columns);
      $sql .= " FROM (SELECT * FROM " . $this->table . " ";
      $mainTableConditions = $this->getMainTableConditions();
      $sql .= implode(" ", $mainTableConditions);
      $sql .= " ) AS _ ";
      return $sql;
    }

    public function __construct($conn) {
      $this->conn = $conn;
    }
  }
}