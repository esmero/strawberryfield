<?php

namespace Drupal\strawberryfield\Plugin\Action;

use Drupal\Component\Diff\Diff;
use Drupal\Component\Diff\DiffFormatter;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Plugin\Action\EntityActionBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Psr\Log\LoggerInterface;
use Swaggest\JsonDiff\Exception as JsonDiffException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Swaggest\JsonDiff\JsonPatch;
use Swaggest\JsonDiff\JsonDiff;

/**
 * Provides an action that can Modify Entity attached SBFs via JSON Patch.
 *
 * @Action(
 *   id = "entity:jsonpatch_action",
 *   action_label = @Translation("JSON Patch an ADO"),
 *   label = @Translation("JSON Patch an ADO"),
 *   category = @Translation("Metadata"),
 *   type = "node"
 * )
 */
class StrawberryfieldJsonPatch extends ConfigurableActionBase implements DependentPluginInterface, ContainerFactoryPluginInterface {

  /**
   *  *   deriver = "Drupal\Core\Action\Plugin\Action\Derivative\EntityChangedActionDeriver",
   * confirm_form_route_name = "strawberryfield.multiple_patch_confirm"
   *
   * JSON SCHEMA for a JSON Patch Operation @see https://github.com/fge/sample-json-schemas/blob/master/json-patch/json-patch.json
   */
  const acceptedjsonschema = <<<'JSON'
{
    "title": "JSON Patch",
    "description": "A JSON Schema describing a JSON Patch",
    "$schema": "http://json-schema.org/draft-04/schema#",
    "notes": [
        "Only required members are accounted for, other members are ignored"
    ],
    "type": "array",
    "items": {
        "description": "one JSON Patch operation",
        "allOf": [
            {
                "description": "Members common to all operations",
                "type": "object",
                "required": [ "op", "path" ],
                "properties": {
                    "path": { "$ref": "#/definitions/jsonPointer" }
                }
            },
            { "$ref": "#/definitions/oneOperation" }
        ]
    },
    "definitions": {
        "jsonPointer": {
            "type": "string",
            "pattern": "^(/[^/~]*(~[01][^/~]*)*)*$"
        },
        "add": {
            "description": "add operation. Value can be any JSON value.",
            "properties": { "op": { "enum": [ "add" ] } },
            "required": [ "value" ]
        },
        "remove": {
            "description": "remove operation. Only a path is specified.",
            "properties": { "op": { "enum": [ "remove" ] } }
        },
        "replace": {
            "description": "replace operation. Value can be any JSON value.",
            "properties": { "op": { "enum": [ "replace" ] } },
            "required": [ "value" ]
        },
        "move": {
            "description": "move operation. \"from\" is a JSON Pointer.",
            "properties": {
                "op": { "enum": [ "move" ] },
                "from": { "$ref": "#/definitions/jsonPointer" }
            },
            "required": [ "from" ]
        },
        "copy": {
            "description": "copy operation. \"from\" is a JSON Pointer.",
            "properties": {
                "op": { "enum": [ "copy" ] },
                "from": { "$ref": "#/definitions/jsonPointer" }
            },
            "required": [ "from" ]
        },
        "test": {
            "description": "test operation. Value can be any JSON value.",
            "properties": { "op": { "enum": [ "test" ] } },
            "required": [ "value" ]
        },
        "oneOperation": {
            "oneOf": [
                { "$ref": "#/definitions/add" },
                { "$ref": "#/definitions/remove" },
                { "$ref": "#/definitions/replace" },
                { "$ref": "#/definitions/move" },
                { "$ref": "#/definitions/copy" },
                { "$ref": "#/definitions/test" }
            ]
        }
    }
}
JSON;
  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The tempstore object.
   *
   * @var \Drupal\Core\TempStore\SharedTempStore
   */
  protected $tempStore;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;
  /**
   * The Strawberry Field Utility Service.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldUtilityService
   */
  protected $strawberryfieldUtility;

  /**
   * A Json Decoded and validated Patch
   *
   * @var array
   */
  protected $patchArray = [];


  /**
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * StrawberryfieldJsonPatch constructor.
   *
   * @param array $configuration
   * @param $plugin_id
   * @param $plugin_definition
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * @param \Drupal\strawberryfield\StrawberryfieldUtilityService $strawberryfield_utility_service
   * @param \Psr\Log\LoggerInterface $logger
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PrivateTempStoreFactory $temp_store_factory, AccountInterface $current_user, StrawberryfieldUtilityService $strawberryfield_utility_service, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->tempStore = $temp_store_factory->get('sbf_json_patch');
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('tempstore.private'),
      $container->get('current_user'),
      $container->get('strawberryfield.utility'),
      $container->get('logger.factory')->get('action')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $objects) {
    $results = [];
    foreach ($objects as $entity) {
      $results[] = $this->execute($entity);
    }

    return $results;
  }


  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    /** @var \Drupal\Core\Entity\EntityInterface $entity */
    $patched = FALSE;
    if ($entity) {
      if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
        $entity
      )) {
        $this->patchArray = json_decode($this->configuration['jsonpatch']);
        foreach ($sbf_fields as $field_name) {
          /* @var $field \Drupal\Core\Field\FieldItemInterface */
          $field = $entity->get($field_name);
          /* @var \Drupal\strawberryfield\Field\StrawberryFieldItemList $field */
          $entity = $field->getEntity();
          /** @var $field \Drupal\Core\Field\FieldItemList */
          $patched = FALSE;
          foreach ($field->getIterator() as $delta => $itemfield) {
            /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */
            $fullvalues = $itemfield->provideDecoded(FALSE);
            $fullvaluesoriginal = clone $fullvalues;
            $patch = JsonPatch::import($this->patchArray);
            try {
              $patch->apply($fullvalues, TRUE);
              if (!$field[$delta]->setMainValueFromArray((array) $fullvalues)) {
                $this->messenger()->addError(
                  $this->t(
                    'We could not persist patched metadata for @entity. Please contact the site admin.',
                    [
                      '@entity' => $entity->label()
                    ]
                  )
                );
              };
              if ($this->configuration['simulate']) {
                $r = new JsonDiff(
                  $fullvaluesoriginal,
                  $fullvalues,
                  JsonDiff::REARRANGE_ARRAYS + JsonDiff::SKIP_JSON_MERGE_PATCH
                );
                // We just keep track of the changes. If none! Then we do not set
                // the formstate flag.
                  $message = $this->formatPlural($r->getDiffCnt(),
                    'Simulated patch: Digital Object @label would one modification',
                    'Simulated patch: Digital Object @label would @count modifications',
                    ['@label' => $entity->label()]);
                 // This is not as accurate as the JSON Patch but is a good hint
                $visualjsondiff = new Diff(explode(PHP_EOL,json_encode($fullvaluesoriginal,JSON_PRETTY_PRINT)), explode(PHP_EOL,json_encode($fullvalues, JSON_PRETTY_PRINT)));
                $formatter = new DiffFormatter();
                $output = $formatter->format($visualjsondiff);
                $this->messenger()->addMessage($message);
                $this->messenger()->addMessage($output);
               }
              $patched = TRUE;
            } catch (JsonDiffException $exception) {
              $patched = FALSE;
              $this->messenger()->addWarning(
                $this->t(
                  'Patch could not be applied for @entity',
                  [
                    '@entity' => $entity->label()
                  ]
                )
              );
            }
          }
        }
        if ($patched) {
          $this->logger->notice('%label had the following JSON Patch applied: @jsonpatch', [
            '%label' => $entity->label(),
            '@jsonpatch' => '<pre><code>'.$this->configuration['jsonpatch'].'</code></pre>'

          ]);
          if (!$this->configuration['simulate']) {
            if ($entity->getEntityType()->isRevisionable()) {
              // Forces a New Revision for Not-create Operations.
              $entity->setNewRevision(TRUE);
              $entity->setRevisionCreationTime(\Drupal::time()->getRequestTime());
              // Set data for the revision
              $entity->setRevisionLogMessage('ADO modified via JSON Patch Search And Replace with Patch:'. $this->configuration['jsonpatch']);
              $entity->setRevisionUserId($this->currentUser->id());
            }
            $entity->save();
          }
        }
      }
    }
  }


  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {

    /** @var \Drupal\Core\Entity\EntityInterface $object */
    $result = $object->access('update', $account, TRUE)
      ->andIf(AccessResult::allowedIfHasPermission($account, 'JSON Patch Archipelago Digital Objects'));
    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $module_name = $this->entityTypeManager
      ->getDefinition($this->getPluginDefinition()['type'])
      ->getProvider();
    return ['module' => [$module_name]];
  }


  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Don't want to make this a dependency, but if its there, let's use it
    if (\Drupal::moduleHandler()->moduleExists('codemirror_editor')) {
      $form['jsonpatch'] = [
        '#type' => 'codemirror',
        '#title' => t('JSON Patch commands'),
        '#default_value' => $this->configuration['jsonpatch'],
        '#codemirror' => [
          'modeSelect' => [
            'application/json' => $this->t('JSON'),
            'javascript' => $this->t('JavaScript'),
          ],
          'lineWrapping' => TRUE,
          'lineNumbers' => TRUE,
          'autoCloseTags' => FALSE,
          'styleActiveLine' => TRUE,
        ],
        '#cols' => '80',
        '#rows' => '20',
        '#description' => t('Jsonpatch operations and conditionals to be applied on an ADO. You can use also tokens [node:title], [user:account-name], [user:display-name] and [comment:body] to represent data that will be different each time the operations are applied. Not all placeholders will be available in all contexts.'),
      ];
    }
    else {
      $form['jsonpatch'] = [
        '#type' => 'textarea',
        '#title' => t('JSON Patch commands'),
        '#default_value' => $this->configuration['jsonpatch'],
        '#cols' => '80',
        '#rows' => '20',
        '#description' => t('JSON Patch operations and conditionals to be applied on an ADO. You can use also tokens [node:title], [user:account-name], [user:display-name] and [comment:body] to represent data that will be different each time the operations are applied. Not all placeholders will be available in all contexts.'),
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {

    if (!StrawberryfieldJsonHelper::isValidJsonSchema($form_state->getValue('jsonpatch'), $this::acceptedjsonschema)) {
      $form_state->setErrorByName('jsonpatch', t('The JSON Patch provided is not correctly formed'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['jsonpatch'] = $form_state->getValue('jsonpatch');
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'jsonpatch' => '',
      'simulate' => FALSE,
    ];
  }

}
