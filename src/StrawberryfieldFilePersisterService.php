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
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\strawberryfield\Field\StrawberryFieldItemList;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\strawberryfield\Event\StrawberryfieldJsonProcessEvent;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\strawberryfield\StrawberryfieldEventType;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;


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
   * The SBF configuration settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The logger factory.
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;


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
    ModuleHandlerInterface $module_handler,
    LoggerChannelFactoryInterface $logger_factory
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
    $this->config = $config_factory->get('strawberryfield.settings');
    $this->languageManager = $language_manager;
    $this->transliteration = $transliteration;
    $this->moduleHandler = $module_handler;
    $this->loggerFactory = $logger_factory;
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
    $uuid = $file->uuid();

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

    // First part of Mime type becomes prefix. Performant for filtering in S3.
    $destination_basename = $file_parts['destination_filetype'] . '-' . $file_parts['destination_filename'];

    // Sanitize the whole thing.
    $destination_basename = $this->sanitizeFileName($destination_basename);

    // Edge case, should only happen if all goes wrong.
    // RFC 2046: Since unknown mime-types always default to
    // application/octet-stream  and we use first part of the string
    // we default to 'application' here.
    if (empty($destination_basename)) {
      $destination_basename =  'application-unnamed';
    } else {
      // Object name limit for AWS S3 is 512 chars. Minio does not impose any.
      // UUID adds 36 characters,  plus 1 for the dash + 4 for extension.
      // So we shamelessly cut at 471. Someone needs to act!
      $destination_basename = substr($destination_basename, 0, 471);
    }

    // WE add the unique UUID at the end. That gives us best protection against
    // name collisions but still keeping human semantically aware file naming.

    $destination_filename = $destination_basename . '-' . $uuid . '.' . $destination_extension;
    return $desired_scheme . '://' . $file_parts['destination_folder'] . '/' . $destination_filename;
  }

  /**
   * Sanitizes a File name removing/mapping non valid characters.
   *
   * @param string $basename
   *  A file name without extension.
   *
   * @return string
   *  A sanitized string that can be used as a filename.
   */
  public function sanitizeFileName(string $basename) {
    // Lower case
    $basename = mb_strtolower($basename);
    // Deals with UTF-8 to ASCII translation, and replaces unknowns with a -
    $basename = $this->transliteration->transliterate(
      $basename,
      $this->languageManager->getCurrentLanguage()->getId(),
      '-'
    );
    // Removes dangerous characters
    $basename = preg_replace(array('~[^0-9a-z]~i', '~[-]+~'), '-', $basename);
    $basename = preg_replace('/\s+/', '-', $basename);
    return trim($basename, ' -');
  }

  /**
   *  Generates the full AS metadata structure to keep track of SBF files.
   *
   * @param array $file_id_list
   * @param $file_source_key
   * @param array $cleanjson
   *
   * @return array
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
    // Processing of 6000 iterations when saving is/should be neglectable, IMHO.
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
        $this->t('Sorry, we had real issues loading your files. Invalid Plugin File Definition.')
      );
      return [];
    } catch (PluginNotFoundException $e) {
      $this->messenger()->addError(
        $this->t('Sorry, we had real issues loading your files. File Plugin not Found')
      );
      return [];
    }
    // Will contain all as:something and its members based on referenced file ids
    $fileinfo_bytype_many = [];
    // Will contain temporary classification
    $files_bytype_many = [];
    // Simpler structure with classification and file id
    $file_list = [];

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
          ) != $mimetype) && ($mimetype != 'application/octet-stream')) {
        $file->setMimeType($mimetype);
        $file->save();
        //@TODO notify the user of the updated mime type.
      }

      // Calculate the destination json key
      $as_file_type = explode('/', $file->getMimeType());
      $as_file_type = count($as_file_type) == 2 ? $as_file_type[0] : 'document';
      $as_file_type = ($as_file_type != 'application') ? $as_file_type : 'document';

      $files_bytype_many[$as_file_type]['urn:uuid:' . $file->uuid()] = $file;
      // Simpler structure to iterate over
      $file_list[$as_file_type][] = $file->id();
    }
    // Second iteration, find if we already have a structure in place for them
    // Only to avoid calculating checksum again, if not generate.


    $to_process = [];
    foreach ($file_list as $askey => $fileids) {
      $fileinfo_bytype_many['as:' . $askey] = [];
      if (isset($cleanjson['as:' . $askey])) {
        // Gets us structures in place with checksum applied
        $fileinfo_bytype_many['as:' . $askey] = $this->retrieve_filestructure_from_metadata(
          $cleanjson['as:' . $askey],
          array_values($fileids),
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
        $filemetadata = $this->getBaseFileMetadata($file);
        $relativefolder = substr($md5, 0, 3);
        $uuid = $file->uuid();
        // again, i know!
        $mime = $file->getMimeType();
        // Desired destination.
        $destinationuri = $this->getDestinationUri($file, $relativefolder);
        // Add exception here for PDFs. We need the number of pages
        // @TODO add a mime type based hook/plugin or event. Idea is to allow modules
        // to intercept this


        $fileinfo = [
          'type' => ucfirst($askey),
          'url' => $destinationuri,
          'crypHashFunc' => 'md5',
          'checksum' => $md5,
          'dr:for' => $file_source_key,
          'dr:fid' => (int) $file->id(),
          'dr:uuid' => $uuid,
          'dr:mimetype' => $mime,
          'name' => $file->getFilename(),
          'tags' => [],
        ];

        // Add Metadata from exif/fido
        $fileinfo = array_merge($fileinfo, $filemetadata);
        // Dispatch event with just the $fileinfo for a single file as JSON
        // This is used allow other functions to do things based on the JSON.
        // IN this case we want 'someone' to count txhe number of pages e.g
        // If the file is a PDF.
        // @TODO inject event dispatcher and move this to its own method.
        $event_type = StrawberryfieldEventType::JSONPROCESS;
        $event = new StrawberryfieldJsonProcessEvent($event_type, $cleanjson, $fileinfo);
        /** @var \Symfony\Component\EventDispatcher\EventDispatcher $dispatcher */
        $dispatcher = \Drupal::service('event_dispatcher');
        $dispatcher->dispatch($event_type, $event);
        if ($event->wasModified()) {
          // Note: When the event gets an object passed,
          // We don't need to retrieve the changes. But our JSON is an array.
          // So we do relay on reassigning it.
          $fileinfo = $event->getProcessedJson();
        }

        //The node save hook will deal with moving data.
        // We don't need the key here but makes cleaning easier
        $fileinfo_bytype_many['as:' . $askey]['urn:uuid:' . $uuid] = $fileinfo;
        // Side effect of this is that if the same file id is referenced twice
        // by different fields, as:something will contain it once only.
        // Not bad, just saying.
        // @TODO see if having the same file in different keys is even
        // a good idea.
      }
      // Natural Order Sort.
      // @TODO how should we deal with manually ordered files?
      // This will always reorder everything based on filenames.
      uasort($fileinfo_bytype_many['as:' . $askey], array($this,'sortByFileName'));
      // For each always wins over array_walk
      $i=0;
      foreach ($fileinfo_bytype_many['as:' . $askey] as &$item) {
        $i++;
       //Order is already given by uasort but not trustable in JSON
       //So we set sequence number
        $item['sequence'] = $i;
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
        $found['urn:uuid:' . $info['dr:uuid']] = $info;
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
      $entity_type_id = $entity->getEntityTypeId();
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
                    $this->fileSystem->prepareDirectory(
                      $destination_folder,
                      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
                    );
                    // Copy to new destination
                    $destination_uri = $this->fileSystem->copy(
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
                      $this->add_file_usage($file, $entity->id(), $entity_type_id);
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

  /**
   * Deals with tracking file usage inside a strawberryfield.
   *
   * @param \Drupal\strawberryfield\Field\StrawberryFieldItemList $field
   *
   * @return int
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function updateUsageFilesInJson(StrawberryFieldItemList $field) {
    $updated = 0;
    if (!$field->isEmpty()) {
      $entity = $field->getEntity();
      $entity_type_id = $entity->getEntityTypeId();
      /** @var $field \Drupal\Core\Field\FieldItemList */
      foreach ($field->getIterator() as $delta => $itemfield) {
        // Note: we are not touching the metadata here.
        /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */
        $flatvalues = (array) $itemfield->provideFlatten();
        if (isset($flatvalues['dr:fid'])) {
          foreach ($flatvalues['dr:fid'] as $fid) {
            if (is_numeric($fid)) {
              $file = $this->entityTypeManager->getStorage('file')->load($fid);
              /** @var $file FileInterface; */
              if ($file) {
                $this->add_file_usage($file, $entity->id(), $entity_type_id);
                $updated++;
              } else {
                $this->messenger()->addError(
                  t(
                    'Your content references a file with Internal ID @file_id that does not exist or was removed.',
                    ['@file_id' => $fid]
                  )
                );
              }
            }
          }
        }
      }
    }
    // Number of files we could update its usage record.
    return $updated;
  }


  /**
   * Deals with removing file usage for a node bearing SBFs.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *
   * @return array|void
   *  Two values, number of files removed, number of orphaned cleaned.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function removeUsageFilesInJson(ContentEntityInterface $entity) {
    $updated = 0;
    $orphaned = 0;
    $entity_id = $entity->id();
    $entity_type_id = $entity->getEntityTypeId();
    // Funny, D8/9 has no method for getting all files marked as used by
    // a given entity id

    if (!$this->moduleHandler->moduleExists('file')) {
      return [$updated,$orphaned];
    }
    //@TODO check if we should allow other than nodes to bear SBFs?
    $file_id_list = \Drupal::database()->select('file_usage', 'fu')
        ->fields('fu', ['fid'])
        ->condition('module', 'strawberryfield')
        ->condition('type', $entity_type_id)
        ->condition('id', $entity_id)
        ->execute()
        ->fetchCol();

    if (empty($file_id_list)) {
      return [$updated,$orphaned];
    }

    /** @var \Drupal\file\FileInterface[] $files */
    $files = $this->entityTypeManager->getStorage('file')->loadMultiple(
      $file_id_list
    );

    foreach ($files as $file) {
      $this->remove_file_usage($file, $entity_id, $entity_type_id, 0);

      // This is a humble attempt to garbage clean up file usage
      // because Drupal neglects this issues sometimes.
      //@TODO moved to its own method and make it smarter
      $usage = $this->fileUsage->listUsage($file);
      if (!empty($usage)) {
      if (isset($usage['strawberryfield']) && isset($usage['strawberryfield'][$entity_type_id])) {
        foreach ($usage['strawberryfield'][$entity_type_id] as $id => $count) {

          $values = \Drupal::entityQuery($entity_type_id)->condition(
            'nid',
            $id
          )->execute();
        }
        if (empty($values)) {
          $this->remove_file_usage($file, $id, $entity_type_id, 0);
          $orphaned++;
        }
      }
      // Now check if there is still usage around.
      $usage = $this->fileUsage->listUsage($file);
      if (empty($usage)) {
        //@TODO D 8.4+ does not mark unusued files as temporary.
        // For repo needs we want everything SBF managed to be cleaned up.
        $file->setTemporary();
        $file->save();
      }
    }
    else {
        //@TODO D 8.4+ does not mark unusued files as temporary.
        // For repo needs we want everything SBF managed to be cleaned up.
        $file->setTemporary();
        $file->save();
      }
    }
    $updated = count($files);
    // Number of files we could update its usage record.
    return [$updated,$orphaned];
  }
  /**
   * Adds File usage to DB for SBF managed files.
   *
   * @param \Drupal\file\FileInterface $file
   * @param int $nodeid
   */
  protected function add_file_usage(FileInterface $file, int $nodeid, string $entity_type_id = 'node') {
    if (!$file || !$this->moduleHandler->moduleExists('file')) {
      return;
    }
    /** @var \Drupal\file\FileUsage\FileUsageInterface $file_usage */

    if ($file) {
      $this->fileUsage->add($file, 'strawberryfield', $entity_type_id, $nodeid);
    }
  }

  /**
   * Deletes File usage from DB for SBF managed files.
   *
   * @param \Drupal\file\FileInterface $file
   * @param int $nodeid
   * @param int $count
   *  If count is 0 it will remove all references.
   */
  protected function remove_file_usage(
    FileInterface $file,
    int $nodeid,
    string $entity_type_id = 'node',
    $count = 1
  ) {
    if (!$file || !$this->moduleHandler->moduleExists('file')) {
      return;
    }
    /** @var \Drupal\file\FileUsage\FileUsageInterface $file_usage */

    if ($file) {
      $this->fileUsage->delete(
        $file,
        'strawberryfield',
        $entity_type_id,
        $nodeid,
        $count
      );
    }
  }

  /**
   * Deposits String encoded Metadata to file given a URI
   *
   * @param string $data
   *   Data to be written out as a string.
   * @param string $path
   *   Destination path with/or without streamwrapper and no trailing slash.
   * @param string $filename
   *   Destination filename
   * @param bool $compress
   *    If and additional gzip is required
   * @param bool $onlycompressed
   *    If gzipped file will be the only one.
   *    Depends on $compress option. FALSE Ignored if compress is FALSE.
   * @return bool
   *    TRUE if all requested operations could be executed, FALSE if not.
   */
  public function persistMetadataToDisk(
    string $data,
    string $path,
    string $filename,
    $compress = FALSE,
    $onlycompressed = TRUE
  ) {
    $success = FALSE;
    $compress = (extension_loaded('zlib') && $compress);
    $onlycompressed = ($compress && $onlycompressed);
    if (!empty($data) && !empty($path) && !empty($filename)) {
      $success = TRUE;
      $uri = $path . '/' . $filename;

      if (!$this->fileSystem->prepareDirectory(
        $path,
        FileSystemInterface::CREATE_DIRECTORY
      )) {
        $success = FALSE;
        return $success;
      }

      if (!$onlycompressed) {
        if (!$this->fileSystem->saveData(
          $data,
          $uri,
          FileSystemInterface::EXISTS_REPLACE
        )) {
          $success = FALSE;
          // We have to return early if this failed.
          return $success;
        }
      }
      if ($compress) {
        if (!$this->fileSystem->saveData(
          gzencode($data, 9, FORCE_GZIP),
          $uri . '.gz',
          FileSystemInterface::EXISTS_REPLACE
        )) {
          $success = FALSE;
        }
      }
    }
    return $success;
  }

  /**
   * Natural Sort function to be used as uasort callback
   *
   * Only works for as: structures like
   *  $fileinfo = [
   *  'type' => ucfirst($askey),
   *  'url' => $destinationuri,
   *  'crypHashFunc' => 'md5',
   *  'checksum' => $md5,
   *  'dr:for' => $file_source_key,
   *  'dr:fid' => (int) $file->id(),
   *  'dr:uuid' => $uuid,
   *  'name' => $file->getFilename(),
   *  'tags' => [],
   * ];
   * @param $a
   * @param $b
   *
   * @return int
   */
  public function sortByFileName($a, $b) {
    return strnatcmp($a['name'],$b['name']);
  }


  /**
   * Gets basic metadata from a File to be put back into a SBF
   *
   * Also deals with the fact that it can be local v/s remote.
   *
   * @param \Drupal\file\FileInterface $file
   *
   * @return array
   *    Metadata extracted for the image in array format if any
   */
  public function getBaseFileMetadata(FileInterface $file) {

    // These are the 2 basic binaries we want eventually be able to run
    // For each referenced Files
    // With certain conditions of course
    // Like:
    // - How many files? Like 1 is cool, 2000 not cool
    // - Size? Like moving realtime 'Sync' 2TB back to TEMP to MD5 it not cool
    $metadata = [];

    $exif_exec_path = $this->config->get(
      'exif_exec_path'
    ) ?: '/usr/bin/exiftool';
    $fido_exec_path = $this->config->get('fido_exec_path') ?: '/usr/bin/fido';
    $file_size = $file->getSize();
    $uri = $file->getFileUri();

    /** @var \Drupal\Core\File\FileSystem $file_system */
    $scheme = $this->streamWrapperManager->getScheme($uri);
    $templocation = NULL;

    // If the file isn't stored locally make a temporary copy.
    if (!isset(
      $this->streamWrapperManager->getWrappers(
        StreamWrapperInterface::LOCAL
      )[$scheme]
    )) {
      // Local stream.
      $cache_key = md5($uri);
      $templocation = $this->fileSystem->copy(
        $uri,
        'temporary://sbr_' . $cache_key . '_' . basename($uri),
        FileSystemInterface::EXISTS_REPLACE
      );
      $templocation = \Drupal::service('file_system')->realpath(
        $templocation
      );
    }
    else {
      $templocation = \Drupal::service('file_system')->realpath(
        $file->getFileUri()
      );
    }

    if (!$templocation) {
      $this->loggerFactory->get('strawberryfield')->warning(
        'Could not adquire a local accesible location for metadata extraction for file with URL @fileurl',
        [
          '@fileurl' => $file->getFileUri(),
        ]
      );
      return $metadata;
    }


    if ($templocation) {
      // @TODO MOVE CHECKSUM here
      $output_exif = '';
      $output_fido = '';
      $result_exif = exec(
        $exif_exec_path . ' -json -q ' . escapeshellcmd($templocation),
        $output_exif,
        $status_exif
      );

      $result_fido = exec(
        $fido_exec_path . ' ' . escapeshellcmd($templocation),
        $output_fido,
        $status_fido
      );

      // First EXIF
      if ($status_exif != 0) {
        // Means exiftool did not work
        $this->loggerFactory->get('strawberryfield')->warning(
          'Could not process EXIF on @temlocation for @fileurl',
          [
            '@fileurl' => $file->getFileUri(),
            '@templocation' => $templocation,
          ]
        );
      }
      else {
        // JSON-ify EXIF data
        // remove RW Properties?
        $output_exif = implode('', $output_exif);
        $exif_full = json_decode($output_exif, TRUE);
        $json_error = json_last_error();
        if ($json_error == JSON_ERROR_NONE && isset($exif_full[0])) {
          $exif = $exif_full[0];
          unset($exif['FileName']);
          unset($exif['SourceFile']);
          unset($exif['Directory']);
          unset($exif['FilePermissions']);
          unset($exif['ThumbnailImage']);
          $metadata['flv:exif'] = $exif;
        }
      }
      // Second FIDO
      if ($status_fido != 0) {
        // Means Fido did not work
        $this->loggerFactory->get('strawberryfield')->warning(
          'Could not process FIDO on @temlocation for @fileurl',
          [
            '@fileurl' => $file->getFileUri(),
            '@templocation' => $templocation,
          ]
        );
      }
      else {
        // JSON-ify EXIF data
        // remove RW Properties?
        $output_fido = explode(',', str_replace('"', '', $result_fido));
        if (count($output_fido) && $output_fido[0] == 'OK') {
          // Means FIDO could do its JOB
          $pronom['pronom_id'] = isset($output_fido[2]) ? 'info:pronom/' . $output_fido[2] : NULL;
          $pronom['label'] = $output_fido[3] ?: NULL;
          $pronom['mimetype'] = $output_fido[7] ?: NULL;
          $pronom['detection_type'] = $output_fido[8] ?: NULL;
          $metadata['flv:pronom'] = $pronom;
        }
      }
    }
    return $metadata;
  }

}
