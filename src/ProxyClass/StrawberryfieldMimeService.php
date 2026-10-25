<?php
// @codingStandardsIgnoreFile

/**
 * This file was generated via php core/scripts/generate-proxy-class.php 'Drupal\strawberryfield\StrawberryfieldMimeService' "web/modules/contrib/strawberryfield/src".
 */

namespace Drupal\strawberryfield\ProxyClass {

  use Drupal\Core\DependencyInjection\DependencySerializationTrait;
  use Symfony\Component\DependencyInjection\ContainerInterface;
  use Drupal\Core\File\MimeType\ExtensionMimeTypeGuesser;

  /**
   * Provides a proxy class for \Drupal\strawberryfield\StrawberryfieldMimeService.
   *
   * @see \Drupal\Component\ProxyBuilder
   */
  class StrawberryfieldMimeService extends ExtensionMimeTypeGuesser {

    use DependencySerializationTrait;

    /**
     * The real proxied service, after it was lazy loaded.
     *
     * @var \Drupal\strawberryfield\StrawberryfieldMimeService
     */
    protected $service;

    /**
     * The service container.
     *
     * @var \Symfony\Component\DependencyInjection\ContainerInterface
     */
    protected $container;

    /**
     * Constructs a ProxyClass Drupal proxy object.
     *
     * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
     *   The container.
     * @param string $drupalProxyOriginalServiceId
     *   The service ID of the original service.
     */
    public function __construct(ContainerInterface $container, /**
     * The id of the original proxied service.
     */
    protected $drupalProxyOriginalServiceId)
    {
      $this->container = $container;
    }

    /**
     * Lazy loads the real service from the container.
     *
     * @return object
     *   Returns the constructed real service.
     */
    protected function lazyLoadItself()
    {
      if (!isset($this->service)) {
        $this->service = $this->container->get($this->drupalProxyOriginalServiceId);
      }

      return $this->service;
    }

    /**
     * {@inheritdoc}
     */
    public function inverseGuess($mimetype)
    {
      return $this->lazyLoadItself()->inverseGuess($mimetype);
    }

    /**
     * {@inheritdoc}
     */
    public function guess($path)
    {
      return $this->lazyLoadItself()->guess($path);
    }

    /**
     * {@inheritdoc}
     */
    public function guessMimeType($path): ?string
    {
      return $this->lazyLoadItself()->guessMimeType($path);
    }


    /**
     * {@inheritdoc}
     */
    public function setMapping(array $mapping = NULL): void
    {
      $this->lazyLoadItself()->setMapping($mapping);
    }

  }

}
