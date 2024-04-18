<?php

namespace Drupal\strawberryfield\KeyValueStore;

use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\KeyValueStore\DatabaseStorage;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;

/**
 * Defines a default key/value store implementation.
 *
 * This is Drupal's default key/value store implementation. It uses the database
 * to store key/value data.
 */
class DatabaseStorageWithIndex extends DatabaseStorage {

  use DependencySerializationTrait;

  /**
   * The serialization class to use.
   *
   * @var \Drupal\Component\Serialization\SerializationInterface
   */
  protected $serializer;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The name of the SQL table to use.
   *
   * @var string
   */
  protected $table;


  public function listKeys(int $offset = 0, int $count = 100) {
      try {
        $result = $this->connection->queryRange('SELECT [name] FROM {' . $this->connection->escapeTable($this->table) . '} WHERE [collection] = :collection ORDER BY [name] ASC', $offset, $count, [
          ':collection' => $this->collection
        ]);
      }
      catch (\Exception $e) {
        $this->catchException($e);
        $result = [];
      }
      $values = [];
      foreach ($result as $item) {
        if ($item) {
          $values[$item->name] = $values[$item->name];
        }
      }
      return $values;
  }

  public function getMultipleListed(int $offset = 0, int $count = 100) {
    try {
      $result = $this->connection->queryRange('SELECT [name] [value] FROM {' . $this->connection->escapeTable($this->table) . '} WHERE [collection] = :collection ORDER BY [name] ASC', $offset, $count, [
        ':collection' => $this->collection,
      ]);
    }
    catch (\Exception $e) {
      $this->catchException($e);
      $result = [];
    }
    $values = [];
    foreach ($result as $item) {
      if ($item) {
        $values[$item->name] = $this->serializer->decode($item->value);
      }
    }
    return $values;
  }

}
