<?php

namespace Drupal\strawberryfield\Form;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\strawberryfield\Ajax\UpdateCodeMirrorCommand;

/**
 * Returns responses for Node routes.
 */
class StrawberryfieldToolsForm extends FormBase {

  /**
   * Constructs a StrawberryfieldToolsForm object.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function __construct(RendererInterface $renderer, EntityRepositoryInterface $entity_repository) {
    $this->entityRepository = $entity_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('renderer'),
      $container->get('entity.repository')
    );
  }

  public function getFormId() {
    return 'strawberryfield_tools_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $node = NULL) {

    // For code Mirror
    // @TODO make this module dependant
    $settings['mode'] = 'application/ld+json';
    $settings['readOnly'] = TRUE;
    $settings['toolbar'] = FALSE;
    $settings['lineNumbers'] = TRUE;

    if ($sbf_fields = \Drupal::service('strawberryfield.utility')->bearsStrawberryfield($node)) {
      foreach ($sbf_fields as $field_name) {
        /* @var $field \Drupal\Core\Field\FieldItemInterface */
        $field = $node->get($field_name);
        if (!$field->isEmpty()) {
          /** @var $field \Drupal\Core\Field\FieldItemList */
          foreach ($field->getIterator() as $delta => $itemfield) {
            // Note: we are not longer touching the metadata here.
            /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */
            $json = json_encode(json_decode($itemfield->value), JSON_PRETTY_PRINT);
            $form_state->set('itemfield', $itemfield);
            $form['test_jmespath'] = [
              '#type' => 'textfield',
              '#default_value' => $form_state->getValue('test_jmespath'),
              '#title' => $this->t('JMESPATH'),
              '#description' => $this->t(
                'Evaluate a JMESPath Query against this ADO\'s JSON. See <a href=":href" target="_blank">JMESPath Tutorial</a>.',
                [':href' => 'http://jmespath.org/tutorial.html']
              ),

              '#ajax' => [
                'callback' => [$this, 'callJmesPathprocess'],
                'event' => 'change',
                'keypress' => FALSE,
                'disable-refocus' => FALSE,
                'progress' => [
                  // Graphic shown to indicate ajax. Options: 'throbber' (default), 'bar'.
                  'type' => 'throbber',
                ],
              ],
              '#required' => TRUE,
              '#executes_submit_callback' => TRUE,
              '#submit' =>  ['::submitForm']
            ];
            $form['test_output'] = [
              '#type' => 'codemirror',
              '#prefix' => '<div id="jmespathoutput">',
              '#suffix' => '</div>',
              '#codemirror' => $settings,
              '#default_value' => '{}',
              '#rows' => 15,
              '#attached' => [
                'library' => [
                  'strawberryfield/jmespath_codemirror_strawberryfield',
                ],
              ],
            ];
            $form['test_jmespath_input'] = [
              '#type' => 'codemirror',
              '#codemirror' => $settings,
              '#default_value' => $json,
              '#rows' => 15,
            ];
          }
        }
      }
    }
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => t('Submit'),
      '#attributes' => ['class' => ['js-hide']],
      '#submit' =>  [[$this,'submitForm']]
    ];
    return $form;
  }

  /**
   * @param array                                $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild();
  }

  /**
   * @param array                                $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function callJmesPathprocess(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */
    $itemfield = $form_state->get('itemfield');
    try {
      $result = $itemfield->searchPath($form_state->getValue('test_jmespath'));
    }
    catch (\Exception $exception) {
      $result = $exception->getMessage();
    }

    $response->addCommand(new UpdateCodeMirrorCommand('#jmespathoutput', json_encode($result,JSON_PRETTY_PRINT)));

    return $response;
  }
}
