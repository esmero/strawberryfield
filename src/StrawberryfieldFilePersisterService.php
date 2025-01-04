<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 6/26/19
 * Time: 6:56 PM
 */

namespace Drupal\strawberryfield;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Archiver\ArchiverManager;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\strawberryfield\Event\StrawberryfieldFileEvent;
use Drupal\strawberryfield\Field\StrawberryFieldItemList;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\strawberryfield\Event\StrawberryfieldJsonProcessEvent;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Drupal\Core\Utility\Error;

/**
 * Provides a SBF File persisting class.
 */
class StrawberryfieldFilePersisterService {

  const FILE_IRI_PREFIX = 'urn:uuid:';

  const AS_TYPE_PREFIX = 'as:';

  /**
   * Default path for digital object file storage.
   */
  const DEFAULT_OBJECT_STORAGE_FILE_PATH = 'dostorage';

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
   * The SBF storage configuration settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $storageConfig;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The Strawberry Field Utility Service.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldUtilityService
   */
  protected $strawberryfieldUtility;

  /**
   * The Strawberry Field File Metadata Service.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldFileMetadataService
   */
  protected $strawberryfieldFileMetadataService;

  /**
   * If getBaseFileMetadata should be processed.
   *
   * @var bool
   */
  protected $extractFileMetadata = FALSE;

  /**
   *
   * The Full ConfigFactory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private $configFactory;

  /**
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * StrawberryfieldFilePersisterService constructor.
   *
   * @param \Drupal\Core\File\FileSystemInterface                       $file_system
   * @param \Drupal\file\FileUsage\FileUsageInterface                   $file_usage
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface              $entity_type_manager
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface    $stream_wrapper_manager
   * @param \Drupal\Core\Archiver\ArchiverManager                       $archiver_manager
   * @param \Drupal\Core\Config\ConfigFactoryInterface                  $config_factory
   * @param \Drupal\Core\Session\AccountInterface                       $current_user
   * @param \Drupal\Core\Language\LanguageManagerInterface              $language_manager
   * @param \Drupal\Component\Transliteration\TransliterationInterface  $transliteration
   * @param \Drupal\Core\Extension\ModuleHandlerInterface               $module_handler
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface           $logger_factory
   * @param StrawberryfieldUtilityService                               $strawberryfield_utility_service
   * @param \Drupal\strawberryfield\StrawberryfieldFileMetadataService  $strawberryfield_file_metadata_service
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
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
    LoggerChannelFactoryInterface $logger_factory,
    StrawberryfieldUtilityService $strawberryfield_utility_service,
    StrawberryfieldFileMetadataService $strawberryfield_file_metadata_service,
    EventDispatcherInterface $event_dispatcher
  ) {
    $this->fileSystem = $file_system;
    $this->fileUsage = $file_usage;
    $this->entityTypeManager = $entity_type_manager;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->archiverManager = $archiver_manager;
    $this->strawberryfieldFileMetadataService = $strawberryfield_file_metadata_service;
    //@TODO evaluate creating a ServiceFactory instead of reading this on construct.
    $this->config = $config_factory->get(
      'strawberryfield.filepersister_service_settings'
    );
    $this->storageConfig = $config_factory->get(
      'strawberryfield.storage_settings'
    );
    $this->configFactory = $config_factory;
    $this->destinationScheme = $this->storageConfig->get('file_scheme');
    $this->languageManager = $language_manager;
    $this->transliteration = $transliteration;
    $this->moduleHandler = $module_handler;
    $this->loggerFactory = $logger_factory;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->eventDispatcher = $event_dispatcher;
  }


  /**
   * Prepares the final persistence URI for a file
   *
   * This function allows implements a hook that can be used
   * By other modules to alter where things go based on another
   * pattern.
   *
   * @param \Drupal\file\FileInterface $file
   * @param string $checksum
   *    The checksum of the file
   * @param array $cleanjson
   *    The Original Clean SBF Metadata of the ADO that holds this File
   * @param bool $force
   *    Forcing will impose a new Path even if the current saved path is seen
   *    by this method as "Ok" to keep. This is based on the destination Schema,
   *    which means anything that is e.g in S3 will be kept its current place
   *    in S3 if set to false.
   *
   * @return string
   */
  public function getDestinationUri(
    FileInterface $file,
    string $checksum,
    array $cleanjson,
    bool $force = FALSE
  ) {

    // The building blocks, as info for the alter hook
    $file_parts = [];
    // The processed building blocks, what the altering agent will want to set
    $processed_file_parts = [];

    // Default $relativefolder is a 3 char hash generated by a checksum algorithm.
    // First, get any parent directories.
    $relativefolders = explode('/', $this->storageConfig->get('file_path') ?? "");
    $relativefolders[] = substr($checksum, 0, 3);
    $relativefolder = implode('/', array_filter($relativefolders));
    $current_uri = $file->getFileUri();
    $uuid = $file->uuid();
    // At this level we do not know the current
    // Node id that is in operation
    // Reason for that is we could be calling this
    // Way before the node exists
    // But we can know here if there is already a SBF involved
    // If so, then that means we should not move the file
    /// There are cases where a file can be permanent and not match our needs
    /// Our needs are: have all the files accessible/consistently under a given
    /// structure IIIF can find.
    // e.g Provided and stored in a webform submission entity or even
    // Uploaded via an endpoint, etc.

    if ($file->isPermanent() && !$force) {
      //First check, is it already in the destination scheme? If not we need to move it
      if ($this->streamWrapperManager->getScheme(
          $current_uri
        ) != $this->destinationScheme) {
        $force = TRUE;
      }
      else {
        /* file usage will be something like
        [
          "strawberryfield" => [
            "node" => [
               2046 => "1"
            ]
          ]
          "file" => [
            "node" => [
              2046 => "7"
          ]
      ]
      */
        $usage_list = $this->fileUsage->listUsage($file);
        // Condition to make it temporary again and also take ownership is
        //  Not used by any SBF Node. And we can check our own module here to be quick
        if (count($usage_list)) {
          // Means someone has taken ownership. E.g AMI module.
          $force = TRUE;
        }
        foreach ($usage_list as $module => $use) {
          if ($module == 'strawberryfield') {
            $force = FALSE;
            break;
          }
        }
      }
    }
    // Start building the file parts.
    $destination_folder = $processed_file_parts['destination_folder'] = $relativefolder;
    $file_parts['destination_filename'] = pathinfo(
      $current_uri,
      PATHINFO_FILENAME
    );

    $file_parts['destination_extension'] = pathinfo(
      $current_uri,
      PATHINFO_EXTENSION
    );
    // Check if the file may have a secondary extension

    $file_parts['destination_extension_secondary'] = pathinfo(
      $file_parts['destination_filename'],
      PATHINFO_EXTENSION
    );
    // Deal with 2 part extension problem.
    if (!empty($file_parts['destination_extension_secondary']) &&
      strlen($file_parts['destination_extension_secondary']) <= 4 &&
      strlen($file_parts['destination_extension_secondary']) > 0
    ) {
      $file_parts['destination_extension'] = $file_parts['destination_extension_secondary'] . '.' . $file_parts['destination_extension'];
    }

    $file_parts['destination_scheme'] = $this->streamWrapperManager
      ->getScheme($current_uri);

    [$file_parts['destination_filetype'],] = explode(
      '/',
      $file->getMimeType()
    );

    //https://api.drupal.org/api/drupal/core%21includes%21file.inc/function/file_uri_scheme/8.7.x
    // If no destination scheme was setup on our global config use the original file scheme.
    // @TODO alert if no Destination scheme?

    $processed_file_parts['desired_scheme'] = $destination_scheme = !empty($this->destinationScheme) ? $this->destinationScheme : $file_parts['destination_scheme'];
    // First part of Mime type becomes prefix. Performant for filtering in S3.
    $destination_basename = $file_parts['destination_filetype'] . '-' . $file_parts['destination_filename'];

    // Edge case, should only happen if all goes wrong.
    // RFC 2046: Since unknown mime-types always default to
    // application/octet-stream  and we use first part of the string
    // we default to 'application' here.
    if (empty($destination_basename)) {
      $destination_basename = 'application-unnamed';
    }
    else {
      // Object name limit for AWS S3 is 512 chars. Minio does not impose any.
      // UUID adds 36 characters,  plus 1 for the dash + 4 for extension.
      // So we shamelessly cut at 471. Someone needs to act!
      $destination_basename = substr($destination_basename, 0, 471);
    }

    // WE add the unique UUID at the end. That gives us best protection against
    // name collisions but still keeping human semantically aware file naming.
    $destination_extension = mb_strtolower(
      $file_parts['destination_extension'] ?? 'bin'
    );
    // First part of Mime type becomes prefix. Performant for filtering in S3.
    $destination_basename = $this->sanitizeFileName($destination_basename);
    $processed_file_parts['destination_filename'] =  $destination_filename = $destination_basename . '-' . $uuid . '.' . $destination_extension;
    $processed_file_parts['force'] = $force;

    // Allow other modules to alter the parts used to create final persistent destination.
    $file_extra_data = [
      'checksum' => $checksum,
      'file' => $file,
      'file_parts' => $processed_file_parts
    ];

    $this->moduleHandler->alter(
      'strawberryfield_file_destination',
      $processed_file_parts,
      $cleanjson,
      $file_extra_data
    );
    // Recover the $force flag from the alter
    $force = $processed_file_parts['force'] ?? $force;

    if ($force || $file->isTemporary()) {
      $desired_scheme = $processed_file_parts['desired_scheme'] ?? $destination_scheme;
      $destination_filename = $processed_file_parts['destination_filename'] ?? $destination_filename;
      $destination_folder = $processed_file_parts['destination_folder'] ?? $destination_folder;
      // Sanitize the whole thing.
      // Finally make temporary Again in case it is permanent and we forced this.
      if ($force && $file->isPermanent()) {
        $file->setTemporary();
        // Ensure its temporary so persisting Event Handler actually reacts to it.
        //$file->save();
      }
      return $desired_scheme . '://' . $destination_folder . '/' . $destination_filename;
    }
    else {
      return $file->getFileUri();
    }
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
    $basename = preg_replace(['~[^0-9a-z]~i', '~[-]+~'], '-', $basename);
    $basename = preg_replace('/\s+/', '-', $basename);
    return trim($basename, ' -');
  }

  public function calculateAsKeyFromFile(FileInterface $file) {
    // Calculate the destination json key
    $as_file_type = explode('/', $file->getMimeType());
    $as_file_type = count($as_file_type) == 2 ? $as_file_type[0] : 'document';
    $as_file_type = ($as_file_type != 'application') ? $as_file_type : 'document';
    return $as_file_type;
  }

  /**
   *  Generates the full AS metadata structure to keep track of SBF files.
   *
   * This method processes a single JSON Key with Entity IDs every time
   *
   * @param array $file_id_list
   * @param $file_source_key
   *   The top level JSON key/property that contains the file entity id(s).
   * @param array $cleanjson
   *   A previously existing JSON/SBF full content. Used to extract existing,
   *   already processed as:structures. This means the only real requirement
   *   are the as:structures, if an
   *
   * @param bool $force
   *    If true, we will force a new Path even if current localtion of the file
   *    Matches the Destination Schema. This can be altered:
   * @param bool $force_reduced_techmd
   *    Forces Minimal TECHMD to be produced
   *
   * @return array
   *    An array containing only as:structures with every file classified and
   *    their metadata.
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @see \Drupal\strawberryfield\StrawberryfieldFilePersisterService::getDestinationUri
   *
   */
  public function generateAsFileStructure(
    array $file_id_list,
          $file_source_key,
    array $cleanjson = [],
    bool $force = FALSE,
    bool $force_reduced_techmd = FALSE
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
        $this->t(
          'Sorry, we had real issues loading your files. Invalid Plugin File Definition.'
        )
      );
      return [];
    } catch (PluginNotFoundException $e) {
      $this->messenger()->addError(
        $this->t(
          'Sorry, we had real issues loading your files. File Plugin not Found'
        )
      );
      return [];
    }
    // @TODO: should we alert the user in case the list of ids does not yield in
    // the same amount of files loaded?


    // Will contain all as:something and its members based on referenced file ids
    $fileinfo_bytype_many = [];
    // Will contain temporary classification
    $files_bytype_many = [];
    // Simpler structure with classification and file id
    $file_list = [];

    // @TODO if count($files) is different than $file_id_list means we lost
    // a file from storage. Could have been temporary and it was never accounted
    // Notify the user of that. Not a good thing
    // Give the user the chance to restore the file from some other place.

    // Iterate and clasify by as: type
    foreach ($files as $file) {
      // Make sure mime is up to date!
      // Real use case since the file DB gets never reprocessed once saved.
      // And we could have update/upgraded our mappings.
      $uri = $file->getFileUri();
      $mimetype = \Drupal::service('strawberryfield.mime_type.guesser.mime')->guessMimeType(
        $uri
      );
      if (($file->getMimeType(
          ) != $mimetype) && ($mimetype != 'application/octet-stream')) {
        $file->setMimeType($mimetype);
        $file->save();
        //@TODO notify the user of the updated mime type.
      }

      // Calculate the destination json key
      $as_file_type = $this->calculateAsKeyFromFile($file);
      $files_bytype_many[$as_file_type][self::FILE_IRI_PREFIX . $file->uuid(
      )] = $file;
      // Simpler structure to iterate over
      $file_list[$as_file_type][] = $file->id();
    }
    // Second iteration, find if we already have a structure in place for them
    // Only to avoid calculating checksum again, if not generate.


    $to_process = [];
    foreach ($file_list as $askey => $fileids) {
      $fileinfo_bytype_many[self::AS_TYPE_PREFIX . $askey] = [];
      if (isset($cleanjson[self::AS_TYPE_PREFIX . $askey])) {
        // Gets us structures in place with checksum applied
        $fileinfo_bytype_many[self::AS_TYPE_PREFIX . $askey] = $this->retrieve_filestructure_from_metadata(
          $cleanjson[self::AS_TYPE_PREFIX . $askey],
          array_values($fileids),
          $file_source_key
        );
        // Now we need to know which ones still require processing
      }
      // We do this outside the isset to make sure we generate structures
      // Even when this is happening for the first time

      $to_process[$askey] = array_diff_key(
        $files_bytype_many[$askey],
        $fileinfo_bytype_many[self::AS_TYPE_PREFIX . $askey]
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
        $filemetadata = $this->strawberryfieldFileMetadataService->getBaseFileMetadata($file, $md5, count($files), $askey, $force_reduced_techmd);
        $uuid = $file->uuid();
        // again, i know!
        $mime = $file->getMimeType();
        // Desired destination. Passes also Clean JSON around.
        $destinationuri = $this->getDestinationUri($file, $md5, $cleanjson, $force);

        $fileinfo = [
          'type' => ucfirst($askey),
          'url' => $destinationuri,
          'crypHashFunc' => 'md5',
          'checksum' => $md5,
          'dr:for' => $file_source_key,
          'dr:fid' => (int) $file->id(),
          'dr:uuid' => $uuid,
          'dr:filesize' => (int) $file->getSize(),
          'dr:mimetype' => $mime,
          'name' => $file->getFilename(),
          'tags' => [],
        ];

        // Add Metadata from exif/fido/etc.
        $fileinfo = array_merge($fileinfo, $filemetadata);
        // Dispatch event with just the $fileinfo for a single file as JSON
        // This is used allow other functions to do things based on the JSON.
        // IN this case we want 'someone' to count the number of pages e.g
        // If the file is a PDF.
        // @TODO inject event dispatcher and move this to its own method.
        $event_type = StrawberryfieldEventType::JSONPROCESS;
        $event = new StrawberryfieldJsonProcessEvent(
          $event_type,
          $cleanjson,
          $fileinfo
        );

        $this->eventDispatcher->dispatch($event, $event_type);
        if ($event->wasModified()) {
          // Note: When the event gets an object passed,
          // We don't need to retrieve the changes. But our JSON is an array.
          // So we do relay on reassigning it.
          $fileinfo = $event->getProcessedJson();
        }

        //The node save hook will deal with moving data.
        // We don't need the key here but makes cleaning easier
        $fileinfo_bytype_many[self::AS_TYPE_PREFIX . $askey][self::FILE_IRI_PREFIX . $uuid] = $fileinfo;
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
   * Adds sequence key for a single as: file type (askey)
   *
   * @param array $fileinfo
   *    Contains as:document, etc key with all file data for that type.
   * @param array $flipped_json_keys_with_filenumids
   *    Contains the original JSON Key values with file ids but flipped.
   * @param string $sortmode
   *    Sort mode can be either 'natural' or 'index'
   *    - 'natural' will use the filenames to sort.
   *    - 'index' will respect the order of appearance.
   *    - 'manual' will just add new files at the end.
   * @param bool $force
   *    Force will reorder without respecting manual sequences.
   *
   * @return array
   */
  public function sortFileStructure(array $fileinfo, array $flipped_json_keys_with_filenumids, $sortmode = 'natural', bool $force = TRUE) {

    // So, here is how things go:
    // We sort anyway, faster than dividing and thinking too much.
    // But, we assign new sequence only to newer ones. So never (for now) to existing ones
    // with one exception. If the sequence matches the new order, which basically means
    // we are good.

    // 'manual' will always disabled force so we can add to the end
    $force = $sortmode == 'manual' ? FALSE : $force;

    if ($sortmode == 'natural') {
      uasort($fileinfo, [$this, 'sortByFileName']);
    }
    else {
      // Use the ingest order of the files, namely the order in which
      // The file IDs appear in each dr:for key
      // This applies for 'manual' and 'index' sort mode
      uasort($fileinfo,
        function ($a, $b) use ($flipped_json_keys_with_filenumids) {
          $source_field_a = $a['dr:for'];
          $source_field_b = $b['dr:for'];
          // In case this is totally wrong and we have no source Field at all, give it a large number so it gets pushed to the end.
          $comp_a = $flipped_json_keys_with_filenumids[$source_field_a][$a['dr:fid']] ?? 100000;
          $comp_b = $flipped_json_keys_with_filenumids[$source_field_b][$b['dr:fid']] ?? 100000;
          return $comp_a > $comp_b ? 1 : -1;
        });
    }
    $max_sequence = 0;
    // Let's get the max sequence first but only if not forcing a reorder.
    if (!$force) {
      $max_sequence = array_reduce(
        $fileinfo,
        function ($a, $b) {
          if (isset($b['sequence'])) {
            return max($a, (int) $b['sequence']);
          }
          else {
            return $a;
          }
        },
        0
      );
    }

    // For each always wins over array_walk
    $i = 0;
    $j = 0;
    foreach ($fileinfo as &$item) {
      $i++;
      //Order is already given by uasort but not trustable in JSON
      //So we set sequence number but let's check first what we got
      if (!isset($item['sequence']) || $force) {
        // Why $j and no $i? Because i want to only count ones without a sequence
        // And keep old sequence numbers if not forced (e.g 'manual');
        $j++;
        $item['sequence'] = $j + ($max_sequence);
      }
    }

    return $fileinfo;
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
    array $cleanjsonbytype,
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
        $found[self::FILE_IRI_PREFIX . $info['dr:uuid']] = $info;
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
      foreach ($field->getIterator() as $delta => $itemfield) {
        // Note: we are not longer touching the metadata here.
        /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */
        $flatvalues = (array) $itemfield->provideFlatten();
        if (isset($flatvalues['dr:fid'])) {
          foreach ($flatvalues['dr:fid'] as $fid) {
            if (is_numeric($fid)) {
              $file = $this->entityTypeManager->getStorage('file')->load($fid);
              /** @var $file \Drupal\file\FileInterface|NULL */

              //@TODO. We used to allow this service to act on any file
              // Allowing users to renamed/move files
              // Now only if it is temporary or scheme is temporary://
              // Because all not temporaries are already persisted.
              // This clashes with the fact that the file structure
              // Naming service will always try to name things in a certain
              // way. So either we allow both to act everytime or we
              // have a other 'move your files' service?


              // New option. {
              //    "ap:tasks": {
              //        "ap:forcefilemanage": true
              //    }
              //}
              $force_file_manage =  $flatvalues["ap:tasks"]["ap:forcefilemanage"] ?? FALSE;
              // We need to remove this now. Can't run again.
              if ($force_file_manage) {
                $fullvalues = $itemfield->provideDecoded(TRUE);

                unset($fullvalues["ap:tasks"]["ap:forcefilemanage"]);

                if (!$itemfield->setMainValueFromArray((array) $fullvalues)) {
                  $this->messenger->addError($this->t('We failed unsetting ap:forcefilemanage. Please remove manually.'));
                }
              }

              $scheme = $file ? $this->streamWrapperManager::getScheme($file->getFileUri()) : NULL;
              if ($file && ($file->isTemporary() || $scheme == 'temporary' || $force_file_manage)) {
                // This is tricky. We will allow non temporary to be moved if
                // The only usage is the current node!
                $uuid = $file->uuid();
                $current_uri = $file->getFileUri();
                // Get the info structure from flatten:
                if (isset($flatvalues[self::FILE_IRI_PREFIX . $uuid]) &&
                  isset($flatvalues[self::FILE_IRI_PREFIX . $uuid]['dr:fid']) &&
                  ($flatvalues[self::FILE_IRI_PREFIX . $uuid]['dr:fid'] = $fid) &&
                  isset($flatvalues[self::FILE_IRI_PREFIX . $uuid]['url']) &&
                  !empty($flatvalues[self::FILE_IRI_PREFIX . $uuid]['url'])) {
                  // Weird egde case:
                  // What if same urn:uuid:uuid has multiple info structures?
                  // Flattener could end being double nested?
                  $destination_uri = $flatvalues[self::FILE_IRI_PREFIX . $uuid]['url'];
                  // Only deal with expensive process if destination uri is
                  // different to the known one.
                  if ($destination_uri != $current_uri) {
                    $destination_folder = $this->fileSystem
                      ->dirname($destination_uri);
                    $this->fileSystem->prepareDirectory(
                      $destination_folder,
                      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
                    );
                    // Let's deal with the 5Gbyte Limit of the S3:// Wrapper here
                    if ($file->getSize() >= $this->storageConfig->get('multipart_upload_threshold')) {
                      $destination_uri = $this->copyOrPutS3Aware($current_uri, $destination_uri);
                    }
                    else {
                      // Normal Copy to new destination
                      $destination_uri = $this->fileSystem->copy(
                        $current_uri,
                        $destination_uri
                      );
                    }
                    // Means copying was successful
                    if ($destination_uri) {
                      $file->setFileUri($destination_uri);
                    }
                  }
                  try {
                    if (isset($info['name']) && !empty($info['name'])) {
                      $file->setFilename($info['name']);
                    }
                    $file->setPermanent();
                    $file->save();
                    $persisted++;
                    // Only attempt to compost if $destination_uri != $current_uri
                    // basically if the File was already in the perfect spot why attempt it again?
                    if ($destination_uri != $current_uri) {
                      $event_type = StrawberryfieldEventType::TEMP_FILE_CREATION;
                      $current_timestamp = (new DrupalDateTime())->getTimestamp();
                      $event = new StrawberryfieldFileEvent($event_type, 'strawberryfield', $current_uri, $current_timestamp);
                      // This will allow any temp file on ADO save to be managed
                      // IN a queue by \Drupal\strawberryfield\EventSubscriber\StrawberryfieldEventCompostBinSubscriber
                      $this->eventDispatcher->dispatch($event, $event_type);
                    }
                  }
                  catch (\Drupal\Core\Entity\EntityStorageException $e) {
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
                  $this->add_file_usage(
                    $file,
                    $entity->id(),
                    $entity_type_id
                  );
                }
              }
              elseif (!$file) {
                $this->messenger()->addError(
                  t(
                    'Your content references a file with Internal ID @file_id that we could not find a File Entity for or a full metadata definition for.',
                    ['@file_id' => $fid]
                  )
                );
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
   * Removes a list of Files from the as:structure and decreases its Usage
   * count.
   *
   * @param array $file_id_list
   * @param array $originaljson
   *
   * @param ContentEntityInterface $entity
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function removefromAsFileStructure(
    array $file_id_list,
    array $originaljson,
    ContentEntityInterface $entity
  ) {

    /** @var \Drupal\file\FileInterface[] $files */
    try {
      $files = $this->entityTypeManager->getStorage('file')->loadMultiple(
        $file_id_list
      );
    } catch (InvalidPluginDefinitionException $e) {
      $this->messenger()->addError(
        $this->t(
          'Sorry, we had real issues loading your files during cleanup/removal. Invalid Plugin File Definition.'
        )
      );
      return $originaljson;
    } catch (PluginNotFoundException $e) {
      $this->messenger()->addError(
        $this->t(
          'Sorry, we had real issues loading your files during cleanup/removal. File Plugin not Found.'
        )
      );
      return $originaljson;
    }
    $existing_ids = [];
    // Iterate and classify by as: type
    foreach ($files as $file) {
      $existing_ids[] = $file->id();
      $as_file_type = $this->calculateAsKeyFromFile($file);
      $uuid = $file->uuid();
      if (isset($originaljson[self::AS_TYPE_PREFIX . $as_file_type][self::FILE_IRI_PREFIX . $uuid])) {
        // Double check, may be silly but hey!
        if (isset($originaljson[self::AS_TYPE_PREFIX . $as_file_type][self::FILE_IRI_PREFIX . $uuid]['dr:fid']) &&
          $originaljson[self::AS_TYPE_PREFIX . $as_file_type][self::FILE_IRI_PREFIX . $uuid]['dr:fid'] == $file->id(
          )) {
          unset($originaljson[self::AS_TYPE_PREFIX . $as_file_type][self::FILE_IRI_PREFIX . $uuid]);
          // We can only remove usage for already saved content entities.
          if (!$entity->isNew()) {
            $this->remove_file_usage($file, $entity->id(), 'node', 1);
          }
        }
      }
    }

    $not_existing = array_diff($file_id_list, $existing_ids);

    // means we have left over files (coming from dr:fid that DO not longer
    // exist inside Drupal as entities. Clean this up but in a costly way.
    if (count($not_existing) > 0) {
      $originaljson = $this->removefromAsFileStructureBrutForce($not_existing, $originaljson);
    }

    return $originaljson;
  }

  /**
   * Removes a list of File IDs from the as:structure in a non optimal way.
   *
   * @param array $file_id_list
   * @param array $originaljson
   *
   * @return array
   */
  public function removefromAsFileStructureBrutForce(
    array $file_id_list,
    array $originaljson
  ) {
    // Iterate and over every as:file and compare against our known not existing
    // File entity IDs. If found remove.
    foreach (StrawberryfieldJsonHelper::AS_FILE_TYPE as $file_key) {
      if (isset($originaljson[$file_key]) &&
        is_array($originaljson[$file_key])) {
        foreach ($originaljson[$file_key] as $as_key => $as_entry) {
          if (isset($as_entry['dr:fid']) && in_array($as_entry['dr:fid'], $file_id_list)) {
            unset($originaljson[$file_key][$as_key]);
          }
        }
      }
    }
    return $originaljson;
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
              }
              else {
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
      return [$updated, $orphaned];
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
      return [$updated, $orphaned];
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
            )->accessCheck(FALSE)->execute();
            if (empty($values)) {
              $this->remove_file_usage($file, $id, $entity_type_id, 0);
              $orphaned++;
            }
          }
        }
        // Now check if there is still usage around.
        $usage = $this->fileUsage->listUsage($file);
        if (empty($usage)) {
          //@TODO D 8.4+ does not mark unused files as temporary.
          // For repo needs we want everything SBF managed to be cleaned up.
          $file->setTemporary();
          $file->save();
        }
      }
      else {
        //@TODO D 8.4+ does not mark unused files as temporary.
        // For repo needs we want everything SBF managed to be cleaned up.
        $file->setTemporary();
        $file->save();
      }
    }
    $updated = count($files);
    // Number of files we could update its usage record.
    return [$updated, $orphaned];
  }

  /**
   * Adds File usage to DB for SBF managed files.
   *
   * @param \Drupal\file\FileInterface $file
   * @param int $nodeid
   */
  protected function add_file_usage(
    FileInterface $file,
    int $nodeid,
    string $entity_type_id = 'node'
  ) {
    if (!$file || !$this->moduleHandler->moduleExists('file')) {
      return;
    }
    /** @var \Drupal\file\FileUsage\FileUsageInterface $file_usage */
    $this->fileUsage->add($file, 'strawberryfield', $entity_type_id, $nodeid);
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

    $this->fileUsage->delete(
      $file,
      'strawberryfield',
      $entity_type_id,
      $nodeid,
      $count
    );
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
   *
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
   *
   * @param $a
   * @param $b
   *
   * @return int
   */
  public function sortByFileName($a, $b) {
    return strnatcmp($a['name'], $b['name']);
  }

  /**
   *
   * Checks if a file managed by S3FS exists and caches its url if so.
   *
   * This function also allows an optional checksum to be passed
   * and used as comparison. If no match it returns FALSE
   *
   * @param string $uri
   *    An URI with stream wrapper protocol
   * @param string|null $checksum
   *    An optional MD5 Checksum to compare against
   *
   * @return bool
   *    TRUE if exists. IF checksum passed and exists and does not match, FALSE.
   */
  public function fileS3Exists(string $uri, string $checksum = NULL) {

    $exists = FALSE;
    // Soft dependency, if S3FS module is not enabled we return silently.
    if (empty(\Drupal::hasService('s3fs'))) {
      return FALSE;
    }
    $wrapper = $this->streamWrapperManager->getViaUri($uri);
    if (!$wrapper) {
      return FALSE;
    }
    if (get_class($wrapper) === 'Drupal\s3fs\StreamWrapper\S3fsStream') {
      $parts = explode('://', $uri);
      if (count($parts) == 2) {
        $protocol = $parts[0];
        $key = $parts[1];
      }
      else {
        // The URI is not even valid, do not bother on informing here
        return FALSE;
      }

      /* @var $s3fs \Drupal\s3fs\S3fsServiceInterface */
      $s3fs = \Drupal::Service('s3fs');
      $s3fsConfig = $this->configFactory->get('s3fs.settings');
      foreach ($s3fsConfig->get() as $prop => $value) {
        $config[$prop] = $value;
      }
      try {
        $client = $s3fs->getAmazonS3Client($config);
        $args = ['Bucket' => $config['bucket']];
        if ($protocol == 'private' && !empty($config['private_folder']) && strlen($config['private_folder'] ?? '' > 0)) {
          $key = $config['private_folder'] . '/' . $key;
        }
        elseif ($protocol == 'public' && !empty($config['public_folder']) && strlen($config['public_folder'] ?? '' > 0)) {
          $key = $config['public_folder'] . '/' . $key;
        }
        if (!empty($config['root_folder'])  && strlen($config['root_folder']  ?? '' > 0)) {
          $key = $config['root_folder'] . '/' . $key;
        }
        // Longer than the max Key for an S3 Object Path
        if (mb_strlen(rtrim($key, '/')) > 255) {
          return FALSE;
        }
        $args['Key'] = $key;
        $response = $client->headObject($args);
        $data = $response->toArray();
        if (isset($data['ETag']) || isset($data['Etag'])) {
          // Means it is there
          $exists = TRUE;
          if ($checksum && trim($data['ETag'], '"') !== $checksum) {
            $exists = FALSE;
          }
        }
      }
      catch (\Exception $exception) {
        $variables = Error::decodeException($exception);
        \Drupal::logger('sbf')
          ->error('%type: @message in %function (line %line of %file).',
            $variables);
        return FALSE;
      }
    }
    if ($exists) {
      // This may take up to 10 seconds for a non existing file
      // So we check upfront if its there before going this route
      $wrapper->writeUriToCache($uri);
    }
    return $exists;
  }

  /**
   *
   * Copies a file, but also checks if source/destination require S3 Multipart workaround.
   *
   * @param string $source_uri
   *    A source URI with stream wrapper protocol
   * @param string $destination_uri
   *   A destination URI with stream wrapper protocol
   *
   * @return string|bool
   *    FALSE if copy/upload was unsucessfull. $destination_uri if all went well.
   */
  public function copyOrPutS3Aware(string $source_uri, string $destination_uri) {

    $source_wrapper = $this->streamWrapperManager->getViaUri($source_uri);
    $destination_wrapper = $this->streamWrapperManager->getViaUri($destination_uri);
    $source_local = TRUE;
    $destination_local = TRUE;
    if (!$source_wrapper || !$destination_wrapper) {
      return FALSE;
    }

    $parts = explode('://', $source_uri);
    if (count($parts) == 2) {
      $source_protocol = $parts[0];
      $source_key = $parts[1];
      if (get_class($source_wrapper) === 'Drupal\s3fs\StreamWrapper\S3fsStream') {
        $source_local = FALSE;
      }
    } else {
      // The URI is not even valid, do not bother on informing here
      return FALSE;
    }

    $parts = explode('://', $destination_uri);
    if (count($parts) == 2) {
      $destination_protocol = $parts[0];
      $destination_key = $parts[1];
      if (get_class($destination_wrapper) === 'Drupal\s3fs\StreamWrapper\S3fsStream') {
        $destination_local = FALSE;
      }
    } else {
      // The URI is not even valid, do not bother on informing here
      return FALSE;
    }

    if (!$destination_local) {
      $s3fs = \Drupal::Service('s3fs');
      $s3fsConfig = $this->configFactory->get('s3fs.settings');
      foreach ($s3fsConfig->get() as $prop => $value) {
        $config[$prop] = $value;
      }
      if ($destination_protocol == 'private' && !empty($config['private_folder']) && strlen($config['private_folder'] ?? '' > 0)) {
        $destination_key = $config['private_folder'] . '/' . $destination_key;
      }
      elseif ($destination_protocol == 'public' && !empty($config['public_folder']) && strlen($config['public_folder'] ?? '' > 0)) {
        $destination_key = $config['public_folder'] . '/' . $destination_key;
      }
      if (!empty($config['root_folder']) && strlen($config['root_folder']  ?? '' > 0)) {
        $destination_key = $config['root_folder'] . '/' . $destination_key;
      }
      // Longer than the max Key for an S3 Object Path
      if (mb_strlen(rtrim($destination_key, '/')) > 255) {
        $this->loggerFactory->get('strawberryfield')->info('File @destination_uri will not be processed as S3 because the path is longer than 255 characters', ['@destination_uri' => $destination_uri]);
        return FALSE;
      }

      try {
        $client = $s3fs->getAmazonS3Client($config);
        $options = [
          'mup_threshold' => $this->storageConfig->get('multipart_upload_threshold')
        ];
        $bucket =  $config['bucket'];
        if (!$source_local) {
          if ($source_protocol == 'private' && !empty($config['private_folder']) && strlen($config['private_folder'] ?? '' > 0)) {
            $source_key  = $config['private_folder'] . '/' . $source_key;
          }
          elseif ($source_protocol == 'public' && !empty($config['public_folder']) && strlen($config['public_folder'] ?? '' > 0)) {
            $source_key = $config['public_folder'] . '/' . $source_key;
          }
          if (!empty($config['root_folder']) && strlen($config['root_folder']  ?? '' > 0)) {
            $source_key = $config['root_folder'] . '/' . $source_key;
          }

          try {
            $objectCopierPromise = new \Aws\S3\ObjectCopier($client, ['Bucket' => $bucket, 'Key' => $source_key], ['Bucket' => $bucket, 'Key' => $destination_key], 'private', $options);
            $result = $objectCopierPromise->copy();
            $destination_wrapper->writeUriToCache($destination_uri);
            $this->loggerFactory->get('strawberryfield')->info('File was successfully Copied to S3 at @destination_uri via Multipart', ['@destination_uri' => $destination_uri]);
            return $destination_uri;
          }
          catch (\Aws\Exception\MultipartUploadException $e) {
            $this->loggerFactory->get('sbf')->error($e->getMessage());
          }
          catch (\Exception $e) {
            $this->loggerFactory->get('sbf')->error($e->getMessage());
          }
        }
        else {
          // Use a stream instead of a file path.
          $source = fopen($source_uri, 'rb');
          if ($source) {
            $objectUploaderPromise = new \Aws\S3\ObjectUploader($client, $bucket, $destination_key, $source, 'private', $options);
            do {
              try {
                $result = $objectUploaderPromise->upload();
                if ($result["@metadata"]["statusCode"] == '200') {
                  $this->loggerFactory->get('strawberryfield')->info('File was successfully uploaded to S3 at @destination_uri via Multipart', ['@destination_uri' => $destination_uri]);
                }
                // If the SDK chooses a multipart upload, try again if there is an exception.
                // Unlike PutObject calls, multipart upload calls are not automatically retried.
              }
              catch (\Aws\Exception\MultipartUploadException $e) {
                rewind($source);
                $this->loggerFactory->get('strawberryfield')->warning('File failed uploading to S3 at @destination_uri via Multipart but we will try again', ['@destination_uri' => $destination_uri]);
                try {
                  $uploader = new \Aws\S3\MultipartUploader($client, $source, [
                    'state' => $e->getState(),
                  ]);
                }
                catch (\Exception $e) {
                  $this->loggerFactory->get('strawberryfield')->error('File failed uploading to S3 at @destination_uri from @source_uri via Multipart even if we retried', [
                    '%destination_uri' => $destination_uri,
                    '%source_uri' => $source_uri
                  ]);
                  return FALSE;
                }
              }
            } while (!isset($result));
            $destination_wrapper->writeUriToCache($destination_uri);
            fclose($source);
            return $destination_uri;
          }
          else {
            $this->loggerFactory->get('strawberryfield')->error('File upload %source_uri failed because we could not open the Source.', [
              '%source_uri' => $source_uri
            ]);
            return FALSE;
          }
        }
      }
      catch (\Exception $exception) {
        $this->loggerFactory->get('strawberryfield')->error('File upload %source_uri failed because we could not connect to S3.', [
          '%source_uri' => $source_uri
        ]);
        return FALSE;
      }
    }
    else {
      $destination_uri = $this->fileSystem->copy(
        $source_uri,
        $destination_uri
      );
      if ($destination_uri) {
        return $destination_uri;
      }
      else {
        $this->loggerFactory->get('strawberryfield')->error('File %source_uri direct copy operation failed.', [
          '%source_uri' => $source_uri
        ]);
        return FALSE;
      }
    }
  }
}
