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
use GuzzleHttp\Exception\GuzzleException;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\File\FileSystemInterface;


/**
 *
 * JSON-LD Strawberry Field Key Name Provider
 *
 * @StrawberryfieldKeyNameProvider(
 *    id = "jsonld",
 *    label = @Translation("JSONLD Strawberry Field Key Name Provider"),
 *    processor_class = "\Drupal\strawberryfield\Plugin\DataType\StrawberryValuesFromJson",
 *    item_type = "string"
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
        // We allow a subset that will be used to filter against
        // e.g https://schema.org/Book.jsonld
        'filterurl' => '',
        'keys' => '',
        'configEntity' => NULL
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
   * {@inheritdoc}
   */
  public function provideKeyNames(string $config_entity_id = NULL) {

    // In case an empty config_entity_id is passed here try to fetch from
    // local config.
    $config_entity_id = !empty($config_entity_id) ?
      $config_entity_id : $this->getConfiguration()['configEntity'];

    $processedvalues = $this->getCacheTheKeys($config_entity_id);

    if (empty($processedvalues)) {
      $jsonldcontext = StrawberryfieldJsonHelper::SIMPLE_JSONLDCONTEXT;
      $processedvalues = json_decode($jsonldcontext, TRUE);
      $processedvalues = $processedvalues['@context'];
    }
    //user extra provided keys, if any.
    $extrakeys = explode(",",$this->getConfiguration()['keys']);
    $extrakeys = array_map('trim', $extrakeys);
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
    // Mix all keys together and remove values
    // Values in this case are very rich, like if something is an @id or a value
    // Even DataTypes
    // @TODO future use those rich values to drive logic

    $validkeys = array_keys(
      array_merge(
        $processedvalues,
        array_fill_keys($jsonld_reservedkeys, 'stub'),
        $extrakeys
      )
    );
    // return them sorted for children's joy.
    sort($validkeys,SORT_NATURAL);

    // Property creation uses a property name and a key. In this case both are
    // the same reason we combine.
    return array_combine($validkeys,$validkeys);
  }

  /**
   * @param string $config_entity_id
   *   The unique config entity id machine name used by the config entity.
   *   This value is comes from the config entity used to store all this settings
   *   and needed to generate also separate cache bins for each.
   *   Plugin Instance.
   * @param bool $bypasscache
   *
   * @return array|null
   */
  protected function getCacheTheKeys(string $config_entity_id = NULL, $bypasscache = false) {
    // This is expensive, reason why we process and store in cache

    $data = NULL;
    $cachetags = [
      'strawberryfield',
      'strawberryfieldKeyNameProvider:' . $this->getPluginId()
    ];

    if (!empty($config_entity_id)) {
      $cid = 'strawberryfieldKeyNameProvider:' . $this->getPluginId(
        ) . ':' . $config_entity_id;
      $cachetags[] = $cid;
    }
    else {
      $cid = NULL;
    }

    if ($cid === NULL || $bypasscache) {
      $data = $this->processFromSource();
    }
    elseif ($cid !== NULL && $cache = \Drupal::cache()
        ->get($cid)) {
      $data = $cache->data;
    }
    else {
      $data = $this->processFromSource();
    }

    if ($cid !== NULL) {
      //@TODO refactor to get the cache service as dependency injection
      \Drupal::cache()
        ->set(
          $cid,
          $data,
          CacheBackendInterface::CACHE_PERMANENT,
          $cachetags
        );
    }

    $cleandata = array_filter($data, 'is_string',ARRAY_FILTER_USE_KEY);

    return $cleandata;
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
      $filderdatapre = $this->getRemoteJsonData($filterURL);
      $filterData = $filderdatapre ? $filderdatapre : [];

      $keys = $this->extractKeys($maindata, $filterData);

    }
    return $keys;

  }

  protected function getRemoteJsonData($remoteUrl) {
    // This is expensive, reason why we process and store in cache
    $jsondata = [];
    $remoteUrl = trim($remoteUrl);
    if (empty($remoteUrl)){
      // No need to alarm. all good. If no URL just return.
      return [];
    }
    if (!UrlHelper::isValid($remoteUrl, $absolute = TRUE)) {
      $this->messenger->addError(
        $this->t('Provided URL @url for @pluginid is invalid. Replace it with a valid one.',
          [
            '@pluginid' => $this->label(),
            '@url' => $remoteUrl,
          ]
        )
      );
      return [];
    }
    // Let's check if we have a downloaded local version around
    // https://api.drupal.org/api/drupal/core%21includes%21file.inc/function/file_unmanaged_save_data/8.2.x
    $possible_name = hash('md5',$remoteUrl);
    $directory =  "public://jsonld";
    $path = "{$directory}/{$possible_name}.jsonld";
    $filecache = FALSE;
    if (file_exists($path)) {
      $filecache = file_get_contents($path, FALSE);
    }

    if ($filecache) {
      $jsondata = json_decode($filecache, TRUE);
      $json_error = json_last_error();
      if ($json_error == JSON_ERROR_NONE) {
        return is_array($jsondata) ? $jsondata: [$jsondata];
      } else {
        // Basically whatever that we have is not JSON, lets go for it again.
        $filecache = FALSE;
      }
    }

    if (!$filecache) {
      // If we had no local cache file, lets do the HTTP thing.
      // Funny. Today Dec 3 2019 schema.org was out. That is the reason we do this.
      $options['headers'] = ['Accept' => 'application/ld+json'];
      try {
        $request = $this->httpClient->get($remoteUrl, $options);
      } catch (GuzzleException $exception) {
        $responseMessage = $exception->getMessage();
        $responseCode = $exception->getCode();
        $this->messenger->addError(
          $this->t(
            'We tried to contact @url from @pluginid but we could not. <br> HTTP with code @code says: @response. <br> Check that URL or try later again!',
            [
              '@url' => $remoteUrl,
              '@pluginid' => $this->label(),
              '@response' => $responseMessage,
              '@code' => $responseCode,
            ]
          )
        );
        return [];
      }
      $body = $request->getBody()->getContents();

      $jsondata = json_decode($body, TRUE);
      $json_error = json_last_error();
      if ($json_error == JSON_ERROR_NONE) {
        // Lets deposit a cached file version, just in case
        if (\Drupal::service('file_system')->prepareDirectory(
          $directory,
          FileSystemInterface::CREATE_DIRECTORY
        )) {
          if (!\Drupal::service('file_system')->saveData(
            $body,
            $path,
            FileSystemInterface::EXISTS_REPLACE
          )) {
            $this->messenger->addWarning(
              $this->t(
                'We tried to generate a local cached copy of @url at @path, but we could not. Please check your logs. Not terrible, just a warning.',
                [
                  '@url' => $remoteUrl,
                  '@path' => $path,
                ]
              )
            );
          }
        }
      }
      return is_array($jsondata) ? $jsondata: [$jsondata];
    }
    // This means we had an error on the JSON decode.
    $this->messenger->addError(
      $this->t(
        'Looks like data fetched from @url by @pluginid is not in JSON format.<br> JSON says: @$jsonerror <br>Please check your URL!',
        [
          '@url' => $remoteUrl,
          '@pluginid' => $this->label(),
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

}