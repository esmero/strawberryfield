<?php

namespace Drupal\strawberryfield;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Drupal\search_api\Query\QueryInterface;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;

/**
 * Provides the most basic Container we can keep a memory state of past Search API events.
 */
class StrawberryfieldSearchAPIUtilityService implements StrawberryfieldSearchAPIUtilityServiceInterface {

  protected $isIndexing = FALSE;

  public function isIndexing(): bool
  {
    return $this->isIndexing;
  }

  public function setIsIndexing(bool $isIndexing): void
  {
    $this->isIndexing = $isIndexing;
  }


}
