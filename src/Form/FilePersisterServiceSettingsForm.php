<?php

namespace Drupal\strawberryfield\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\Url;
use Drupal\strawberryfield\EventSubscriber\StrawberryfieldEventInsertSubscriberDepositDO;
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\MessageCommand;

/**
 * ConfigurationForm for Strawberryfield File Storage.
 */
class FilePersisterServiceSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'strawberryfield.filepersister_service_settings',
      'strawberryfield.storage_settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'strawberryfield_filepersister_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('strawberryfield.filepersister_service_settings');
    $config_storage = $this->config('strawberryfield.storage_settings');
    $scheme_options = OcflHelper::getVisibleStreamWrappers();
    $form['file_scheme'] = [
      '#type' => 'radios',
      '#title' => $this->t('Storage Scheme for Persisting Files'),
      '#description' => $this->t('Please provide your prefered Storage Scheme for Persisting Strawberryfield managed Files'),
      '#default_value' => $config_storage ->get('file_scheme'),
      '#options' => $scheme_options,
      '#required' => TRUE

    ];
    $form['file_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Relative Path for Persisting Files'),
      '#description' => $this->t('Path relative to the root of the storage scheme selected above where the hashed directories used to store persisted managed files will be created. Do not include beginning or ending slashes. Default is "".
                                  <br>Note that changing this setting will not affect the file storage locations for previously ingested objects. They will remain where they were.'),
      '#default_value' => !empty($config_storage->get('file_path')) ? $config_storage->get('file_path') : "",
      '#prefix' => '<span class="file-path-validation"></span>',
      '#ajax' => [
        'callback' => [$this, 'validateFilePath'],
        'effect' => 'fade',
        'wrapper' => 'file-path-validation',
        'method' => 'replace',
        'event' => 'change',
      ],
    ];
    $form['object_file_scheme'] = [
      '#type' => 'radios',
      '#title' => $this->t('Storage Scheme for Persisting Digital Objects'),
      '#description' => $this->t('Please provide your prefered Storage Scheme for Persisting Digital Objects as JSON Files'),
      '#default_value' => $config_storage ->get('object_file_scheme'),
      '#options' => $scheme_options,
      '#required' => TRUE
    ];
    $form['object_file_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Relative Path for Persisting Digital Object Files'),
      '#description' => $this->t('Path relative to the root of the storage scheme selected above where digital object files will be stored. Do not include beginning or ending slashes. Default is "@storage".
                                  <br>Note that changing this setting will not affect the file storage locations for previously ingested objects. They will remain where they were.',
        ['@storage' => StrawberryfieldEventInsertSubscriberDepositDO::DEFAULT_OBJECT_STORAGE_FILE_PATH]),
      '#default_value' => !empty($config_storage->get('object_file_path')) ? $config_storage->get('object_file_path') : StrawberryfieldEventInsertSubscriberDepositDO::DEFAULT_OBJECT_STORAGE_FILE_PATH,
      '#prefix' => '<span class="object-file-path-validation"></span>',
      '#ajax' => [
        'callback' => [$this, 'validateObjectFilePath'],
        'effect' => 'fade',
        'wrapper' => 'object-file-path-validation',
        'method' => 'replace',
        'event' => 'change',
      ],
    ];

    $form['extractmetadata'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Should File level metadata extraction be processed?'),
      '#description' => $this->t('If enabled, exiftool and FIDO will run on every file.'),
      '#default_value' => !empty($config->get('extractmetadata')) ? $config->get('extractmetadata'): FALSE,
      '#return_value' => TRUE,
    ];
    $form['exif_exec_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Absolute path to the exiftool inside your server'),
      '#description' => $this->t('exiftool will run on every file associated to an Archipelago Digital Object and resulting metadata will be appended to the strawberryfield JSON'),
      '#default_value' => !empty($config->get('exif_exec_path')) ? $config->get('exif_exec_path'): '/usr/bin/exiftool',
      '#prefix' => '<span class="exif-exec-path-validation"></span>',
      '#states' => [
        'visible' => [
          ':input[name="extractmetadata"]' => ['checked' => TRUE],
        ],
      ],
      '#ajax' => [
        'callback' => [$this, 'validateExif'],
        'effect' => 'fade',
        'wrapper' => 'exif-exec-path-validation',
        'method' => 'replace',
        'event' => 'change'
      ]
    ];
    $form['fido_exec_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Absolute path to the FIDO tool binary inside your server'),
      '#description' => $this->t('FIDO will run against any file associated to an Archipelago Digital Object and resulting PRONOM ID will be appended to the strawberryfield JSON'),
      '#default_value' => !empty($config->get('fido_exec_path')) ? $config->get('fido_exec_path'): '/usr/bin/fido',
      '#states' => [
        'visible' => [
          ':input[name="extractmetadata"]' => ['checked' => TRUE],
        ],
      ],
      '#prefix' => '<span class="fido-exec-path-validation"></span>',
      '#ajax' => [
        'callback' => [$this, 'validateFido'],
        'effect' => 'fade',
        'wrapper' => 'fido-exec-path-validation',
        'method' => 'replace',
        'event' => 'change'
      ]
    ];
    $form['identify_exec_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Absolute path to the Identify tool binary inside your server'),
      '#description' => $this->t('Identify will run against any file associated to an Archipelago Digital Object and resulting characterization will be appended to the strawberryfield JSON. This is specially useful when dealing with PDFs that have different page dimensions.'),
      '#default_value' => !empty($config->get('identify_exec_path')) ? $config->get('identify_exec_path'): '/usr/bin/identify',
      '#states' => [
        'visible' => [
          ':input[name="extractmetadata"]' => ['checked' => TRUE],
        ],
      ],
      '#prefix' => '<span class="identify-exec-path-validation"></span>',
      '#ajax' => [
        'callback' => [$this, 'validateIdentify'],
        'effect' => 'fade',
        'wrapper' => 'identify-exec-path-validation',
        'method' => 'replace',
        'event' => 'change'
      ]
    ];

    $form['pdfinfo_exec_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Absolute path to the PDFInfo tool binary inside your server'),
      '#description' => $this->t('PDFInfo will run against any PDF or PS file associated to an Archipelago Digital Object and resulting Mediabox and page numbers will be appended to the strawberryfield JSON. This is specially useful when dealing with PDFs that have different page dimensions and complements Identify.'),
      '#default_value' => !empty($config->get('pdfinfo_exec_path')) ? $config->get('pdfinfo_exec_path'): '/usr/bin/pdfinfo',
      '#states' => [
        'visible' => [
          ':input[name="extractmetadata"]' => ['checked' => TRUE],
        ],
      ],
      '#prefix' => '<span class="pdfinfo-exec-path-validation"></span>',
      '#ajax' => [
        'callback' => [$this, 'validatePdfinfo'],
        'effect' => 'fade',
        'wrapper' => 'pdfinfo-exec-path-validation',
        'method' => 'replace',
        'event' => 'change'
      ]
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Validate File Path
   *
   * @param  array  $form
   * @param  \Drupal\Core\Form\FormStateInterface  $form_state
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function validateFilePath(
    array $form,
    FormStateInterface $form_state
  ) {
    $response = new AjaxResponse();
    $path = $form_state->getValue('file_path');
    $scheme = $form_state->getValue('file_scheme');
    $valid = \Drupal::service('strawberryfield.utility')->filePathIsValid($scheme, $path);
    if (!$valid) {
      $warning_message = $this->filePathErrorMessage('file', $path);
      $response->addCommand(new InvokeCommand('#edit-file-path', 'addClass',
        ['error']));
      $response->addCommand(new InvokeCommand('#edit-file-path', 'removeClass',
        ['ok']));
      $response->addCommand(new MessageCommand($warning_message, NULL,
        ['type' => 'error', 'announce' => 'file path is not valid.']));

    }
    else {
      $response->addCommand(new InvokeCommand('#edit-file-path', 'removeClass',
        ['error']));
      $response->addCommand(new InvokeCommand('#edit-file-path', 'addClass',
        ['ok']));
      $response->addCommand(new MessageCommand('Relative file path is valid for ' . $scheme . '://',
        NULL, ['type' => 'status', 'announce' => 'file path is valid!']));

    }
    return $response;
  }

  /**
   * Validate Object File Path
   *
   * @param  array  $form
   * @param  \Drupal\Core\Form\FormStateInterface  $form_state
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function validateObjectFilePath(
    array $form,
    FormStateInterface $form_state
  ) {
    $response = new AjaxResponse();
    $path = $form_state->getValue('object_file_path');
    $scheme = $form_state->getValue('object_file_scheme');
    $valid = \Drupal::service('strawberryfield.utility')->filePathIsValid($scheme, $path);
    if (!$valid) {
      $warning_message = $this->filePathErrorMessage('object file', $path);
      $response->addCommand(new InvokeCommand('#edit-object-file-path',
        'addClass', ['error']));
      $response->addCommand(new InvokeCommand('#edit-object-file-path',
        'removeClass', ['ok']));
      $response->addCommand(new MessageCommand($warning_message, NULL,
        ['type' => 'error', 'announce' => 'object file path is not valid.']));

    }
    else {
      $response->addCommand(new InvokeCommand('#edit-object-file-path',
        'removeClass', ['error']));
      $response->addCommand(new InvokeCommand('#edit-object-file-path',
        'addClass', ['ok']));
      $response->addCommand(new MessageCommand('Relative object file path is valid for ' . $scheme . '://',
        NULL,
        ['type' => 'status', 'announce' => 'object file path is valid!']));

    }
    return $response;
  }

  /**
   * Utility function builds warning message string for file path validation
   * functions.
   *
   * @param $type
   * @param $path
   *
   * @return mixed
   */
  private function filePathErrorMessage($type, $path) {
    return t('Relative @type path "@path" is not valid. To avoid potential problems when moving to different filesystems, we apply the most restrictive file system path rules:
            <ul>
              <li>Folder names must be a minimum of three characters, and may contain only lower case letters, numbers and internal hyphens.</li>
              <li>Folder names are separated by forward slashes.</li>
              <li>Total path length limit is 63 characters.</li>
            </ul>',
      ['@type' => $type, '@path' => $path]
    );
  }


  /**
   * Validate exiftool Exec Path
   *
   * @param  array  $form
   * @param  \Drupal\Core\Form\FormStateInterface  $form_state
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function validateExif(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $canrun = \Drupal::service('strawberryfield.utility')->verifyCommand($form_state->getValue('exif_exec_path'));
    if (!$canrun) {
      $response->addCommand(new InvokeCommand('#edit-exif-exec-path', 'addClass', ['error']));
      $response->addCommand(new InvokeCommand('#edit-exif-exec-path', 'removeClass', ['ok']));
      $response->addCommand(new MessageCommand('exiftool path is not valid.', NULL, ['type' => 'error', 'announce' => 'exiftool path is not valid.']));

    } else {
      $response->addCommand(new InvokeCommand('#edit-exif-exec-path', 'removeClass', ['error']));
      $response->addCommand(new InvokeCommand('#edit-exif-exec-path', 'addClass', ['ok']));
      $response->addCommand(new MessageCommand('exiftool path is valid!', NULL, ['type' => 'status', 'announce' => 'exiftool path is valid!']));

    }
    return $response;
  }

  /**
   * Validate fido Exec Path
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function validateFido(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $canrun = \Drupal::service('strawberryfield.utility')->verifyCommand($form_state->getValue('fido_exec_path'));
    if (!$canrun) {
      $response->addCommand(new InvokeCommand('#edit-fido-exec-path', 'addClass', ['error']));
      $response->addCommand(new InvokeCommand('#edit-fido-exec-path', 'removeClass', ['ok']));
      $response->addCommand(new MessageCommand('fido path is not valid.', NULL, ['type' => 'error', 'announce' => 'fido path is not valid.']));

    } else {
      $response->addCommand(new InvokeCommand('#edit-fido-exec-path', 'removeClass', ['error']));
      $response->addCommand(new InvokeCommand('#edit-fido-exec-path', 'addClass', ['ok']));
      $response->addCommand(new MessageCommand('fido path is valid!', NULL, ['type' => 'status', 'announce' => 'fido path is valid!']));

    }
    return $response;
  }

  /**
   * Validate Identify Exec Path
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function validateIdentify(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $canrun = \Drupal::service('strawberryfield.utility')->verifyCommand($form_state->getValue('identify_exec_path'));
    if (!$canrun) {
      $response->addCommand(new InvokeCommand('#edit-identify-exec-path', 'addClass', ['error']));
      $response->addCommand(new InvokeCommand('#edit-identify-exec-path', 'removeClass', ['ok']));
      $response->addCommand(new MessageCommand('Identify path is not valid.', NULL, ['type' => 'error', 'announce' => 'Identify path is not valid.']));

    } else {
      $response->addCommand(new InvokeCommand('#edit-identify-exec-path', 'removeClass', ['error']));
      $response->addCommand(new InvokeCommand('#edit-identify-exec-path', 'addClass', ['ok']));
      $response->addCommand(new MessageCommand('Identify path is valid!', NULL, ['type' => 'status', 'announce' => 'Identify path is valid!']));

    }
    return $response;
  }

  /**
   * Validate Identify Exec Path
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function validatePdfinfo(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $canrun = \Drupal::service('strawberryfield.utility')->verifyCommand($form_state->getValue('pdfinfo_exec_path'));
    if (!$canrun) {
      $response->addCommand(new InvokeCommand('#edit-pdfinfo-exec-path', 'addClass', ['error']));
      $response->addCommand(new InvokeCommand('#edit-pdfinfo-exec-path', 'removeClass', ['ok']));
      $response->addCommand(new MessageCommand('PDFInfo path is not valid.', NULL, ['type' => 'error', 'announce' => 'PDFInfo path is not valid.']));

    } else {
      $response->addCommand(new InvokeCommand('#edit-pdfinfo-exec-path', 'removeClass', ['error']));
      $response->addCommand(new InvokeCommand('#edit-pdfinfo-exec-path', 'addClass', ['ok']));
      $response->addCommand(new MessageCommand('PDFInfo path is valid!', NULL, ['type' => 'status', 'announce' => 'PDFInfo path is valid!']));

    }
    return $response;
  }
  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    if ((bool) $form_state->getValue('extractmetadata')) {
      // Don't validate if not enabled.
      $canrun_exif = \Drupal::service('strawberryfield.utility')->verifyCommand(
        $form_state->getValue('exif_exec_path')
      );
      if (!$canrun_exif) {
        $form_state->setErrorByName(
          'exif_exec_path',
          $this->t('Please correct. exiftool path is not valid.')
        );
      }
      $canrun_fido = \Drupal::service('strawberryfield.utility')->verifyCommand(
        $form_state->getValue('fido_exec_path')
      );
      if (!$canrun_fido) {
        $form_state->setErrorByName(
          'fido_exec_path',
          $this->t('Please correct. fido path is not valid.')
        );
      }
      $canrun_identify = \Drupal::service('strawberryfield.utility')->verifyCommand(
        $form_state->getValue('identify_exec_path')
      );
      if (!$canrun_identify) {
        $form_state->setErrorByName(
          'identify_exec_path',
          $this->t('Please correct. Identify path is not valid.')
        );
      }
      $canrun_pdfinfo = \Drupal::service('strawberryfield.utility')->verifyCommand(
        $form_state->getValue('pdfinfo_exec_path')
      );
      if (!$canrun_pdfinfo) {
        $form_state->setErrorByName(
          'pdfinfo_exec_path',
          $this->t('Please correct. PDFInfo path is not valid.')
        );
      }
    }

    foreach(['file' => t('Relative file path'), 'object_file' => t('Relative object file path')] as $file_path_field => $file_path_field_label) {
      $path = $form_state->getValue($file_path_field . "_path");
      $scheme = $form_state->getValue($file_path_field . "_scheme");
      if (empty($path)) {
        $valid = TRUE;
      }
      else {
        // Validate for known schemes.
        $known_schemes = array_merge(['s3'], array_keys(\Drupal::service('stream_wrapper_manager')->getWrappers(StreamWrapperInterface::LOCAL)));
        if(in_array($scheme, $known_schemes)) {
          $valid = \Drupal::service('strawberryfield.utility')->filePathIsValid($scheme, $path);
        }
        else {
          // Can't flag as invalid if we don't know the scheme.
          $valid = TRUE;
        }
      }
      if(!$valid) {
        $form_state->setErrorByName(
          $file_path_field . "_path",
          $this->t('Please correct. @label is not valid.', ['@label' => $file_path_field_label] )
        );
      }
    }

    parent::validateForm(
      $form,
      $form_state
    ); // TODO: Change the autogenerated stub

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('strawberryfield.filepersister_service_settings')
      ->set('extractmetadata', (bool) $form_state->getValue('extractmetadata'))
      ->set('exif_exec_path', trim($form_state->getValue('exif_exec_path')))
      ->set('fido_exec_path', trim($form_state->getValue('fido_exec_path')))
      ->set('identify_exec_path', trim($form_state->getValue('identify_exec_path')))
      ->set('pdfinfo_exec_path', trim($form_state->getValue('pdfinfo_exec_path')))
      ->save();
    $this->config('strawberryfield.storage_settings')
      ->set('file_scheme', $form_state->getValue('file_scheme'))
      ->set('file_path', trim($form_state->getValue('file_path')," \n\r\t\v\0/"))
      ->set('object_file_scheme', $form_state->getValue('object_file_scheme'))
      ->set('object_file_path', trim($form_state->getValue('object_file_path')," \n\r\t\v\0/"))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
