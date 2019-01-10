<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 10/7/18
 * Time: 3:59 PM
 */

namespace Drupal\strawberryfield\Plugin\StrawberryfieldKeyNameProvider;

use Drupal\Core\Annotation\Translation;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Drupal\strawberryfield\Plugin\StrawberryfieldKeyNameProviderBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\UrlHelper;
use GuzzleHttp\Exception\ClientException;
use Drupal\Core\Cache\CacheBackendInterface;


/**
 *
 * JSON-LD Strawberry Field Key Name Provider
 *
 * @StrawberryfieldKeyNameProvider(
 *    id = "jsonld",
 *    label = @Translation("JSONLD Strawberry Field Key Name Provider")
 * )
 */
class JsonldKeyNameProvider extends StrawberryfieldKeyNameProviderBase {

  public function calculateDependencies() {
    // TODO: Implement calculateDependencies() method.
  }

  public function getFormClass($operation) {
    // TODO: Implement getFormClass() method.
  }

  public function hasFormClass($operation) {
    // TODO: Implement hasFormClass() method.
  }

  public function defaultConfiguration() {
    return [
       // e.g https://schema.org/docs/jsonldcontext.json"
      'url' => '',
        // Since JSON lists like schema.org can be huge
        // We allow people to provide a subset that will be used to filter agains
        // e.g https://schema.org/Book.jsonld
      'filterurl' => '',
      'keys' => '',
       // The id of the config entity from where these values came from.'
       'configEntity' => ''
      ] + parent::defaultConfiguration();
  }


  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $parents, FormStateInterface $form_state) {

    // url could be curl -iv -H"Accept:application/ld+json" https://schema.org/docs/jsonldcontext.json
    $element['url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL to JSON-LD @Context'),
      '#size' => 40,
      '#maxlength' => 255,
      '#default_value' => $this->getConfiguration()['url'],
      '#description' => $this->t('Enter a URL to a publicly available JSON-LD <em>@Context</em>.<br> e.g. https://schema.org/docs/jsonldcontext.json'),
     // '#parents' => $parents
      ];

    $element['filterurl'] = [
      '#type' => 'url',
      '#title' => $this->t('An addition URL to a JSON-LD Document to limit to a subset of the specified <em>@vocab</em>.'),
      '#size' => 40,
      '#maxlength' => 255,
      '#default_value' => $this->getConfiguration()['filterurl'],
      '#description' => $this->t('Enter a URL to a publicly available JSON-LD Document that matches the previous <em>@Context</em>.<br> e.g <em>https://schema.org/Book.jsonld</em>'),
    ];

    $element['keys'] = [
      '#type' => 'textarea',
      '#rows' => 5,
      '#title' => $this->t('Additional keys separated by commas'),
      '#default_value' => $this->getConfiguration()['keys'],
      '#description' => t('Additional properties that we would like to allow Strawberryfields to expose.'),
    ];
    return $element;
  }

  /**
   * @return array
   */
  public function provideKeyNames() {

    $processedvalues = $this->getCacheTheKeys();
    $validkeys = [];
    if (empty($processedvalues)) {
      $jsonldcontext = StrawberryfieldJsonHelper::SIMPLE_JSONLDCONTEXT;
      $processedvalues = json_decode($jsonldcontext, TRUE);
      $processedvalues = $processedvalues['@context'];
    }
    //user extra provided keys, if any.
    $extrakeys = explode(",",$this->getConfiguration()['keys']);
    $extrakeys = !empty($extrakeys) ? array_fill_keys($extrakeys,'stub'): [];
    $jsonld_reservedkeys = [
      '@context',
      '@id',
      '@value',
      '@language',
      '@type',
      '@container',
      '@list',
      '@set',
      '@reverse',
      '@index',
      '@base',
      '@vocab',
      '@graph',
      'label',
    ];
    // Mix all keys together.
    $validkeys = array_keys(
      array_merge(
        $processedvalues,
        array_fill_keys($jsonld_reservedkeys, 'stub'),
        $extrakeys
      )
    );
    // return them sorted for children's joy.
    sort($validkeys,SORT_NATURAL);

    return $validkeys;
  }

  protected function getCacheTheKeys($bypasscache = false) {
    // This is expensive, reason why we process and store in cache

    $config_entity_id = $this->getConfiguration()['configEntity'];
    if (!empty($config_entity_id)) {}
    //@TODO what happens if someone changes the pluginID in the plugin Manager list?
    //@TODO refactor to get the cache service as dependency injection
    $cid = 'strawberryfieldKeyNameProvider:'.$this->getPluginId().':'.$config_entity_id;
    $data = NULL;

    $cachetags = [
      'strawberryfield',
      'strawberry_keynameprovider:'.$config_entity_id,
    ];

    if (!$bypasscache && ($cache = \Drupal::cache()
      ->get($cid))) {
      $data = $cache->data;
    }
    else {
      $data = $this->processFromSource();

      if ($bypasscache === false) {
        \Drupal::cache()
          ->set(
            $cid,
            $data,
            CacheBackendInterface::CACHE_PERMANENT,
            $cachetags
          );
      }
    }
    return $data;
  }

  protected function getRemoteJsonData($remoteUrl) {
    // This is expensive, reason why we process and store in cache
    $jsondata = [];
    if (empty($remoteUrl)){
      // No need to alarm. all good. If not URL just return.
      return [];
    }
    if (!UrlHelper::isValid($remoteUrl, $absolute = TRUE)) {
      $this->messenger->addError(
        $this->t('We can not fetch Data from @pluginid, check your URL',
          ['@pluginid' => $this->label()]
        )
      );
    return [];
    }

    $options['headers']=['Accept' => 'application/ld+json'];
    try {
      $request = $this->httpClient->get($remoteUrl, $options);
    }
    catch(ClientException $exception) {
      $responseMessage = $exception->getMessage();
      $this->messenger->addError(
        $this->t('We tried to contact @url from @pluginid but we could not. <br> The WEB says: @response. <br> Check that URL!',
         [
           '@url' => $remoteUrl,
           '@pluginid' =>  $this->label(),
           '@response' => $responseMessage
         ]
        )
      );
      return [];
    }
    $body = $request->getBody()->getContents();
    $jsondata = json_decode($body, TRUE);
    $json_error = json_last_error();
    if ($json_error == JSON_ERROR_NONE) {
      return $jsondata;
    }
    $this->messenger->addError(
      $this->t('Looks like data fetched from @url by @pluginid is not in JSON format.<br> JSON says: @$jsonerror <br>Please check your URL!',
        [
          '@url' => $remoteUrl,
          '@pluginid' =>  $this->label(),
          '@$jsonerror' => $json_error
        ]
      )
    );
    return [];
  }

  protected function extractKeys(array $original, array $subset = []) {
    // This is expensive, reason why we process and store in cache
    // subset may need quite some processing.
    // https://schema.org/Book.jsonld.

    // @TODO move this into class StrawberryfieldJsonHelper
    $keystoreturn = [];
    if (!empty($subset)) {
      $flat = [];
      StrawberryfieldJsonHelper::arrayToFlatCommonkeys($subset,$flat,TRUE);
      $keyswewant = (isset($flat['@id']) && !empty($flat['@id'])) ? array_flip(array_values($flat['@id'])) : [];
      // $keyswewant will be either a prefix like schema:label or an URI

    }
    // Before we filter.

    if (!empty($keyswewant)) {
      // $value here is just a result of the flipping so not much use
      foreach ($original as $key => $value) {
        //@TODO replace this logic with an array_map()
        if (isset($value["@id"]) && array_key_exists($value["@id"],$keyswewant)) {
          $keystoreturn[$key] = $value;
        }
      }
    } else {
      // Means no filter. Contexts like schema can have 2000+ keys!
      // remove prefixes, only leave keys that declare an @id
      $keystoreturn = array_filter($original, 'is_array');

    }
    return $keystoreturn;
  }

  public function processFromSource() {

    $keys = [];
    $maindata = [];
    $filterData = [];

    $primaryURL = $this->getConfiguration()['url'];
    $maindata = $this->getRemoteJsonData($primaryURL);
    if (!empty($maindata)) {

      $maindata = isset($maindata['@context']) ? $maindata['@context'] : $maindata;
      $filterURL = $this->getConfiguration()['filterurl'];

      // We won't filter things out there, ::extractKeys will deal with that.
      $filterData = $this->getRemoteJsonData($filterURL);

      $keys = $this->extractKeys($maindata, $filterData);

    }
    return $keys;

  }



}