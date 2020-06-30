<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 6/2/20
 * Time: 11:15 AM
 */

namespace Drupal\strawberryfield\Commands;

use Drush\Commands\DrushCommands;
use Drush\Drush;
use Drush\Exceptions\UserAbortException;
use Psr\Log\LogLevel;
use Symfony\Component\Filesystem\Filesystem;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drush\Exec\ExecTrait;
use Swaggest\JsonSchema\Schema as JsonSchema;
use Swaggest\JsonSchema\Exception as JsonSchemaException;
use Swaggest\JsonSchema\InvalidValue as JsonSchemaInvalidValue;


/**
 * A SBF Drush commandfile.
 *
 */
class JsonApiDrushCommands extends DrushCommands {

  use ExecTrait;

  protected $user;

  protected $password;

  /**
   * JSON SCHEMA Draft 7.0 for a JSON API NODE via POST
   */
  const acceptedjsonschemapost = <<<'JSON'
{
  "$id": "http://archipelago.nyc/jsonschemas/jsonapinodepost.json",
  "$schema": "http://json-schema.org/schema#",
  "type": "object",
  "definitions": {
     "relationshipToOne": {
      "description": "References to other resource objects in a to-one (\"relationship\"). Relationships can be specified by including a member in a resource's links object.",
      "anyOf": [
        {
          "$ref": "#/definitions/empty"
        },
        {
          "$ref": "#/definitions/linkage"
        }
      ]
    },
    "relationshipToMany": {
      "description": "An array of objects each containing \"type\" and \"id\" members for to-many relationships.",
      "type": "array",
      "items": {
        "$ref": "#/definitions/linkage"
      },
      "uniqueItems": true
    },
    "empty": {
      "description": "Describes an empty to-one relationship.",
      "type": "null"
    },
    "linkage": {
      "description": "The \"type\" and \"id\" to non-empty members.",
      "type": "object",
      "required": [
        "type",
        "id"
      ],
      "properties": {
        "type": {
          "type": "string"
        },
        "id": {
          "type": "string"
        },
        "meta": {
          "$ref": "#/definitions/meta"
        }
      },
      "additionalProperties": false
    },
     "meta": {
      "description": "Non-standard meta-information that can not be represented as an attribute or relationship.",
      "type": "object",
      "additionalProperties": true
    },
    "resource": {
      "description": "\"Resource objects\" appear in a JSON:API document to represent resources.",
      "type": "object",
      "required": [
        "type",
        "id"
      ],
      "properties": {
        "type": {
          "type": "string"
        },
        "id": {
          "type": "string"
        },
        "attributes": {
          "$ref": "#/definitions/attributes"
        },
        "relationships": {
          "$ref": "#/definitions/relationships"
        },
        "links": {
          "$ref": "#/definitions/links"
        },
        "meta": {
          "$ref": "#/definitions/meta"
        }
      },
      "additionalProperties": false
    },
    "relationshipLinks": {
      "description": "A resource object **MAY** contain references to other resource objects (\"relationships\"). Relationships may be to-one or to-many. Relationships can be specified by including a member in a resource's links object.",
      "type": "object",
      "properties": {
        "self": {
          "description": "A `self` member, whose value is a URL for the relationship itself (a \"relationship URL\"). This URL allows the client to directly manipulate the relationship. For example, it would allow a client to remove an `author` from an `article` without deleting the people resource itself.",
          "$ref": "#/definitions/link"
        },
        "related": {
          "$ref": "#/definitions/link"
        }
      },
      "additionalProperties": true
    },
    "links": {
      "type": "object",
      "additionalProperties": {
        "$ref": "#/definitions/link"
      }
    },
    "link": {
      "description": "A link **MUST** be represented as either: a string containing the link's URL or a link object.",
      "oneOf": [
        {
          "description": "A string containing the link's URL.",
          "type": "string",
          "format": "uri-reference"
        },
        {
          "type": "object",
          "required": [
            "href"
          ],
          "properties": {
            "href": {
              "description": "A string containing the link's URL.",
              "type": "string",
              "format": "uri-reference"
            },
            "meta": {
              "$ref": "#/definitions/meta"
            }
          }
        }
      ]
    },
    "attributes": {
      "description": "Members of the attributes object (\"attributes\") represent information about the resource object in which it's defined.",
      "type": "object",
      "patternProperties": {
        "^(?!relationships$|links$|id$|type$)\\w[-\\w_]*$": {
          "description": "Attributes may contain any valid JSON value."
        }
      },
      "additionalProperties": false
    },
    "relationships": {
      "description": "Members of the relationships object (\"relationships\") represent references from the resource object in which it's defined to other resource objects.",
      "type": "object",
      "patternProperties": {
        "^(?!id$|type$)\\w[-\\w_]*$": {
          "properties": {
            "links": {
              "$ref": "#/definitions/relationshipLinks"
            },
            "data": {
              "description": "Member, whose value represents \"resource linkage\".",
              "oneOf": [
                {
                  "$ref": "#/definitions/relationshipToOne"
                },
                {
                  "$ref": "#/definitions/relationshipToMany"
                }
              ]
            },
            "meta": {
              "$ref": "#/definitions/meta"
            }
          },
          "anyOf": [
            {
              "required": [
                "data"
              ]
            },
            {
              "required": [
                "meta"
              ]
            },
            {
              "required": [
                "links"
              ]
            }
          ],
          "additionalProperties": false
        }
      },
      "additionalProperties": false
    }
  },
  "properties": {
    "data": {
      "description": "The document's \"primary data\" is a representation of the resource or collection of resources targeted by a request.",
      "oneOf": [
        {
          "$ref": "#/definitions/resource"
        },
        {
          "description": "An array of resource objects, an array of resource identifier objects, or an empty array ([]), for requests that target resource collections.",
          "type": "array",
          "items": {
            "$ref": "#/definitions/resource"
          },
          "uniqueItems": true
        },
        {
          "description": "null if the request is one that might correspond to a single resource, but doesn't currently.",
          "type": "null"
        }
      ]
    }
  }
}
JSON;


  /**
   * Wraps a JSON API node post call back with added files.
   *
   * @param string $jsonfilepath
   *    A file containing either a full JSON API data payload or just SBF JSON
   *   data.
   *
   * @command archipelago:jsonapi-ingest
   * @aliases ap-jsonapi-ingest
   * @options user JSON API capable user
   * @options password JSON API capable user's password
   * @options files file or folder containing things to be uploaded and
   *   attached to json
   * @options bundle Machine name of the bundle.
   * @options uuid target uuid for new digital object.
   *
   * @usage archipelago:jsonapi-ingest digital_object.json --user=jsonapi
   *   --password=yourpassword --files=/home/www/someplace
   *   --bundle=digital_object --moderation_state=published
   */
  public function ingest(
    $jsonfilepath,
    $options = [
      'files' => '',
      'user' => NULL,
      'password' => NULL,
      'bundle' => 'digital_object',
      'fieldname' => 'field_descriptive_metadata',
      'uuid' => NULL,
      'moderation_state' => NULL,
    ]
  ) {

    // If you want to help please read https://weitzman.github.io/blog/port-to-drush9
    if (!\Drupal::moduleHandler()->moduleExists('jsonapi')) {
      throw new \Exception(
        dt(
          'The JSON API Module needs to be enabled to be able to ingest Archipelago Digital Objects'
        )
      );
    }
    //@see https://www.drupal.org/project/drupal/issues/3072076
    if (!\Drupal::moduleHandler()->moduleExists(
      'jsonapi_earlyrendering_workaround'
    )) {
      throw new \Exception(
        dt(
          'This module needs the jsonapi_earlyrendering_workaround module installed while https://www.drupal.org/project/drupal/issues/3072076 gets merged. Please run php -dmemory_limit=-1 /usr/bin/composer require drupal/jsonapi_earlyrendering_workaround; drush en jsonapi_earlyrendering_workaround; '
        )
      );
    }

    if (!ExecTrait::programExists('curl')) {
      throw new \Exception(
        dt(
          'curl binary needs to exist to be able to ingest Archipelago Digital Objects using this command'
        )
      );
    }

    if (strlen($options['bundle']) == 0) {
      $bundle = 'digital_object';
    }
    else {
      $bundle = $options['bundle'];
    }
    // Build the POST URI for the request
    $base_url = $this->input()->getOption('uri');
    $fileurlpost = $base_url . '/jsonapi/node/' . $bundle . '/field_file_drop';
    $nodeurlpost = $base_url . '/jsonapi/node/' . $bundle;

    // Check if files is passed and if file or folder
    if ($options['files']) {
      if (is_dir($options['files'])) {
        // @TODO should we allow a pattern to be passed as argument?
        $files = \Drupal::service('file_system')->scanDirectory(
          $options['files'],
          '/\.*$/',
          [
            'callback' => 0,
            'recurse' => FALSE,
            'key' => 'uri',
            'min_depth' => 0,
          ]
        );
        if (count($files)) {
          $this->output()->writeln(dt('Files in provided location:'));
        }
        foreach ($files as $file) {
          //@TODO list files here?
          $this->output()->writeln(' - '.$file->filename);
        }

      }
      else {
        $this->output()->writeln(dt('No files provided'));

      }
    }

    // @see https://www.drupal.org/docs/8/core/modules/jsonapi-module/creating-new-resources-post
    // @see https://www.drupal.org/node/3024331

    // We could use Guzzle and stuff, but to be honest we just need to call CURL.

    // This will also allow us to do some crazy stuff like allowing partial JSONs
    // SBF only pushes and also check for validity
    // In the end all this will serve as wrap around for the AMI UI processing
    // module.

    $json_data = @file_get_contents($jsonfilepath);

    // Only process if json_data is present
    if ($json_data && StrawberryfieldJsonHelper::isJsonString($json_data)) {
      $schema = JsonSchema::import(
        json_decode($this::acceptedjsonschemapost)
      );
      // We want to create the Digital Object first and then attach the files?
      // Probably not.
      // Check if JSON is a full JSON API data payload or just our SBF.
      // If just SBF, we need to have the machine name of the field to push data
      $data = json_decode($json_data, TRUE);
      if (isset($data['data']['type']) && $data['data']['type'] == 'node--' . $bundle) {
        try {
          // @see https://github.com/swaggest/php-json-schema
          $schema->in((Object) $data);
        } catch (JsonSchemaException $exception) {
          throw new \Exception(
            dt(
              'The provided JSON is not a valid JSON API payload. Suspending the ingest'
            )
          );
        }
      }
      else {
        // Means we need to create our own body
        if (!$options['uuid']) {
          $options['uuid'] = \Drupal::service('uuid')->generate();
          $this->output()->writeln(
            dt(
              'Using the following @uuid for your new ADO',
              [
                '@uuid' => $options['uuid'],
              ]
            )
          );
        }
        $field_name = NULL;
        $sbf_fields = array_values(
          \Drupal::service('strawberryfield.utility')
            ->getStrawberryfieldMachineForBundle($bundle)
        );
        // If there are more than 1 then we need someone to tell us which one!
        // but if only one we are all good
        if (count($sbf_fields) == 1) {
          $field_name = reset($sbf_fields);
        }
        elseif ($options['fieldname'] && in_array(
            $options['fieldname'],
            $sbf_fields
          )) {
          $field_name = $options['fieldname'];
        }

      }

    }


    if ($field_name) {
      foreach ($files as $file) {
        // Each file has the following structure
        /*(object) array(
          'uri' => '/var/www/html/d8content/metadatadisplay_entity_03.json',
          'filename' => 'metadatadisplay_entity_03.json',
          'name' => 'metadatadisplay_entity_03',
        ); */

        $args = [
          'curl',
          '-L',
          '--connect-timeout 30',
          '-H "Accept: application/vnd.api+json;"',
          '-H "Content-Type: application/octet-stream;"',
          '-H "Content-Disposition: attachment; filename=\"' . urlencode(
            $file->filename
          ) . '\""',
          '--data-binary @' . $file->uri,
        ];
        if ($options['user'] && $options['password']) {
          $args = array_merge(
            $args,
            [
              '--user',
              $options['user'] . ':' . $options['password'],
              $fileurlpost,
            ]
          );

          $process = Drush::process(implode(' ', $args));
          $process->mustRun();
          if ($process->getExitCode() == 0) {

            $response = json_decode($process->getOutput(), TRUE);
            if (isset($response['data']['attributes']['drupal_internal__fid'])) {
              $this->output()->writeln(
                dt(
                  'File @file sucessfully uploaded with file ID @fileid ',
                  [
                    '@file' => $file->filename,
                    '@fileod' => $response['data']['attributes']['drupal_internal__fid'],
                  ]
                )
              );
              $mime_type = $response['data']['attributes']['filemime'];
              // Calculate the destination json key
              $as_file_type = explode('/', $mime_type);
              $as_file_type = count(
                $as_file_type
              ) == 2 ? $as_file_type[0] : 'document';
              $as_file_type = ($as_file_type != 'application') ? $as_file_type : 'document';
              $as_specific = $as_file_type[1];
              // WE need to check if $data a.k.a JSON contains some mappings that can help
              /*
              "ap:entitymapping": {
                "entity:file": [
                  "images",
                  "documents",
                  "audios",
                  "videos",
                  "models",
                  "vtts"
                ]

              },*/
              // let's be naive and
              // @TODO how to map this better, pass a webform id and use as an API
              // Things that qualify
              // Second part of the mime in plural. Needs to be empty/shallow array
              // first part of the mime. Needs to be empty/shallow array
              // Should check if all values are integer?
              if (isset($data[$as_specific . 's']) && ($data[$as_specific . 's'] == NULL || is_array(
                    $data[$as_specific . 's']
                  ))) {
                $data[$as_specific . 's'][] = $response['data']['attributes']['drupal_internal__fid'];
              }
              else {
                $data[$as_file_type . 's'][] = $response['data']['attributes']['drupal_internal__fid'];
              }
            }
          }
          else {
            $this->output()->writeln($process->getExitCodeText());
            throw new \Exception(
              dt('We failed to upload the file. Suspending the ingest')
            );
          }
        }
      }

      // Now ingest the actual OBJECT
      $data_body = [];
      $ado_title = isset($data['label']) ? $data['label'] : 'Unnamed Digital Object';
      $data_body['data'] = [
        'id' => $options['uuid'],
        'type' => 'node--' . $bundle,
        'attributes' => [
          $field_name => json_encode($data),
          'title' =>  $ado_title,
        ],
      ];

      if ($options['moderation_state']) {
        // Check if the bundle has actually the field.
        $all_bundle_fields = \Drupal::service('entity_field.manager')
          ->getFieldDefinitions('node', $bundle);
        if (isset($all_bundle_fields['moderation_state'])) {
          $data_body['data']['attributes']['moderation_state'] = $options['moderation_state'];
        }
        else {
          $this->output()->writeln(
            dt(
              'Bundle @bundle is not moderated so skipping moderation state',
              [
                '@bundle' => $bundle,
              ]
            )
          );
        }
      }

      $curl_body = json_encode($data_body);

      $args_node = [
        'curl',
        '-L',
        '--connect-timeout 30',
        '-H "Accept: application/vnd.api+json;"',
        '-H "Content-type: application/vnd.api+json"',
        '-XPOST',
        "--data '" . $curl_body . "'",
      ];
      if ($options['user'] && $options['password']) {
        $args_node = array_merge(
          $args_node,
          [
            '--user',
            $options['user'] . ':' . $options['password'],
            $nodeurlpost,
          ]
        );

        $process_node = Drush::process(implode(' ', $args_node));
        $process_node->mustRun();

        if ($process_node->getExitCode() == 0) {
          $response = json_decode($process_node->getOutput(), TRUE);
          if (isset($response['data']['id'])) {
            $this->output()->writeln(dt("New Object '@title' with UUID @id successfully ingested. Thanks!",[
              '@title' =>  $ado_title,
              '@id' => $response['data']['id']
            ]));
          }
          else {
            throw new \Exception(
              dt('We failed to Ingest the ADO. Sorry this is the output: @errorcode. Suspending the ingest.', [
                '@errorcode' => $process_node->getOutput()
              ])
            );
          }
        }
        else {

          throw new \Exception(
            dt('We failed to Ingest the ADO with error: @errorcode. Suspending the ingest.', [
              '@errorcode' => $this->output()->writeln($process->getExitCodeText())
            ])
          );
          // Should i roll back the files?
        }
      }
    }
  }
}