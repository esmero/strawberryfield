<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 6/26/19
 * Time: 6:56 PM
 */

namespace Drupal\strawberryfield;

use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Archiver\ArchiverManager;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Mhor\MediaInfo\Exception\UnknownTrackTypeException;
use Mhor\MediaInfo\MediaInfo;

/**
 * Provides a SBF File persisting class.
 */
class StrawberryfieldFileMetadataService {

  use StringTranslationTrait;
  use MessengerTrait;

  /**
   * File system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * The Storage Destination Scheme.
   *
   * @var string;
   */
  protected $destinationScheme = NULL;

  /**
   * The SBF configuration settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

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
   * If getBaseFileMetadata should be processed
   *
   * @var bool
   */
  protected $extractFileMetadata = FALSE;

  /**
   * If Temp file should be deleted immediately after processing.
   *
   * @var bool
   */
  protected $cleanUp = FALSE;

  /**
   * StrawberryfieldFileMetadataService constructor.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param StrawberryfieldUtilityService $strawberryfield_utility_service
   */
  public function __construct(
    FileSystemInterface $file_system,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    StrawberryfieldUtilityService $strawberryfield_utility_service
  ) {
    $this->fileSystem = $file_system;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->destinationScheme = $config_factory->get(
      'strawberryfield.storage_settings'
    )->get('file_scheme');
    $this->config = $config_factory->get(
      'strawberryfield.filepersister_service_settings'
    );
    $this->loggerFactory = $logger_factory;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    // This will verified once per injection of the service, not every time
    if ((boolean) $this->config->get('extractmetadata')) {
      $canrun_exif = $this->strawberryfieldUtility->verifyCommand(
        $this->config->get('exif_exec_path')
      );
      $canrun_fido = $this->strawberryfieldUtility->verifyCommand(
        $this->config->get('fido_exec_path')
      );
      if ($canrun_exif || $canrun_fido) {
        $this->extractFileMetadata = TRUE;
      }
      else {
        // This will be moved to runners anyway so won't work it too
        // much more.
        $this->loggerFactory->get('strawberryfield')->warning(
          'File Metadata Extraction is enabled on ingest via Strawberryfield but neither EXIF or FIDO paths are correct executables. Please correct of disable.'
        );
      }
      $this->cleanUp = $this->config->get('delete_tempfiles') ?? FALSE;
    }
  }

  /**
   * Gets basic metadata from a File to be put back into a SBF
   *
   * Also deals with the fact that it can be local v/s remote.
   *
   * @param \Drupal\file\FileInterface $file
   *  A file
   * @param  string|bool $checksum
   * @param $total_count
   * @param string $askey
   *   How the file was classified according to the as:key format
   *   Can be: document, image, model, text, application, movie, sound etc.
   *
   * @return array
   *    Metadata extracted for the image in array format if any
   */
  public function getBaseFileMetadata(
    FileInterface $file, $checksum, $total_count = 1,
    $askey = 'document'
  ) {

    // - How many files? Like 1 is cool, 2000 not cool
    // - Size? Like moving realtime 'Sync' 2TB back to TEMP to MD5-it not cool
    $metadata = [];

    if (!$this->extractFileMetadata) {
      // early return if not allowed.
      return $metadata;
    }
    // I'm assuming binaries exists and are there.
    // Should we check everytime?
    // Or just when saving via the form?

    $exif_exec_path = trim($this->config->get('exif_exec_path'));
    $fido_exec_path = trim($this->config->get('fido_exec_path'));
    $identify_exec_path = trim($this->config->get('identify_exec_path'));
    $pdfinfo_exec_path = trim($this->config->get('pdfinfo_exec_path'));
    $mediainfo_exec_path = trim($this->config->get('mediainfo_exec_path'));
    $cleanup = $this->cleanUp;

    $uri = $file->getFileUri();

    $templocation = NULL;
    $templocation = $this->ensureFileAvailability($file, $checksum);


    if (!$templocation) {
      $this->loggerFactory->get('strawberryfield')->warning(
        'Could not adquire a local accessible location for metadata extraction for file with URL @fileurl. Aborted processing. Please check you have space in your temporary storage location.',
        [
          '@fileurl' => $file->getFileUri(),
        ]
      );
      return $metadata;
    }
    if ($templocation === TRUE) {
      // We can not cleanup a file that is local and was local all the time
      // During ingest.
      $cleanup = FALSE;
      $templocation = $this->fileSystem->realpath(
        $uri
      );
    }
    // Deal with the possible fact that Checksum may have been passed empty
    // Or failed because of the file being initially remote.
    if (!$checksum) {
      $md5 = md5_file($templocation);
      $metadata['checksum'] = $md5;
      $metadata['crypHashFunc'] = 'md5';
    }

    if (strlen($exif_exec_path) == 0) {
      $this->loggerFactory->get('strawberryfield')->warning(
        '@fileurl was not processed using EXIF because the path is not set. <a href="@url">Please configure it here if you want that</a>',
        [
          '@fileurl' => $file->getFileUri(),
          '@url' => Url::fromRoute(
            'strawberryfield.file_persister_settings_form'
          )->toString(),
        ]
      );
    }
    else {
      $this->extractExif($askey, $exif_exec_path, $file, $templocation,
        $metadata);
    }

    if (strlen($fido_exec_path) == 0) {
      $this->loggerFactory->get('strawberryfield')->warning(
        '@fileurl was not processed using Fido(Pronom) because the path is not set. <a href="@url">Please configure it here if you want that</a>',
        [
          '@fileurl' => $file->getFileUri(),
          '@url' => Url::fromRoute(
            'strawberryfield.file_persister_settings_form'
          )->toString(),
        ]
      );
    }
    else {
      $this->extractPronom($askey, $fido_exec_path, $file, $templocation,
        $metadata);
    }

    if (strlen($identify_exec_path) == 0) {
      $this->loggerFactory->get('strawberryfield')->warning(
        '@fileurl was not processed using Identify because the path is not set. <a href="@url">Please configure it here if you want that</a>',
        [
          '@fileurl' => $file->getFileUri(),
          '@url' => Url::fromRoute(
            'strawberryfield.file_persister_settings_form'
          )->toString(),
        ]
      );
    }
    else {
      $this->extractIdentify($askey, $identify_exec_path, $file, $templocation,
        $metadata);
    }

    if (strlen($pdfinfo_exec_path) == 0) {
      $this->loggerFactory->get('strawberryfield')->warning(
        '@fileurl was not processed using PDFinfo because the path is not set. <a href="@url">Please configure it here if you want that</a>',
        [
          '@fileurl' => $file->getFileUri(),
          '@url' => Url::fromRoute(
            'strawberryfield.file_persister_settings_form'
          )->toString(),
        ]
      );
    }
    else {
      $this->extractPdfInfo($askey, $pdfinfo_exec_path, $file, $templocation,
        $metadata);
    }

    if (strlen($mediainfo_exec_path) == 0) {
      $this->loggerFactory->get('strawberryfield')->warning(
        '@fileurl was not processed using MediaInfo because the path is not set. <a href="@url">Please configure it here if you want that</a>',
        [
          '@fileurl' => $file->getFileUri(),
          '@url' => Url::fromRoute(
            'strawberryfield.file_persister_settings_form'
          )->toString(),
        ]
      );
    }
    else {
      $this->extractMediaInfo($askey, $mediainfo_exec_path, $file, $templocation,
        $metadata);
    }

    // Now check if cleanup is needed and how

    if ($cleanup) {
      $cleaned = $this->cleanUptemp($templocation);
      if (!$cleaned) {
        $this->loggerFactory->get('strawberryfield')->warning(
          'temporary @fileurltemp for @fileurl could not be removed. Please check if its there, can be delete, and delete only the temp one manually ',
          [
            '@fileurl' => $file->getFileUri(),
            '@fileurltemp' => $templocation,
          ]
        );
      }
    }

    return $metadata;
  }

  /**
   * @param string $askey
   * @param string $exec_path
   * @param \Drupal\file\FileInterface $file
   * @param string $templocation
   * @param array $metadata
   */
  public function extractExif(string $askey, string $exec_path, FileInterface $file, string $templocation, &$metadata = []) {
    $templocation_for_exec = escapeshellarg($templocation);
    $result_exif = exec(
      $exec_path . ' -json -q -a -gps:all -Common "-gps*" -xmp:all -XMP-tiff:Orientation -ImageWidth -ImageHeight -Canon -Nikon-AllDates -pdf:all -ee -MIMEType ' . $templocation_for_exec,
      $output_exif,
      $status_exif
    );

    // First EXIF
    if ($status_exif != 0) {
      // Means exiftool did not work
      $this->loggerFactory->get('strawberryfield')->warning(
        'Could not process EXIF on @templocation for @fileurl',
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
        foreach ($exif as &$exifitem) {
          $exifitem = is_array($exifitem) ? array_unique(
            $exifitem
          ) : $exifitem;
        }
        $metadata['flv:exif'] = $exif;
      }
    }
  }

  /**
   * @param string $askey
   * @param string $exec_path
   * @param \Drupal\file\FileInterface $file
   * @param string $templocation
   * @param array $metadata
   */
  public function extractPronom(string $askey, string $exec_path, FileInterface $file, string $templocation, &$metadata = []) {
    $templocation_for_exec = escapeshellarg($templocation);
    $result_fido = exec(
      $exec_path . ' ' . $templocation_for_exec,
      $output_fido,
      $status_fido
    );

    // Second FIDO
    if ($status_fido != 0) {
      // Means Fido did not work
      $this->loggerFactory->get('strawberryfield')->warning(
        'Could not process FIDO on @templocation for @fileurl',
        [
          '@fileurl' => $file->getFileUri(),
          '@templocation' => $templocation,
        ]
      );
    }
    else {
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

  /**
   * @param string $askey
   * @param string $exec_path
   * @param \Drupal\file\FileInterface $file
   * @param string $templocation
   * @param array $metadata
   */
  public function extractPdfInfo(string $askey, string $exec_path, FileInterface $file, string $templocation, &$metadata = []) {
    $templocation_for_exec = escapeshellarg($templocation);
    if (in_array($file->getMimeType(),
      ['application/pdf', 'application/postscript'])) {
      $result_pdfinfo = exec(
        $exec_path . ' ' . $templocation_for_exec . " | grep '^Pages:' ",
        $output_pdfinfo,
        $status_pdfinfo
      );

      if ($status_pdfinfo != 0) {
        // Means Fido did not work
        $this->loggerFactory->get('strawberryfield')->warning(
          'Could not process PDFinfo page count on @templocation for @fileurl',
          [
            '@fileurl' => $file->getFileUri(),
            '@templocation' => $templocation,
          ]
        );
      }
      else {
        // We need the number of pages first
        $pagecount = explode(':', $output_pdfinfo[0]);
        if (count($pagecount) == 2) {
          $pagecount_int = (int) $pagecount[1];
          // Second pass now
          $result_pages_pdfinfo = exec(
            $exec_path . ' ' . $templocation_for_exec . " -f 1 -l $pagecount_int |grep '^Page' ",
            $output_pdfinfo_pages,
            $status_pdfinfo_pages
          );
          if ($status_pdfinfo_pages != 0) {
            // Means Fido did not work
            $this->loggerFactory->get('strawberryfield')->warning(
              'Could not process PDFinfo page dimensions on @templocation for @fileurl',
              [
                '@fileurl' => $file->getFileUri(),
                '@templocation' => $templocation,
              ]
            );
          }
          else {
            $pdfinfo_metadata = [];
            // Rotation to Orientation/pdfinfo will give is the first
            //  0 (no rotation), TopLeft
            //(rotation to the East, or 90 degrees clockwise), LeftBottom
            // (rotation to the South, tumbled page image, upside-down, or 180 degrees clockwise), BottomRight
            // (rotation to the West, or 90 degrees counter-clockwise, or 270 degrees clockwise). RightTop
            // @see https://stackoverflow.com/questions/9371273/how-can-i-display-the-orientation-of-a-jpeg-file
            $rot_to_orient = [
              '0' => 'TopLeft',
              '90' => 'LeftBottom',
              '180' => 'BottomRight',
              '270' => 'RightTop',
            ];
            if (count($output_pdfinfo_pages) > 1) {
              $i = 0;
              /* $output_pdfinfo_pages will be something like this
               0 => "Pages:          100"
               1 => "Page    1 size: 635.05 x 797.05 pts"
               2 => "Page    1 rot:  0"
               3 => "Page    2 size: 623 x 795.95 pts"
               4 => "Page    2 rot:  0"
              */
              foreach ($output_pdfinfo_pages as $value) {
                $i++;
                if ($i == 1) {
                  // Skip first line
                  continue;
                }
                $page_info = preg_split(
                  '/(:|[\s]+|x)/',
                  $value,
                  -1,
                  PREG_SPLIT_NO_EMPTY
                );
                if (count($page_info) >= 4) {
                  if (trim($page_info[2]) == "size") {
                    $pdfinfo_metadata[trim(
                      $page_info[1]
                    )]['width'] = $page_info[3];
                    $pdfinfo_metadata[trim(
                      $page_info[1]
                    )]['height'] = $page_info[4];
                  }
                  elseif (trim($page_info[2]) == "rot") {
                    $pdfinfo_metadata[trim(
                      $page_info[1]
                    )]['rotation'] = $page_info[3];

                    $pdfinfo_metadata[trim(
                      $page_info[1]
                    )]['orientation'] = $rot_to_orient[$page_info[3]];
                  }
                }
              }
              if (count($pdfinfo_metadata) >= 1) {
                $metadata['flv:pdfinfo'] = $pdfinfo_metadata;
              }
            }
          }
        }
      }
    }
  }

  /**
   * @param string $askey
   * @param string $exec_path
   * @param \Drupal\file\FileInterface $file
   * @param string $templocation
   * @param array $metadata
   */
  public function extractIdentify(string $askey, string $exec_path, FileInterface $file, string $templocation, &$metadata = []) {
    $templocation_for_exec = escapeshellarg($templocation);
    if (in_array($askey, ['image'])) {
      $result_identify = exec(
        $exec_path . " -format 'format:%m|width:%w|height:%h|orientation:%[orientation]@' -quiet " . $templocation_for_exec,
        $output_identify,
        $status_identify
      );

      if ($status_identify != 0) {
        // Means Identify did not work
        $this->loggerFactory->get('strawberryfield')->warning(
          'Could not process Identify on @templocation for @fileurl',
          [
            '@fileurl' => $file->getFileUri(),
            '@templocation' => $templocation,
          ]
        );
      }
      else {
        // JSON-ify Identify data
        $identify_meta = [];
        if (count($output_identify) && isset($output_identify[0])) {
          $output_identify = array_filter(
            explode(
              '@',
              $output_identify[0]
            )
          );
          foreach ($output_identify as $sequencenumber => $pageinfo) {
            if (is_string($pageinfo)) {
              $pageinfo_array = array_filter(explode('|', $pageinfo));
              $identify = [];
              if (count($pageinfo_array)) {
                foreach ($pageinfo_array as $value) {
                  if (is_string($value) && (strlen($value) > 1)) {
                    $pair = array_filter(explode(':', $value));
                    if (count($pair)) {
                      $identify[$pair[0]] = isset($pair[1]) ? $pair[1] : NULL;
                    }
                  }
                }
              }
              $identify_meta[$sequencenumber + 1] = $identify;
            }
          }
          $metadata['flv:identify'] = $identify_meta;
        }
      }
    }
  }

  /**
   * @param string $askey
   * @param string $exec_path
   * @param \Drupal\file\FileInterface $file
   * @param string $templocation
   * @param array $metadata
   *
   */
  public function extractMediaInfo(string $askey, string $exec_path, FileInterface $file, string $templocation, &$metadata = []) {
    // Only run Media info if Video/Audio
    if (in_array($askey, ['audio', 'video'])) {
      $mediaInfo = new MediaInfo();
      $mediaInfo->setConfig('command', $exec_path);
      try {
        $mediaInfoContainer = $mediaInfo->getInfo($templocation, TRUE);
        $metadata['flv:mediainfo'] = $mediaInfoContainer->__toArray();
      } catch (UnknownTrackTypeException $e) {
        $this->loggerFactory->get('strawberryfield')->warning(
          'Could not process MediaInfo on @templocation for @fileurl',
          [
            '@fileurl' => $file->getFileUri(),
            '@templocation' => $templocation,
          ]
        );
      }
    }
  }

  /**
   * Removes an non managed file and temp generated by this module.
   *
   * @param string $templocation
   *
   * @return bool
   */
  public function cleanUpTemp(string $templocation) {
    return $this->fileSystem->unlink($templocation);
  }

  /**
   * Move file to local to if needed process.
   *
   * @param \Drupal\file\FileInterface $file
   *   The File URI to look at.
   *
   * @return string|bool
   *   The temp local file path or.
   *   False if we could not acquire location
   *   TRUE if its already local so we can use existing path.
   */
  private function ensureFileAvailability(FileInterface $file, $cheksum) {
    $uri = $file->getFileUri();
    // Local stream.
    $cache_key = $cheksum ?? md5($uri);
    // Check first if the file is already around in temp?
    // @TODO can be sure its the same one? Ideas?
    // If the file isn't stored locally make a temporary copy.
    /** @var \Drupal\Core\File\FileSystem $file_system */
    $scheme = $this->streamWrapperManager->getScheme($uri);
    if (!isset($this->streamWrapperManager->getWrappers(
        StreamWrapperInterface::LOCAL)[$scheme]
    )) {
      if (is_readable(
        $this->fileSystem->realpath(
          'temporary://sbr_' . $cache_key . '_' . basename($uri)
        )
      )) {
        $templocation = $this->fileSystem->realpath(
          'temporary://sbr_' . $cache_key . '_' . basename($uri)
        );
      }
      else {
        try {
          $templocation = $this->fileSystem->copy(
            $uri,
            'temporary://sbr_' . $cache_key . '_' . basename($uri),
            FileSystemInterface::EXISTS_REPLACE
          );
          $templocation = $this->fileSystem->realpath(
            $templocation
          );
        } catch (FileException $exception) {
          // Means the file is not longer there
          // This happens if a file was added and shortly after that removed and replace
          // by a new one.
          $templocation = FALSE;
        }
      }
    }
    else {
      return TRUE;
    }

    if (!$templocation) {
      $this->loggerFactory->get('strawberryfield')->warning(
        'Could not adquire a local accessible location for text extraction for file with URL @fileurl. File may no longer exist.',
        [
          '@fileurl' => $file->getFileUri(),
        ]
      );
      return FALSE;
    }
    else {
      return $templocation;
    }
  }


}
