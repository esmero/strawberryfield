<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 6/26/19
 * Time: 6:56 PM
 */

namespace Drupal\strawberryfield;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Archiver\ArchiverManager;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Messenger\MessengerTrait;
use \Drupal\strawberryfield\Field\StrawberryFieldItemList;

/**
 * Provides a SBF File persisting class.
 */
class StrawberryfieldFilePersisterService {

  use StringTranslationTrait;
  use MessengerTrait;

  /**
   * File system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The 'file.usage' service.
   *
   * @var \Drupal\file\FileUsage\FileUsageInterface
   */
  protected $fileUsage;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * The archiver manager.
   *
   * @var \Drupal\Core\Archiver\ArchiverManager
   */
  protected $archiverManager;

  /**
   * The Storage Destination Scheme.
   *
   * @var string;
   */
  protected $destinationScheme = NULL;

  /**
   * The Module Handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface;
   */
  protected $moduleHandler;


  /**
   * The language Manager
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The Transliteration
   *
   * @var \Drupal\Component\Transliteration\TransliterationInterface
   */
  protected $transliteration;

  /**
   * StrawberryfieldFilePersisterService constructor.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   * @param \Drupal\file\FileUsage\FileUsageInterface $file_usage
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   * @param \Drupal\Core\Archiver\ArchiverManager $archiver_manager
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   */
  public function __construct(
    FileSystemInterface $file_system,
    FileUsageInterface $file_usage,
    EntityTypeManagerInterface $entity_type_manager,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    ArchiverManager $archiver_manager,
    ConfigFactoryInterface $config_factory,
    AccountInterface $current_user,
    LanguageManagerInterface $language_manager,
    TransliterationInterface $transliteration,
    ModuleHandlerInterface $module_handler
  ) {
    $this->fileSystem = $file_system;
    $this->fileUsage = $file_usage;
    $this->entityTypeManager = $entity_type_manager;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->archiverManager = $archiver_manager;
    //@TODO evaluate creating a ServiceFactory instead of reading this on construct.
    $this->destinationScheme = $config_factory->get(
      'strawberryfield.storage_settings'
    )->get('file_scheme');
    $this->languageManager = $language_manager;
    $this->transliteration = $transliteration;
    $this->moduleHandler = $module_handler;
  }


  /**
   * Prepares the final persistence URI for a file
   *
   * @param \Drupal\file\FileInterface $file
   * @param string $relativefolder
   *
   * @return string
   */
  public function getDestinationUri(
    FileInterface $file,
    string $relativefolder
  ) {

    // Default $relativefolder is a 3 char hash generated by a checksum algorith.
    $current_uri = $file->getFileUri();
    $file_parts['destination_folder'] = $relativefolder;
    $file_parts['destination_filename'] = pathinfo(
      $current_uri,
      PATHINFO_FILENAME
    );
    $file_parts['destination_extension'] = pathinfo(
      $current_uri,
      PATHINFO_EXTENSION
    );
    $file_parts['destination_scheme'] = $this->fileSystem->uriScheme(
      $file->getFileUri()
    );
    list($file_parts['destination_filetype'],) = explode(
      '/',
      $file->getMimeType()
    );

    // Allow other modules to alter the parts used to create final persistent destination.
    // @TODO add the .api file and an example for this.
    $this->moduleHandler->alter(
      'strawberryfield_file_destination',
      $file_parts,
      $file
    );

    $destination_extension = mb_strtolower(
      $file_parts['destination_extension']
    );
    //https://api.drupal.org/api/drupal/core%21includes%21file.inc/function/file_uri_scheme/8.7.x
    // If no destination scheme was setup on our global config use the original file scheme.
    $desired_scheme = !empty($this->destinationScheme) ? $this->destinationScheme : $file_parts['destination_scheme'];

    $destination_filename = $file_parts['destination_filetype'] . '-' . $file_parts['destination_filename'] . '.' . $file_parts['destination_extension'];

    // Sanitize filename.
    // @see http://stackoverflow.com/questions/2021624/string-sanitizer-for-filename
    // Do this always, why would i not want this?

    $destination_basename = substr(
      pathinfo($destination_filename, PATHINFO_BASENAME),
      0,
      -strlen(".{$file_parts['destination_extension']}")
    );
    $destination_basename = mb_strtolower($destination_basename);
    $destination_basename = $this->transliteration->transliterate(
      $destination_basename,
      $this->languageManager->getCurrentLanguage()->getId(),
      '-'
    );
    $destination_basename = preg_replace(
      '([^\w\s\d\-_~,;:\[\]\(\].]|[\.]{2,})',
      '',
      $destination_basename
    );
    $destination_basename = preg_replace('/\s+/', '-', $destination_basename);
    $destination_basename = trim($destination_basename, '-');
    // If the basename if empty use the element's key.
    if (empty($destination_basename)) {
      $destination_basename = $file_parts['destination_filetype'] . '-' . $file->uuid(
        );
    }
    $destination_filename = $destination_basename . '.' . $destination_extension;
    return $desired_scheme . '://' . $file_parts['destination_folder'] . '/' . $destination_filename;
  }


  /**
   *  Generates the full AS metadata structure to keep track of SBF files.
   *
   * @param array $file_id_list
   * @param $file_source_key
   * @param array $cleanjson
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function generateAsFileStructure(
    array $file_id_list = [],
    $file_source_key,
    array $cleanjson = []
  ) {

    /* @see https://www.drupal.org/project/drupal/issues/2577417 for a
     * a future solution for many many files
     */

    // @TODO This function is processing heavy
    // In a worst scenario we iterate over every existing file 3 times
    // Given the fact that a book could have 2000 pages,
    // Processing of 6000 iterations when saving is should be neglectable, IMHO.
    // And way less than dealing with same as parent/child entities.

    //@TODO chunk $file_id_list in smaller groups, then run this batch.
    //@TODO batch requires a redirect page. Should be a webform step?

    /** @var \Drupal\file\FileInterface[] $files */
    try {
      $files = $this->entityTypeManager->getStorage('file')->loadMultiple(
        $file_id_list
      );
    } catch (InvalidPluginDefinitionException $e) {
      $this->messenger()->addError(
        $this->t('Sorry, we had real issues loading your files.')
      );
      return;
    } catch (PluginNotFoundException $e) {
      $this->messenger()->addError(
        $this->t('Sorry, we had real issues loading your files.')
      );
      return;
    }
    // Will contain all as:something and its members based on referenced file ids
    $fileinfo_bytype_many = [];
    // Will contain temporary clasification
    $files_bytype_many = [];

    // @TODO if count($files) is different than $file_id_list means we lost
    // a file from storage. Could have been temporary and it was never accounted
    // Notify the user of that. Not a good thing
    // Give the user the change to restore the file from some other place.

    // Iterate and clasify by as: type
    foreach ($files as $file) {
      // Make sure mime is up to date!
      // Real use case since the file DB gets never reprocessed once saved.
      // And we could have update/upgraded our mappings.
      $uri = $file->getFileUri();
      $mimetype = \Drupal::service('file.mime_type.guesser')->guess($uri);
      if (($file->getMimeType(
          ) != $mimetype) && ($mimetype != application / octet - stream)) {
        $file->setMimeType($mimetype);
        $file->save();
        //@TODO notify the user of the updated mime type.
      }

      // Calculate the destination json key
      $as_file_type = explode('/', $file->getMimeType());
      $as_file_type = count($as_file_type) == 2 ? $as_file_type[0] : 'document';
      $as_file_type = ($as_file_type != 'application') ? $as_file_type : 'document';

      $files_bytype_many[$as_file_type][$file->id()] = $file;
    }
    // Second iteration, find if we already have a structure in place for them
    // Only to avoid calculating checksum again, if not generate
    $to_process = [];
    foreach ($files_bytype_many as $askey => $files) {
      $fileinfo_bytype_many['as:' . $askey] = [];
      if (isset($cleanjson['as:' . $askey])) {
        // Gets us structures in place with checksum applied
        $fileinfo_bytype_many['as:' . $askey] = $this->retrieve_filestructure_from_metadata(
          $cleanjson['as:' . $askey],
          array_keys($files),
          $file_source_key
        );
        // Now we need to know which ones still require processing
      }
      // We do this outside the isset to make sure we generate structures
      // Even when this is happening for the first time

      $to_process[$askey] = array_diff_key(
        $files_bytype_many[$askey],
        $fileinfo_bytype_many['as:' . $askey]
      );
    }
    // Final iteration
    // Only do this if file was not previously processed and stored.
    foreach ($to_process as $askey => $files) {
      foreach ($files as $file) {
        $uri = $file->getFileUri();
        // This can get heavy.
        // @TODO make md5 a queue worker task.
        // @TODO build two queues. Top one that calls all subqueues and then
        // @TODO Fills up the md5 for all files and updates a single node at a time
        // @TODO evaluate Node locking while this happens.
        $md5 = md5_file($uri);
        $relativefolder = substr($md5, 0, 3);
        $uuid = $file->uuid();
        // Desired destination.
        $destinationuri = $this->getDestinationUri($file, $relativefolder);
        $fileinfo = [
          'type' => ucfirst($askey),
          'url' => $destinationuri,
          'crypHashFunc' => 'md5',
          'checksum' => $md5,
          'dr:for' => $file_source_key,
          'dr:fid' => (int) $file->id(),
          'dr:uuid' => $uuid,
          'name' => $file->getFilename(),
          'tags' => [],
        ];

        //The node save hook will deal with moving data.
        // We don't need the key here but makes cleaning easier
        $fileinfo_bytype_many['as:' . $askey]['urn:uuid:' . $uuid] = $fileinfo;
        // Side effect of this is that if the same file id is referenced twice
        // by different fields, as:something will contain it once only.
        // Not bad, just saying.
        // @TODO see if having the same file in different keys is even
        // a good idea.
      }
    }

    return $fileinfo_bytype_many;
  }

  /**
   * Gets AS structures for a given file id from existing metadata.
   *
   * @param array $cleanjsonbytype
   * @param $file_id_list
   * @param string $file_source_key
   *
   * @return array
   */
  protected function retrieve_filestructure_from_metadata(
    $cleanjsonbytype = [],
    $file_id_list,
    string $file_source_key
  ) {
    $found = [];
    foreach ($cleanjsonbytype as $info) {
      if ((isset($info['dr:fid']) && in_array(
            $info['dr:fid'],
            $file_id_list
          )) && isset($info['checksum']) && (isset($info['dr:for']) && $info['dr:for'] == $file_source_key)) {
        // If present means it was persisted so 'url' and $file->getFileUri() will be the same.
        // We return keyed by fileid to allow easier array_diff on the calling function
        $found[$info['dr:fid']] = $info;
      }
    }
    return $found;
  }

  /**
   * Deals with moving and saving files referenced in a SBF JSON content.
   *
   * @param \Drupal\strawberryfield\Field\StrawberryFieldItemList $field
   *
   * @return int
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function persistFilesInJsonToDisks(StrawberryFieldItemList $field) {
    $persisted = 0;
    if (!$field->isEmpty()) {
      $entity = $field->getEntity();
      /** @var $field \Drupal\Core\Field\FieldItemList */
      foreach ($field->getIterator() as $delta => $itemfield) {
        // Note: we are not longer touching the metadata here.
        /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */
        $flatvalues = (array) $itemfield->provideFlatten();
        if (isset($flatvalues['dr:fid'])) {
          foreach ($flatvalues['dr:fid'] as $fid) {
            if (is_numeric($fid)) {
              $file = $this->entityTypeManager->getStorage('file')->load($fid);
              /** @var $file \Drupal\file\FileInterface|NULL */
              if ($file && $file->isTemporary()) {
                // This is tricky. We will allow non temporary to be moved if
                // The only usage is the current node!
                $uuid = $file->uuid();
                $current_uri = $file->getFileUri();
                // Get the info structure from flatten:
                if (isset($flatvalues['urn:uuid:' . $uuid]) &&
                  isset($flatvalues['urn:uuid:' . $uuid]['dr:fid']) &&
                  ($flatvalues['urn:uuid:' . $uuid]['dr:fid'] = $fid) &&
                    isset($flatvalues['urn:uuid:' . $uuid]['url']) &&
                    !empty($flatvalues['urn:uuid:' . $uuid]['url'])) {
                  // Weird egde case:
                  // What if same urn:uuid:uuid has multiple info structures?
                  // Flattener could end being double nested?
                  $destination_uri = $flatvalues['urn:uuid:' . $uuid]['url'];
                  // Only deal with expensive process if destination uri is
                  // different to the known one.
                  if ($destination_uri != $current_uri) {
                    $destination_folder = $this->fileSystem
                      ->dirname($destination_uri);
                    file_prepare_directory(
                      $destination_folder,
                      FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS
                    );
                    // Copy to new destination
                    $destination_uri = file_unmanaged_copy(
                      $current_uri,
                      $destination_uri
                    );
                    // Means moving was successful
                    if ($destination_uri) {
                      $file->setFileUri($destination_uri);
                      $file->setPermanent();
                      if (isset($info['name']) && !empty($info['name'])) {
                        $file->setFilename($info['name']);
                      }
                      try {
                        $file->save();
                        $persisted++;
                      } catch (\Drupal\Core\Entity\EntityStorageException $e) {
                        $this->messenger()->addError(
                          t(
                            'Something went wrong when saving file @filename:, please check your logs.',
                            ['@filename' => $file->getFilename()]
                          )
                        );
                      }
                    }
                    // Count usage even if we had issues moving.
                    // This gives us at least the chance to try again.
                    if (!$entity->isNew()) {
                      // We can not update its usage if the entity is new here
                      // Because we have no entity id yet
                      _update_file_usage($file, $entity->id());
                    }
                  }
                }
                else {
                  $this->messenger()->addError(
                    t(
                      'Your content references a file at @fileurl with Internal ID @file_id that we could not find a full metadata definition for, maybe we forgot to process it?',
                      ['@fileurl' => $current_uri, '@file_id' => $fid]
                    )
                  );
                }
              }
            }
          }
        }
      }
    }
    // Number of files we could store.
    return $persisted;
  }

}
