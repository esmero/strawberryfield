<?php

/**
 * @file
 * Hooks provided by the strawberryfield module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Lets modules alter the saving path of a File during CRUD.
 *
 *   To effectively modify the final destination of a file $file_parts entries
 *   may be altered. Changing a file extension is not a good idea
 *   Its up to the implementer to make sure that the scheme/folders are
 * writable. To understand how the final name is generated look at the invoking
 * method.
 *
 * @param array $processed_file_parts
 *      Contains the so far calculated final destination (Path) of a file
 *   during
 *      and ADO CRUD operation. This is what you want to actually alter. It has
 *   the following structure:
 *        $processed_file_parts['desired_scheme'] => The saving scheme
 *        $processed_file_parts['destination_filename'] => the file name
 *        $processed_file_parts['destination_folder'] => Folder(s) without
 *   starting nor trailing '/'
 *
 * @param array $sbf_json_as_array
 *       An array from the decode Strawberry Field JSON that references this
 *   file (parent ADO's metadata contained in a SBF type of field).
 * @param array $file_extra_data
 *       An array with the following file info that can be used to decide
 *       or compute based on the Generic File URI generator:
 *       $file_extra_data['checksum'] => The checksum of the file
 *       $file_extra_data['file'] => $file entity, @see
 *   \Drupal\file\FileInterface
 *       $file_extra_data['file_parts'] array with The original building blocks
 *   used to create the destination to be altered.
 *           'destination_folder' => The relative folder;
 *           'destination_filename' => The not sanitized file name
 *           'destination_extension_secondary' => e.g tar for tar.gz
 *           'destination_extension' => e.g gz or combined tar.gz
 *           'destination_scheme' => Destination scheme e.g s3 (no ://)
 *           'destination_filetype' =>The first part of the mimetype used as
 *   prefix to build the basename by the parent caller.
 *
 * @see \Drupal\strawberryfield\StrawberryfieldFilePersisterService::getDestinationUri
 *
 */
function hook_strawberryfield_file_destination_alter(array &$processed_file_parts, array $sbf_json_as_array, array $file_extra_data) {
  // Example alter based on metadata
  if (isset($sbf_json_as_array['type']) && $sbf_json_as_array['type'] == 'photograph') {
    // Use the file checksum to generate extra subfolders
    $new_relativefolder = substr($file_extra_data['checksum'], 3, 3);
    $processed_file_parts['destination_folder'] = $processed_file_parts['destination_folder'] . '/' . $new_relativefolder;
    // Now use the original filename instead of the one generate by archipelago
    $file = $file_extra_data['file'];
    /** @var \Drupal\file\FileInterface $file */
    // Warning this may collide! So just an example. Also clean your values! or add the UUID.
    $processed_file_parts['destination_filename'] = $file->getFilename();
  }
}

/**
 * @} End of "addtogroup hooks".
 */