<?php

namespace Drupal\strawberryfield\KeyValueStore;

use Drupal\Component\Serialization\SerializationInterface;

use Drupal\Core\Database\Connection;

use Drupal\strawberryfield\KeyValueStore\DatabaseStorageWithIndex;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;


/**
 * Defines the key/value store factory for the database backend.
 */
class KeyValueDatabaseWithIndexFactory implements KeyValueFactoryInterface {

  /**
   * The serialization class to use.
   *
   * @var \Drupal\Component\Serialization\SerializationInterface
   */
  protected $serializer;

  /**
   * The database connection to use.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructs this factory object.
   *
   * @param \Drupal\Component\Serialization\SerializationInterface $serializer
   *   The serialization class to use.
   * @param \Drupal\Core\Database\Connection $connection
   *   The Connection object containing the key-value tables.
   */
  public function __construct(SerializationInterface $serializer, Connection $connection) {
    $this->serializer = $serializer;
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public function get($collection) {
    return new DatabaseStorageWithIndex($collection, $this->serializer, $this->connection);
  }

}
