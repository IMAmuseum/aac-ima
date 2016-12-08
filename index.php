<?php

// IRN = Emu's internal reference number (i.e. unique id)
// 1. Parse the old JSON submitted to AAC to get an array of ids (irns)
// 2. Parse the Emu XML, keep only those objects that appear in the AAC JSON
// 3. (Optional) Create JSON Schema files for reference
// 4. Data mapping: take old JSON and supplement it w/ ids from the new Emu XML
// 5. Ensure that all actors have IRNs
// 6. Generate a separate JSON for actors
// 7. Generate a separate JSON for objects
// 8. Crunch the new actors JSON to consolidate all the identical actors

// We'll be using to the parse the XML files:
// https://github.com/hakre/XMLReaderIterator

// Don't minify the generated XML and JSON. Most editors will choke on that.
// If you are on Windows, try using these tools to view the data:
// http://development.wellisolutions.de/huge-json-viewer/
// http://www.firstobject.com/dn_editor.htm

define( 'WS', DIRECTORY_SEPARATOR );

define( 'DIR_ROOT',     __DIR__               . WS );
define( 'DIR_DATA',     DIR_ROOT . 'data'     . WS );
define( 'DIR_VENDOR',   DIR_ROOT . 'vendor'   . WS );

require DIR_VENDOR .'autoload.php';

// We'll be setting this on a per-section basis:
// header("Content-Type: text/plain");
// header("Content-Type: application/json");

ini_set('memory_limit', '-1');
set_time_limit(0);

define( 'FILE_AAC_OLD', DIR_DATA . 'aac.json' );
define( 'FILE_AAC_NEW', DIR_DATA . 'aac-new.json' );
define( 'FILE_EMU_OLD', DIR_DATA . 'DagwoodEmuRecords.xml' );
define( 'FILE_EMU_NEW', DIR_DATA . 'emu.xml' );
define( 'FILE_IDS', DIR_DATA . 'ids.json' );

define( 'GENERATE_SCHEMA', false );
define( 'FILE_AAC_SAMPLE', DIR_DATA . 'aac.sample.json' );
define( 'FILE_AAC_SCHEMA', DIR_DATA . 'aac.schema.json' );

define( 'FILE_AAC_ACTORS_MIN', DIR_DATA . 'aac-actors-min.json' );
define( 'FILE_AAC_ACTORS', DIR_DATA . 'aac-actors.json' );
define( 'FILE_AAC_OBJECTS', DIR_DATA . 'aac-objects.json' );

define( 'VALIDATE_ACTOR_IRNS', false );

// Create a file that contains our desired EMU irns (ids)
// We will use this to parse the XML for only the records we want
if( !file_exists( FILE_IDS ) ) {

    $file = file_get_contents( FILE_AAC_OLD );
    $json = json_decode( $file, true );

    $ids = array_keys( $json['data'] );
    $out = json_encode( $ids );

    file_put_contents( FILE_IDS, $out );

    header("Content-Type: application/json");
    echo $out;
    exit;

}

// These are just helpers to ease the parsing of this particular XML
class ObjectElement extends SimpleXMLElement {

    // Shortcut for getting value of <meta> w/ emu_name attribute
    public function meta( $emu_name ) {
        $emu_name = $this->xpath("/*/meta[@emu_name='{$emu_name}']");
        $emu_name = array_shift( $emu_name );
        $emu_name = $emu_name ? (string) $emu_name : null;
        return $emu_name;
    }

}

// We will use this to parse <object> in the XML en masse
class ObjectIterator extends XMLElementIterator {
    const ELEMENT_NAME = 'object';

    public function __construct(XMLReader $reader) {
        parent::__construct($reader, self::ELEMENT_NAME);
    }

    /**
     * @return SimpleXMLElement
     */
    public function current() {
        // http://stackoverflow.com/questions/2970602/php-how-to-handle-cdata-with-simplexmlelement
        return simplexml_load_string($this->reader->readOuterXml(), "ObjectElement", LIBXML_NOCDATA);
    }
}

// http://stackoverflow.com/questions/4778865/php-simplexml-addchild-with-another-simplexmlelement
function simplexml_append(SimpleXMLElement $to, SimpleXMLElement $from) {
    $toDom = dom_import_simplexml($to);
    $fromDom = dom_import_simplexml($from);
    $toDom->appendChild($toDom->ownerDocument->importNode($fromDom, true));
}

// Parse the original EMU XML, keep only objects w/ irns that match AAC's
// We are doing this mainly to speed up future processing
if( !file_exists( FILE_EMU_NEW ) ) {

    // We are going to output the results as we go along
    header("Content-Type: text/plain");

    // Start output buffering if it's disabled
    // http://php.net/manual/en/function.ob-get-level.php
    if( !ob_get_level() ) {
        ob_start();
    }

    $reader = new XMLReader();
    $reader->open( FILE_EMU_OLD );

    // Inefficient, we'll use this to compare ids via in_array
    $ids = file_get_contents( FILE_IDS );
    $ids = json_decode( $ids );

    // Uncomment for testing
    // $ids = array_slice( $ids, 0, 5 );

    /* @var $users XMLReaderNode[] - iterate over all <object> elements */
    $objects = new ObjectIterator($reader);

    // This variable will be added to the response
    $filtered = array();

    foreach( $objects as $object ) {

        if( in_array( $object->meta('irn'), $ids ) ) {

            $filtered[] = $object;

            echo $object->meta('TitAccessionNo') . PHP_EOL;

            ob_flush();
            flush();

            // Uncomment for testing
            // if( $objects->key() > 5 ) break;

        }

    }

    // Build output to match the Dagwood export file
    $result = new SimpleXMLElement('<response></response>');
    $status = $result->addChild( 'status', 'ok' );
    $total = $result->addChild( 'totalItems', count($filtered) );

    foreach( $filtered as $object ) {
        simplexml_append( $result, $object );
    }

    // Possible but unnecessary improvement:
    // http://stackoverflow.com/questions/1191167/format-output-of-simplexml-asxml
    $out = $result->asXML();

    file_put_contents( FILE_EMU_NEW, $out );

    echo "Done.";

    ob_end_flush();
    exit;

}

// This is a one-time run. It is meant to remove the keys from the json.
// Copy some small-ish portion of the aac.pretty.json to aac.sample.json
// Mine ended up being about 380,000 lines.
// https://github.com/snowplow/schema-guru
// You might need to adjust the paths:
// ./schema-guru schema --output aac.schema.json --no-length aac.sample.json
if( GENERATE_SCHEMA && file_exists( FILE_AAC_SAMPLE ) ) {

    header("Content-Type: application/json");

    $json = file_get_contents( FILE_AAC_SAMPLE );
    $json = json_decode( $json );

    $out = array();
    foreach( $json->data as $datum ) {
        $out[] = $datum;
    }

    $out = json_encode( $out );
    file_put_contents( FILE_AAC_SAMPLE, $out );

    echo $out;
    exit;

}

// Alright, let's do some data mapping yay
// Out of necessity, we'll loop through emu.xml and match IRNs against aac.json
// There's a couple records that could not be matched. They should be discarded.
// We'll *try* to update all the fields, but we *need* to add irns and ulan_id to actors.
// Much of the boilerplate here is similar to the above section.

if( !file_exists( FILE_AAC_NEW ) ) {

    // We are going to output the results as we go along
    header("Content-Type: text/plain");

    // Start output buffering if it's disabled
    // http://php.net/manual/en/function.ob-get-level.php
    if( !ob_get_level() ) {
        ob_start();
    }

    // Use the slimmed-down version of the Emu dump
    $reader = new XMLReader();
    $reader->open( FILE_EMU_NEW );

    // We'll be comparing the XML to the JSON; XML is the definitive source.
    // Note that the root of this JSON data is an object, not an array
    // The keys in that object are numerical, corresponding to Emu's IRNs
    $json = file_get_contents( FILE_AAC_OLD );
    $json = json_decode( $json );
    $json = $json->data;

    $objects = new ObjectIterator($reader);
    $results = array();

    foreach( $objects as $emu_object ) {

        // Find the object in the JSON based on IRN
        $irn = $emu_object->meta('irn');

        $aac_object = $json->{$irn};

        if( $aac_object->actors ) {
            $emu_actors = $emu_object->xpath("/*/meta[@emu_name='CreCreatorRef_tab']");
            $aac_actors = $aac_object->actors;

            foreach( $emu_actors as $emu_actor ) {
                foreach( $aac_actors as $aac_actor ) {

                    // If any of one these match, it's a match overall
                    // TODO: We should be careful w/ the first/last name match...
                    $match = array(
                        $emu_actor->meta('NamFullName') == $aac_actor->name_full,
                        $emu_actor->meta('NamFirst') == $aac_actor->name_first,
                        $emu_actor->meta('NamLast') == $aac_actor->name_last,
                        $emu_actor->meta('NamOrganisation') == $aac_actor->name_organization,
                        $emu_actor->meta('NamTaxonomicName') == $aac_actor->name_taxonomic
                    );

                    if( in_array( 1, $match ) ) {
                        $aac_actor->id = $emu_actor->meta('irn');
                        $aac_actor->ulan_id = $emu_actor->meta('UlaUlanIdNo');
                    }
                }
            }
        }

        // We should likely only push if there is a match
        $results[] = $aac_object;

        ob_flush();
        flush();

        // Uncomment for testing
        // if( $objects->key() > 1 ) break;

    }


    // Match our output to the structure of the old JSON
    $out = array();
    foreach( $results as $result ) {
        $out[ $result->id ] = $result;
    }

    $out = array(
        'count' => count($results),
        'data' => $out
    );

    $out = json_encode( $out, JSON_PRETTY_PRINT );

    file_put_contents( FILE_AAC_NEW, $out );

    echo $out;

    ob_end_flush();
    exit;

}

// Helper to ensure that all actors have IRNs
if( VALIDATE_ACTOR_IRNS && file_exists( FILE_AAC_NEW ) ) {

    header("Content-Type: text/plain");

    $objects = file_get_contents( FILE_AAC_NEW );
    $objects = json_decode( $objects );
    $objects = $objects->data;

    $results = [];

    foreach( $objects as $object ) {
        $found = true;
        if( $object->actors ) {
            foreach( $object->actors as $actor ) {
                if( !isset($actor->id) ) {
                    $found = false;
                }
            }
        }
        if( !$found ) {
            $results[] = $object;
        }
    }

    $out = json_encode( $results, JSON_PRETTY_PRINT );
    echo $out;
    exit;

}

// Now, we need to generate a separate JSON for actors
if( !file_exists( FILE_AAC_ACTORS ) ) {

    header("Content-Type: text/plain");

    $objects = file_get_contents( FILE_AAC_NEW );
    $objects = json_decode( $objects );
    $objects = $objects->data;

    // We can just unset the one problematic object in this case
    if( isset( $objects->{'21509'} ) ) {
        unset( $objects->{'21509'} );
    }

    $results = [];

    foreach( $objects as $object ) {

        if( $object->actors ) {

            foreach( $object->actors as $actor ) {

                $found = false;

                foreach( $results as $result ) {

                    if( $actor->id == $result->id ) {
                        $found = true;
                    }

                }

                // Only add it to the list if it's new
                if( !$found ) {
                    $results[] = $actor;
                }

            }

        }

    }


    // Match our output to the structure of the old JSON
    $out = array();
    foreach( $results as $result ) {
        $out[ $result->id ] = $result;
    }

    $out = array(
        'count' => count($results),
        'data' => $out
    );

    $out = json_encode( $out, JSON_PRETTY_PRINT );
    file_put_contents( FILE_AAC_ACTORS, $out );
    echo $out;
    exit;

}


// Now, we remove actors from objects and link to their ids
if( !file_exists( FILE_AAC_OBJECTS ) ) {

    header("Content-Type: text/plain");

    $objects = file_get_contents( FILE_AAC_NEW );
    $objects = json_decode( $objects );
    $objects = $objects->data;

    // We can just unset the one problematic object in this case
    if( isset( $objects->{'21509'} ) ) {
        unset( $objects->{'21509'} );
    }

    $results = [];

    foreach( $objects as $object ) {

        if( $object->actors ) {

            $actors = array();

            // We should have array_mapped this but am lazy
            foreach( $object->actors as $actor ) {
                $actors[] = $actor->id;
            }

            $object->actors = $actors;
            $results[] = $object;
        }

    }

    // Match our output to the structure of the old JSON
    $out = array();
    foreach( $results as $result ) {
        $out[ $result->id ] = $result;
    }

    $out = array(
        'count' => count($results),
        'data' => $out
    );

    $out = json_encode( $out, JSON_PRETTY_PRINT );
    file_put_contents( FILE_AAC_OBJECTS, $out );
    echo $out;
    exit;

}

// Looks like each actor-object relationship has a unique IRN. Ugh.
// Let's crunch the new actors file and turn the id field into an array

if( file_exists( FILE_AAC_ACTORS ) && !file_exists( FILE_AAC_ACTORS_MIN ) ) {

    header("Content-Type: text/plain");

    $actors = file_get_contents( FILE_AAC_ACTORS );
    $actors = json_decode( $actors );
    $actors = $actors->data;

    $results = [];
    $counter = 0;

    // I don't think this one needs to be by ref, but just in case
    foreach( $actors as $actor ) {

        // Uncomment for testing
        // if( $counter > 6 ) break;

        $found = false;

        // This one almost certainly needs to be by reference
        foreach( $results as &$result ) {

            // If any of one these match, it's a match overall
            // Note that in this case, *both* first and last name must match
            $match = array(
                isset( $actor->name_full ) && $actor->name_full == $result->name_full,
                ( isset( $actor->name_last ) && $actor->name_last == $result->name_last ) &&
                ( isset( $actor->name_first ) && $actor->name_first == $result->name_first ),
                isset( $actor->name_taxonomic ) && $actor->name_taxonomic == $result->name_taxonomic,
                isset( $actor->name_organization ) && $actor->name_organization == $result->name_organization
            );

            // On match, add this actor's id to the existing actor
            // Then, break out of the loop
            if( in_array( 1, $match ) ) {

                // Crude way of detecting errors
                set_error_handler(function() use ($actor, $result) {
                    print_r( $actor );
                    print_r( $result );
                    exit;
                });

                array_push( $result->id, $actor->id );
                $found = true;
                break;
            }
        }

        // If no match found, add the whole actor to the result
        // Transform their id field into an array
        if( !$found ) {
            $actor->id = array( $actor->id );
            $results[] = $actor;
        }

        $counter++;

    }

    // Unlike previously, we will NOT match the old JSON structure here
    $out = array(
        'count' => count($results),
        'data' => $results
    );

    $out = json_encode( $out, JSON_PRETTY_PRINT );
    file_put_contents( FILE_AAC_ACTORS_MIN, $out );
    echo $out;
    exit;

}