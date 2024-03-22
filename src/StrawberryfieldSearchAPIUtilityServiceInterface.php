<?php

namespace Drupal\strawberryfield;

interface StrawberryfieldSearchAPIUtilityServiceInterface
{

  public function isIndexing(): bool;


  public function setIsIndexing(bool $isIndexing): void;

}
