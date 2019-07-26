<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 10/5/18
 * Time: 12:09 PM
 */

namespace Drupal\strawberryfield\Semantic;

use InvalidArgumentException;


/**
 * Class ActivityStream

 * This class implements a basic Activity Stream generator
 * @see https://www.w3.org/TR/activitystreams-core/
 * @see https://www.w3.org/TR/activitystreams-vocabulary
 *
 * @package Drupal\webform_strawberry\Semantic
 */
class ActivityStream
{

    /**
     * type of AS 2.0 events we allow
     */

    const ASTYPES = [
      'Announce' => 'Announce',
      'Add' => 'Add',
      'Create' => 'Create',
      'Delete' => 'Delete',
      'Move' => 'Move',
      'Read' => 'Read',
      'Undo' => 'Undo',
      'Update' => 'Update',
      'View' => 'View',
      'Activity' => 'Activity',
    ];

    /**
     * types of  AS 2. Actors we allow
     */
    const ACTORTYPES = [
      'Application' => 'Application',
      'Group' => 'Group',
      'Organization' => 'Organization',
      'Person' => 'Person',
      'Service' => 'Service'
    ];

    /**
     * types of AS 2.0 Objects we allow
     */
    const OBJECTTYPES = [
      'Activity' => 'Activity',
      'IntransitiveActivity' =>  'IntransitiveActivity',
      'Collection'=>  'Collection',
      'OrderedCollection' => 'OrderedCollection',
      'Article' => 'Article',
      'Audio' => 'Audio',
      'Document' => 'Document',
      'Event' => 'Event',
      'Image' => 'Image',
      'Note' => 'Note',
      'Page' => 'Page',
      'Place' => 'Place',
      'Profile' => 'Profile',
      'Relationship' => 'Relationship',
      'Tombstone' => 'Tombstone',
      'Video' => 'Video',
      'Object' => 'Object'
    ];

    /**
     *
     */
    const LINKTYPES = [

      'Mention'
    ];

    /**
     * The activity stream body
     *
     * @var array
     */
    protected $asBody;


    /**
     * A list of AS Objects
     *
     * @var array $asObjects
     */
    protected $asObjects;

    /**
     * A list of AS Actors
     *
     * @var array $asObjects
     */
    protected $asActors;


    /**
     * The Type of AS event this one is
     *
     * @var String;
     */
    protected $asType;

  /**
   * ArchipelagoResourceEvent constructor.
   *
   * @param string $asType
   * @param array $asBody
   */
    public function __construct(string $asType, array $asBody)
    {

        if (!array_key_exists($asType, self::ASTYPES)) {
            $function = __FUNCTION__ ;
            throw new InvalidArgumentException("AS Activity { $asType } type passed to { $function } is invalid");
        }
        $this->asType = $asType;
        // Call this last since it requires previous ones been set first.
        // @TODO refactor property initialization into ::initializeAS
        $this->setAsBody($this->initializeAS($asBody));
    }

    /**
     * @param array $body
     * @return array
     */
    public function initializeAS(array $body) {

        // @see https://www.w3.org/TR/activitystreams-vocabulary\

        $obj = [
          '@context' => 'https://www.w3.org/ns/activitystreams',
          'type' => $this->asType,
        ];
        $obj = array_merge($obj, $body);


        return $obj;

    }

    /**
     * @param string $asType
     * @param array $properties
     */
    public function addActor(string $asType, array $properties) {

        if (!array_key_exists($asType, self::ACTORTYPES)) {
            $function = __FUNCTION__ ;
            throw new InvalidArgumentException("AS Actor {$asType} type passed to {$function} is invalid");
        }

        if (!$this->checkinfo($asType, $properties)) {
            $function = __FUNCTION__ ;
            throw new InvalidArgumentException("AS Actor properties passed to { $function } are invalid");
        }

        $actor = array();
        $actor['actor'] = ['type' => $asType];
        $actor['actor'] += $properties;

        $newbody = array_merge($this->getAsBody(), $actor);
        $this->setAsBody($newbody);
    }

    /**
     * @param string $asType
     * @param array $properties
     */
    public function addObject(string $asType, array $properties) {

        if (!array_key_exists($asType, self::ACTORTYPES)) {
            $function = __FUNCTION__ ;
            throw new InvalidArgumentException("AS Object type passed to { $function } is invalid");
        }
        if (!$this->checkinfo($asType, $properties)) {
            $function = __FUNCTION__ ;
            throw new InvalidArgumentException("AS Object properties passed to { $function } are invalid");
        }

        $obj = array();
        $obj['object'] = ["type" => $asType];
        $obj['object'] = $obj['object'] + $properties;

        $newbody = array_merge($this->getAsBody(), $obj);
        $this->setAsBody($newbody);

    }

    /**
     * Wanabe AS classes properties validator.
     *
     * @param string $asType
     * @param array $properties
     * @return bool
     */
    protected function checkinfo(string $asType, array $properties) {
        // @TODO implement simplistic AS properties checker
        // @TODO check be against Ontology | const set
        // We pass the asType here since properties validation will be
        // Matching to each AS type.
        //@TODO use our schema validator in the future.
        //Stub for now
        return true;
    }

    /**
     * Full AS getter.
     *
     * @return array
     */
    public function getAsBody(): array
    {
        return $this->asBody;
    }

  /**
   * Full AS setter.
   *
   * @param array $asBody
   */
    public function setAsBody(array $asBody)
    {
        $this->asBody = $asBody;
    }

    /**
     * Full AS Type getter.
     *
     * @return string
     */
    public function getAsType(): string
    {
        return $this->asType;
    }

  /**
   * Full AS Type setter.
   *
   * @param string $asType
   */
    public function setAsType(string $asType)
    {
        $this->asType = $asType;
    }

    /**
     * Full AS getter serialized as JSON.
     *
     * @return string
     */
    public function getAsBodyasJson(): string {

        return json_encode($this->asBody,JSON_PRETTY_PRINT);
    }

}